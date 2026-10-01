<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Helpers\AgentArtifacts;
use JT\Helpers\Ollama;
use JT\SessionStateClassifier;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;
use JT\Transport\TransportRegistry;

/**
 * The done/waiting/working verdict on `graveyard candidates` rows (dotfiles-0enr.1).
 *
 * A RANKING HINT: it reorders and badges rows, and nothing else reads it. It is
 * annotated once, in candidates(), so text, --porcelain, --json and the bury picker
 * all show the same verdict. No model is reached: the classifier's Ollama post is a
 * stub that answers by message text and counts its calls. Fixtures are synthetic.
 */
final class GraveyardCandidateVerdictTest extends TestCase {

	/** Real transcript files under ~/.claude/projects, removed in tearDown. */
	private array $tmpPaths = [];

	/** message text => [p_done, p_waiting, p_working], or 'down' for a refused connection. */
	private array $answers = [];

	private int $posts = 0;

	protected function setUp(): void {
		parent::setUp();
		mkdir($this->graveyardRoot . '/xdg/auto-commit-ollama', 0777, true);
		putenv('XDG_CONFIG_HOME=' . $this->graveyardRoot . '/xdg');
	}

	protected function tearDown(): void {
		foreach ($this->tmpPaths as $p) { @unlink($p); @rmdir(dirname($p)); }
		$this->tmpPaths = [];
		putenv('XDG_CONFIG_HOME');
		parent::tearDown();
	}

	private function cwd(): string { return sys_get_temp_dir() . '/gy-verdict-' . getmypid(); }

	private function sid(string $tag): string {
		return sprintf('%s-%d-%s', $tag, getmypid(), bin2hex(random_bytes(3)));
	}

	/** A live claude row whose transcript ends on $lastText. */
	private function session(string $sid, ?string $lastText, int $idle, array $extra = []): array {
		if ($lastText !== null) {
			$path = (new AgentArtifacts($this->cli))->jsonlPathFor($sid, $this->cwd());
			@mkdir(dirname($path), 0755, true);
			$this->tmpPaths[] = $path;
			file_put_contents($path, json_encode(['type' => 'assistant', 'message' => [
				'model' => 'claude-opus-5', 'content' => [['type' => 'text', 'text' => $lastText]],
			]]) . "\n");
		}
		return FakeTransport::row('cmux', $sid, array_merge(['cwd' => $this->cwd(), 'idle_seconds' => $idle], $extra));
	}

	private function classifier(): SessionStateClassifier {
		$post = function (string $url, string $payload, int $timeout): array {
			$this->posts++;
			$text = json_decode($payload, true)['messages'][1]['content'];
			$a    = $this->answers[$text] ?? [0.1, 0.1, 0.8];
			if ($a === 'down') { return [null, 'Connection refused']; }
			$top = [];
			foreach (['done', 'waiting', 'working'] as $i => $label) {
				$top[] = ['token' => $label, 'logprob' => log($a[$i])];
			}
			return [json_encode(['message' => ['content' => 'x'], 'logprobs' => [
				['token' => 'x', 'logprob' => -0.1, 'top_logprobs' => $top],
			]]), ''];
		};
		return new SessionStateClassifier(new Ollama(static fn(): bool => false, $post), '/home/nobody');
	}

	private function gy(array $rows, string $screen = ''): Graveyard {
		$t   = new FakeTransport('cmux', $rows, true, false, [], null, null, $screen);
		$reg = new TransportRegistry($this->cli, [$t]);
		$gy  = new Graveyard($this->cli, $t, null, $reg);
		$gy->setStateClassifier($this->classifier());
		$gy->setVerdictProgress(false);
		return $gy;
	}

	private function stderr(): mixed {
		$err = fopen('php://memory', 'w+');
		$this->cli->setStreams(null, $err);
		return $err;
	}

	private static function read($stream): string { rewind($stream); return (string) stream_get_contents($stream); }

	# --- annotation and order ------------------------------------------------

	public function test_confident_done_rows_sort_first_then_the_rest_by_idle(): void {
		$done1 = $this->sid('done1'); $done2 = $this->sid('done2'); $wait = $this->sid('wait'); $soft = $this->sid('soft');
		$this->answers = [
			'Shipped it.'        => [0.95, 0.03, 0.02],
			'All merged.'        => [0.90, 0.05, 0.05],
			'Which option?'      => [0.05, 0.90, 0.05],
			'Probably finished.' => [0.70, 0.20, 0.10],  // done, but under the 0.85 band
		];
		$gy = $this->gy([
			$this->session($wait,  'Which option?', 9000),
			$this->session($soft,  'Probably finished.', 8000),
			$this->session($done1, 'Shipped it.', 100),
			$this->session($done2, 'All merged.', 500),
		]);

		$rows = $gy->candidates(true);

		$this->assertSame([$done2, $done1, $wait, $soft], array_column($rows, 'session_id'));
		$this->assertSame(['done', 'done', 'waiting', 'done'], array_column($rows, 'verdict'));
		$this->assertSame(['model', 'model', 'model', 'model'], array_column($rows, 'verdict_source'));
		$this->assertEqualsWithDelta(0.90, $rows[0]['p_done'], 1e-9);
	}

	public function test_without_verdicts_the_order_and_rows_are_todays(): void {
		$a = $this->sid('a'); $b = $this->sid('b');
		$this->answers = ['Shipped it.' => [0.95, 0.03, 0.02]];
		$gy = $this->gy([$this->session($a, 'Shipped it.', 100), $this->session($b, 'Which option?', 9000)]);

		$rows = $gy->candidates();

		$this->assertSame([$b, $a], array_column($rows, 'session_id'));
		$this->assertArrayNotHasKey('verdict', $rows[0]);
		$this->assertSame(0, $this->posts);
	}

	public function test_an_active_session_is_working_without_a_model_call(): void {
		$sid = $this->sid('busy');
		$gy  = $this->gy([$this->session($sid, 'Shipped it.', 5)]); // under the idle floor

		$row = $gy->candidates(true)[0];

		$this->assertSame(['working', null, 'busy'], [$row['verdict'], $row['p_done'], $row['verdict_source']]);
		$this->assertSame(0, $this->posts);
	}

	public function test_a_hotline_completion_is_done_without_a_model_call(): void {
		$sid = $this->sid('hot');
		$gy  = $this->gy([$this->session($sid, "Report.\n\nSTATUS: WORK_COMPLETE call_id=abc", 600)]);

		$row = $gy->candidates(true)[0];

		$this->assertSame(['done', 1.0, 'status-line'], [$row['verdict'], $row['p_done'], $row['verdict_source']]);
		$this->assertSame(0, $this->posts);
	}

	public function test_codex_and_transcriptless_rows_have_no_verdict(): void {
		$codex = $this->sid('codex'); $bare = $this->sid('bare');
		$gy = $this->gy([
			$this->session($codex, null, 600, ['agent' => 'codex']),
			$this->session($bare, null, 500),
		]);

		foreach ($gy->candidates(true) as $row) {
			$this->assertSame([null, null, 'unavailable'], [$row['verdict'], $row['p_done'], $row['verdict_source']]);
		}
		$this->assertSame(0, $this->posts);
	}

	# --- the cache -----------------------------------------------------------

	public function test_an_unchanged_message_reuses_its_cached_verdict(): void {
		$sid = $this->sid('cache');
		$this->answers = ['Shipped it.' => [0.95, 0.03, 0.02]];
		$rows = [$this->session($sid, 'Shipped it.', 600)];

		$this->gy($rows)->candidates(true);
		$again = $this->gy($rows)->candidates(true)[0];

		$this->assertSame(1, $this->posts);
		$this->assertSame(['done', 'cache'], [$again['verdict'], $again['verdict_source']]);
		$this->assertEqualsWithDelta(0.95, $again['p_done'], 1e-9);
	}

	public function test_a_changed_message_is_classified_again(): void {
		$sid = $this->sid('changed');
		$this->gy([$this->session($sid, 'Running tests now.', 600)])->candidates(true);
		$this->answers = ['Shipped it.' => [0.95, 0.03, 0.02]];

		$row = $this->gy([$this->session($sid, 'Shipped it.', 600)])->candidates(true)[0];

		$this->assertSame(2, $this->posts);
		$this->assertSame(['done', 'model'], [$row['verdict'], $row['verdict_source']]);
	}

	public function test_the_cache_holds_no_message_text(): void {
		$sid = $this->sid('private');
		$this->gy([$this->session($sid, 'A secret-ish message body.', 600)])->candidates(true);

		$cache = (string) file_get_contents($this->graveyardRoot . '/verdicts.json');
		$this->assertStringContainsString($sid, $cache);
		$this->assertStringNotContainsString('secret-ish', $cache);
	}

	# --- the model being down ------------------------------------------------

	public function test_an_unreachable_model_leaves_rows_unranked_and_warns_once(): void {
		$a = $this->sid('a'); $b = $this->sid('b');
		$this->answers = ['First.' => 'down', 'Second.' => 'down'];
		$err = $this->stderr();
		$gy  = $this->gy([$this->session($a, 'First.', 900), $this->session($b, 'Second.', 100)]);

		$rows = $gy->candidates(true);

		$this->assertSame([$a, $b], array_column($rows, 'session_id'));
		$this->assertSame(['unavailable', 'unavailable'], array_column($rows, 'verdict_source'));
		$this->assertSame(1, $this->posts, 'one refused connection is enough to stop asking');
		$this->assertSame(1, substr_count(self::read($err), 'Connection refused'));
		$this->assertFileDoesNotExist($this->graveyardRoot . '/verdicts.json');
	}

	# --- every view reads the same annotated rows ----------------------------

	public function test_text_porcelain_json_and_the_picker_show_the_same_verdicts(): void {
		$done = $this->sid('done'); $wait = $this->sid('wait');
		$this->answers = ['Shipped it.' => [0.93, 0.04, 0.03], 'Which option?' => [0.05, 0.90, 0.05]];
		$rows = [$this->session($wait, 'Which option?', 9000), $this->session($done, 'Shipped it.', 100)];
		$gy   = $this->gy($rows);
		$expected = $gy->candidates(true);

		ob_start(); $gy->printCandidates(false, true); $json = json_decode((string) ob_get_clean(), true);
		$this->assertSame(array_column($expected, 'session_id'), array_column($json, 'session_id'));
		$this->assertSame(['done', 'waiting'], array_column($json, 'verdict'));
		$this->assertSame(['cache', 'cache'], array_column($json, 'verdict_source'));
		$this->assertEqualsWithDelta(0.93, $json[0]['p_done'], 1e-9);

		ob_start(); $gy->printCandidates(true); $porcelain = array_filter(explode("\n", (string) ob_get_clean()));
		$cols = array_map(fn($l) => array_slice(explode("\t", $l), 9), array_values($porcelain));
		$this->assertSame([['done', '0.93', 'cache'], ['waiting', '0.05', 'cache']], $cols);

		$out = fopen('php://memory', 'w+');
		$this->cli->setStreams($out, null);
		$gy->printCandidates(false);
		$text = self::read($out);
		$this->assertStringContainsString('done 93%', $text);
		$this->assertStringContainsString('waiting', $text);

		$picker = new class ($this->cli, new FakeTransport('cmux', $rows), null, new TransportRegistry($this->cli, [new FakeTransport('cmux', $rows)])) extends Graveyard {
			public array $offered = [];
			public function pickWithFzf(array $cands): array { $this->offered = $cands; return []; }
			public function pickWithRepl(array $cands): array { $this->offered = $cands; return []; }
		};
		$picker->setStateClassifier($this->classifier());
		$picker->setVerdictProgress(false);
		$picker->pickAndBury(true);
		$this->assertSame(array_column($expected, 'verdict'), array_column($picker->offered, 'verdict'));
		$this->assertSame(array_column($expected, 'session_id'), array_column($picker->offered, 'session_id'));
	}

	public function test_no_verdict_leaves_every_view_as_it_was(): void {
		$sid = $this->sid('plain');
		$gy  = $this->gy([$this->session($sid, 'Shipped it.', 600)]);

		ob_start(); $gy->printCandidates(false, true, false); $json = json_decode((string) ob_get_clean(), true);
		$this->assertSame([null, null, null], [$json[0]['verdict'], $json[0]['p_done'], $json[0]['verdict_source']]);

		$out = fopen('php://memory', 'w+');
		$this->cli->setStreams($out, null);
		$gy->printCandidates(false, false, false);
		$text = self::read($out);
		$this->assertStringContainsString(substr($sid, 0, 8), $text);
		foreach (['?', 'done', 'working', 'waiting'] as $badge) { $this->assertStringNotContainsString($badge, $text); }
		$this->assertSame(0, $this->posts);
	}

	# --- the badge -----------------------------------------------------------

	public function test_the_badge_reads_verdict_and_confidence(): void {
		$this->assertSame('done 93%', $this->gy->verdictBadge(['verdict' => 'done', 'p_done' => 0.934, 'verdict_source' => 'model']));
		$this->assertSame('waiting', $this->gy->verdictBadge(['verdict' => 'waiting', 'p_done' => 0.05, 'verdict_source' => 'cache']));
		$this->assertSame('working', $this->gy->verdictBadge(['verdict' => 'working', 'p_done' => null, 'verdict_source' => 'busy']));
		$this->assertSame('?', $this->gy->verdictBadge(['verdict' => null, 'p_done' => null, 'verdict_source' => 'unavailable']));
		$this->assertSame('', $this->gy->verdictBadge([]), 'no verdict was asked for');
	}
}
