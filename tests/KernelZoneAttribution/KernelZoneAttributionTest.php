<?php
namespace JT\Tests\KernelZoneAttribution;

use JT\KernelZoneAttribution;
use JT\Tests\TestCase;

/**
 * Whether a big kernel zone is a leak or just work someone asked for.
 *
 * The watchdog alerts on any single kernel zone at or above 8G because that is
 * the only signal that sees a wired-memory leak — the 2026-07-13 panic zone
 * (`data.kalloc.1024`, EndpointSecurity event buffers) hit 19G while `%free`
 * still read ~90%. But a local LLM legitimately wires its weights into the GPU
 * zone, so on 2026-09-12 two resident Ollama models (2.1GB + 6.6GB, both 100%
 * GPU) put `com.apple.iokit.IOGPUFamily.API` at 8.8G and fired a panic-flavoured
 * alert with nothing wrong.
 *
 * So attribution is deliberately narrow in two ways, and both are pinned below:
 * only GPU zones can be explained by resident models at all, and only up to the
 * resident total plus a small headroom. Anything above that is unexplained and
 * still alerts — a leak while a model happens to be loaded must not hide behind
 * it.
 */
class KernelZoneAttributionTest extends TestCase
{
	// --- the false positive this exists to stop -------------------------------

	/** The exact 2026-09-12 reading: 8.8G zone, 8.7G of models resident. */
	public function testResidentModelsExplainTheGpuZoneTheySizeTo(): void
	{
		$this->assertTrue(
			KernelZoneAttribution::explains('com.apple.iokit.IOGPUFamily.API', 8.8, 8.7)
		);
	}

	public function testAGpuZoneWellUnderTheResidentTotalIsExplained(): void
	{
		$this->assertTrue(
			KernelZoneAttribution::explains('com.apple.iokit.IOGPUFamily.API', 8.2, 12.4)
		);
	}

	// --- what must still alert ------------------------------------------------

	/**
	 * The regression that matters most: the panic zone is not a GPU zone, so a
	 * loaded model can never excuse it.
	 */
	public function testTheKallocPanicZoneIsNeverExplainedByResidentModels(): void
	{
		$this->assertFalse(
			KernelZoneAttribution::explains('data.kalloc.1024', 19.0, 20.0)
		);
	}

	public function testANonGpuZoneIsNeverExplained(): void
	{
		$this->assertFalse(KernelZoneAttribution::explains('VM_KERN_MEMORY_PTE', 9.5, 12.0));
	}

	/** A GPU zone GBs past the resident total is a real GPU leak. */
	public function testAGpuZoneBeyondTheResidentTotalPlusHeadroomStillAlerts(): void
	{
		$this->assertFalse(
			KernelZoneAttribution::explains('com.apple.iokit.IOGPUFamily.API', 14.0, 8.7)
		);
	}

	public function testNothingResidentExplainsNothing(): void
	{
		$this->assertFalse(
			KernelZoneAttribution::explains('com.apple.iokit.IOGPUFamily.API', 9.0, 0.0)
		);
	}

	/**
	 * Driver-side allocation and IOSurface backing store come and go around the
	 * weights themselves, so the headroom is real but small — a couple of GB,
	 * not a blank cheque.
	 */
	public function testHeadroomAbsorbsDriverOverheadButNotAGigabytesLeak(): void
	{
		$zone = 'com.apple.iokit.IOGPUFamily.API';

		$this->assertTrue(KernelZoneAttribution::explains($zone, 9.5, 8.7), 'within headroom');
		$this->assertFalse(KernelZoneAttribution::explains($zone, 11.5, 8.7), 'past headroom');
	}

	// --- zone classification --------------------------------------------------

	public function testRecognisesTheGpuZones(): void
	{
		foreach (
			[
				'com.apple.iokit.IOGPUFamily.API',
				'com.apple.iokit.IOGPUFamily',
				'com.apple.iokit.IOSurface',
				'com.apple.AGXG14X',
			] as $zone
		) {
			$this->assertTrue(KernelZoneAttribution::isGpuZone($zone), $zone);
		}
	}

	public function testDoesNotMistakeNonGpuZonesForGpuOnes(): void
	{
		foreach (['data.kalloc.1024', 'VM_KERN_MEMORY_PTE', 'VM_KERN_COUNT_MAP_ZONE'] as $zone) {
			$this->assertFalse(KernelZoneAttribution::isGpuZone($zone), $zone);
		}
	}

	// --- reporting ------------------------------------------------------------

	/**
	 * When the map-% backstop fires with models resident, the alert still goes
	 * out — near-exhaustion is near-exhaustion — but the advice has to change
	 * from "hunt an EndpointSecurity flood" to "unload a model".
	 */
	public function testDescribesWhatTheModelsAccountFor(): void
	{
		$this->assertSame(
			'8.7G of it is Ollama models resident on the GPU (`ollama stop <model>` reclaims it)',
			KernelZoneAttribution::describe(8.7)
		);
		$this->assertSame('', KernelZoneAttribution::describe(0.0));
	}
}
