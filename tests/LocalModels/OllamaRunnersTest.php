<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\OllamaEngine;
use JT\LocalModels\OllamaRunners;
use JT\Tests\TestCase;

/**
 * The one shared reader of `ollama ps`.
 *
 * Two callers need this output for different reasons — the store ejector needs
 * model NAMES to unload, and the watchdog needs SIZES and the GPU split to
 * decide whether a ballooning kernel GPU zone is just resident weights. They
 * read the same accessor so a parsing fix lands for both at once.
 *
 * The column shapes below are the ones that actually appear: `SIZE` is two
 * tokens ("2.1 GB"), `PROCESSOR` is either whole ("100% GPU") or split
 * ("58%/42% CPU/GPU"), and `CONTEXT` exists only on newer Ollama builds. A
 * parser that splits on whitespace and counts columns breaks on all three.
 */
class OllamaRunnersTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('AIMODELS_OLLAMA_BIN');
		parent::tearDown();
	}

	/** Stub `ollama` so `ps` prints exactly $psOutput. */
	private function stubOllama(string $psOutput): void
	{
		$stub = $this->graveyardRoot . '/ollama-ps-stub';
		file_put_contents(
			$stub,
			"#!/bin/sh\ncase \"\$1\" in\n  ps) cat <<'OUT'\n{$psOutput}\nOUT\n  ;;\nesac\nexit 0\n"
		);
		chmod($stub, 0755);
		putenv('AIMODELS_OLLAMA_BIN=' . $stub);
	}

	// --- parsing --------------------------------------------------------------

	/** The live output from the 2026-09-12 kernel-zone alert. */
	public function testParsesTwoGpuResidentModels(): void
	{
		$rows = OllamaRunners::parse(
			"NAME                  ID              SIZE      PROCESSOR    CONTEXT    UNTIL\n"
			. "qwen2.5-coder:1.5b    d7372fd82851    2.1 GB    100% GPU     32768      4 minutes from now\n"
			. "qwen2.5-coder:7b      dae161e27b0e    6.6 GB    100% GPU     32768      About a minute from now\n"
		);

		$this->assertCount(2, $rows);
		$this->assertSame('qwen2.5-coder:1.5b', $rows[0]['name']);
		$this->assertSame(2.1, $rows[0]['sizeGb']);
		$this->assertSame(100, $rows[0]['gpuPct']);
		$this->assertSame('qwen2.5-coder:7b', $rows[1]['name']);
		$this->assertSame(6.6, $rows[1]['sizeGb']);
	}

	/** Older builds have no CONTEXT column; the same row must still parse. */
	public function testParsesOutputWithoutAContextColumn(): void
	{
		$rows = OllamaRunners::parse(
			"NAME                ID              SIZE      PROCESSOR    UNTIL\n"
			. "qwen2.5-coder:1.5b  d7372fd82851    1.9 GB    100% GPU     4 minutes from now\n"
		);

		$this->assertCount(1, $rows);
		$this->assertSame(1.9, $rows[0]['sizeGb']);
		$this->assertSame(100, $rows[0]['gpuPct']);
	}

	/**
	 * A split processor reads "58%/42% CPU/GPU" — CPU first. Only the GPU share
	 * is wired into the kernel GPU zone, so taking the leading number would
	 * over-attribute the zone and suppress a real leak.
	 */
	public function testReadsTheGpuShareOfASplitProcessor(): void
	{
		$rows = OllamaRunners::parse(
			"NAME          ID              SIZE       PROCESSOR          UNTIL\n"
			. "gemma4:26b    aaa111bbb222    18.0 GB    58%/42% CPU/GPU    5 minutes from now\n"
		);

		$this->assertSame(42, $rows[0]['gpuPct']);
		$this->assertSame(18.0, $rows[0]['sizeGb']);
	}

	public function testReadsACpuOnlyModelAsZeroGpu(): void
	{
		$rows = OllamaRunners::parse(
			"NAME          ID              SIZE      PROCESSOR    UNTIL\n"
			. "llama3.1:8b   ccc333ddd444    4.9 GB    100% CPU     4 minutes from now\n"
		);

		$this->assertSame(0, $rows[0]['gpuPct']);
	}

	public function testNormalisesMbAndKbSizesToGb(): void
	{
		$rows = OllamaRunners::parse(
			"NAME        ID              SIZE       PROCESSOR    UNTIL\n"
			. "tiny:1b     eee555fff666    512 MB     100% GPU     4 minutes from now\n"
		);

		$this->assertSame(0.5, $rows[0]['sizeGb']);
	}

	public function testHeaderOnlyOutputMeansNothingIsResident(): void
	{
		$this->assertSame([], OllamaRunners::parse("NAME    ID    SIZE    PROCESSOR    UNTIL\n"));
		$this->assertSame([], OllamaRunners::parse(''));
	}

	// --- GPU total ------------------------------------------------------------

	public function testGpuResidentGbSumsOnlyTheGpuShare(): void
	{
		$this->stubOllama(
			"NAME                  ID              SIZE       PROCESSOR          CONTEXT    UNTIL\n"
			. "qwen2.5-coder:1.5b    d7372fd82851    2.0 GB     100% GPU           32768      4 minutes from now\n"
			. "gemma4:26b            aaa111bbb222    10.0 GB    50%/50% CPU/GPU    32768      5 minutes from now\n"
			. "llama3.1:8b           ccc333ddd444    4.0 GB     100% CPU           32768      5 minutes from now"
		);

		// 2.0 (all GPU) + 5.0 (half of 10) + 0 (CPU only).
		$this->assertSame(7.0, (new OllamaRunners())->gpuResidentGb());
	}

	/** No Ollama on the box is not an error — it reads as nothing resident. */
	public function testMissingOllamaBinaryReadsAsZero(): void
	{
		putenv('AIMODELS_OLLAMA_BIN=' . $this->graveyardRoot . '/definitely-not-here');

		$this->assertSame(0.0, (new OllamaRunners())->gpuResidentGb());
		$this->assertSame([], (new OllamaRunners())->resident());
	}

	/**
	 * The watchdog runs on a 4-minute timer and reads the total more than once
	 * per pass; shelling out per read would be the slowest thing it does.
	 */
	public function testResidentIsMemoisedPerInstance(): void
	{
		$stub = $this->graveyardRoot . '/ollama-counting-stub';
		$log  = $this->graveyardRoot . '/ollama-ps-count';
		file_put_contents(
			$stub,
			"#!/bin/sh\necho x >> '{$log}'\n"
			. "printf 'NAME  ID  SIZE  PROCESSOR  UNTIL\\nm:1b  abc123  1.0 GB  100%% GPU  4 minutes from now\\n'\n"
		);
		chmod($stub, 0755);
		putenv('AIMODELS_OLLAMA_BIN=' . $stub);

		$runners = new OllamaRunners();
		$runners->resident();
		$runners->resident();
		$runners->gpuResidentGb();

		$this->assertSame(1, count(file($log)));
	}

	// --- one source, two views ------------------------------------------------

	/**
	 * The ejector and the watchdog must see the SAME set of loaded models.
	 *
	 * They read it for different reasons, so the failure mode is silent drift:
	 * the ejector used to take field 0 of every line that did not start with
	 * "NAME", which turns any banner, warning or footer Ollama prints into a
	 * phantom model it then tries to `ollama stop`. The shared parser rejects a
	 * line that is not a model row, and this pins both views to it — not merely
	 * that each works alone.
	 */
	public function testTheEjectorAndTheWatchdogReadTheSameLoadedModels(): void
	{
		$stub = $this->graveyardRoot . '/ollama-noisy-stub';
		$log  = $this->graveyardRoot . '/ollama-noisy-calls';
		file_put_contents(
			$stub,
			"#!/bin/sh\necho \"\$@\" >> '{$log}'\n"
			. "case \"\$1\" in\n"
			. "  ps) printf 'Warning: server version mismatch\\n"
			. "NAME  ID  SIZE  PROCESSOR  UNTIL\\n"
			. "qwen2.5-coder:7b  dae161e27b0e  6.6 GB  100%% GPU  4 minutes from now\\n"
			. "\\n' ;;\n"
			. "esac\nexit 0\n"
		);
		chmod($stub, 0755);
		putenv('AIMODELS_OLLAMA_BIN=' . $stub);

		$home    = $this->graveyardRoot . '/home';
		$volumes = $this->graveyardRoot . '/Volumes';
		mkdir($home, 0777, true);
		mkdir($volumes, 0777, true);

		$watchdogView = (new OllamaRunners())->names();
		$ejectorView  = (new OllamaEngine($home, $volumes))->releaseHolds();

		$this->assertSame(['qwen2.5-coder:7b'], $watchdogView);
		$this->assertSame($watchdogView, $ejectorView);
		$this->assertStringNotContainsString('stop Warning:', (string) file_get_contents($log));
	}
}
