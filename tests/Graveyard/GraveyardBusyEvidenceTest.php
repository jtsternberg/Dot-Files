<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;

/**
 * A busy check that cannot read the screen must refuse, not proceed (dotfiles-mfe).
 *
 * isBusy() decides "is this session mid-turn?" from the idle clock plus an
 * active-turn regex over a screen read. On the ordinary Claude bury an unreadable
 * screen never reaches it: GATE 1 reads the SAME string first and refuses when it
 * finds no statusline. Two paths escape that argument —
 *
 *   - a _probed row is GATE-1-EXEMPT (passesPreExportGate()), and
 *   - the codex gate 1 is an OS-exact host check that reads no screen at all
 *
 * — and both transports used to answer a FAILED read with '', indistinguishable
 * from a genuinely blank surface. So the whole decision rested on a regex finding
 * no marker in a string that was empty because the read had failed: fail OPEN,
 * ahead of typing into a live REPL and killing a process tree.
 *
 * The seam now distinguishes ('' = read a blank surface, null = the read failed)
 * and this file pins the caller's half of it: pollers coalesce, the busy check
 * refuses. No Graveyard subclass doubles here — the bury runs for real against a
 * FakeTransport, which IMPLEMENTS the interface and so cannot go quietly dead.
 */
final class GraveyardBusyEvidenceTest extends TestCase
{
	private const SID = 'ffff1111-2222-3333-4444-666666666666';

	private ?string $oldHome = null;
	private array $cleanup = [];

	protected function tearDown(): void
	{
		if ($this->oldHome !== null) { putenv('HOME=' . $this->oldHome); }
		putenv('GRAVEYARD_EXPORT_BIN');
		foreach ($this->cleanup as $p) { @unlink($p); }
		parent::tearDown();
	}

	# =====================================================================
	# The decision itself.
	# =====================================================================

	/**
	 * '' and null are different answers, and only null means "no evidence".
	 *
	 * The '' assertions are the OLD fail-open input: a blank screen still reads as
	 * not-busy, which is correct now that '' can only come from a read that worked.
	 */
	public function testAFailedScreenReadReadsAsBusyAndABlankScreenDoesNot(): void
	{
		$this->assertFalse($this->gy->isBusy(30, 15, ''), 'a screen truly read and found blank is evidence of quiet');
		$this->assertFalse($this->gy->isBusy(PHP_INT_MAX, 15, ''), 'including when idle is unmeasurable');

		$this->assertTrue($this->gy->isBusy(30, 15, null), 'a failed read is no evidence, so it must read as busy');
		$this->assertTrue($this->gy->isBusy(PHP_INT_MAX, 15, null), 'and an unmeasurable idle cannot rescue it');

		// The idle floor and the regex still decide when there IS a screen.
		$this->assertTrue($this->gy->isBusy(5, 15, ''));
		$this->assertTrue($this->gy->isBusy(30, 15, '… (esc to interrupt)'));
	}

	/**
	 * The two refusals must not read alike. --force is the right answer to a session
	 * that is genuinely working; a transport that answered nothing wants looking at.
	 */
	public function testTheReasonsAreDistinctAndSoAreTheirMessages(): void
	{
		$this->assertNull($this->gy->busyReason(30, 15, ''));
		$this->assertSame('active', $this->gy->busyReason(5, 15, ''));
		$this->assertSame('active', $this->gy->busyReason(30, 15, '✳ Cogitating… (1.2k tokens)'));
		$this->assertSame('unreadable', $this->gy->busyReason(30, 15, null));

		[$skip, $override] = $this->gy->busyMessages('active', 'notes');
		$this->assertSame('  Skipping notes — session looks busy (use --force to override).', $skip);
		$this->assertStringContainsString('--force given', $override);

		[$skip2, $override2] = $this->gy->busyMessages('unreadable', 'notes');
		$this->assertStringContainsString('could not read its screen', $skip2);
		$this->assertStringContainsString('--force to override', $skip2);
		$this->assertStringContainsString('could not read', strtolower($override2));
		$this->assertNotSame($skip, $skip2, 'the operator has to be able to tell the two refusals apart');
	}

	# =====================================================================
	# The fail-open, end to end, on the path that escapes GATE 1.
	# =====================================================================

	/**
	 * A _probed row whose screen cannot be read is REFUSED, and refused before
	 * anything is typed at it.
	 *
	 * Its companion below is the contrast: the same row with a screen that was
	 * genuinely read and found blank sails past the busy check and is stopped only by
	 * GATE 2. That is exactly what a FAILED read used to do, because it arrived here
	 * as the same '' — and GATE 2 opens for a session whose JSONL yields no needles,
	 * so on such a row nothing at all stood between an unreadable screen and teardown.
	 */
	public function testAProbedRowWithAnUnreadableScreenIsRefusedBeforeAnythingIsTyped(): void
	{
		[$gy, $fake] = $this->buryFixture(screen: null);

		ob_start();
		$refused = $gy->buryOne($this->probedRow(), false, true);
		$out = (string) ob_get_clean();

		$this->assertFalse($refused);
		$this->assertSame(['readScreen'], $this->calledMethods($fake), 'nothing may be sent to a surface we could not read');
		$this->assertFileDoesNotExist($gy->transcriptMdPath(self::SID), 'and no export may run');
		$this->assertFileDoesNotExist($gy->metaPath(self::SID));
		$this->assertStringContainsString('could not read its screen', $out);
	}

	/** @see testAProbedRowWithAnUnreadableScreenIsRefusedBeforeAnythingIsTyped */
	public function testTheSameProbedRowGetsPastTheBusyCheckWhenTheBlankScreenWasReallyRead(): void
	{
		[$gy] = $this->buryFixture(screen: '');

		ob_start();
		$refused = $gy->buryOne($this->probedRow(), false, true);
		$out = (string) ob_get_clean();

		$this->assertFalse($refused, 'GATE 2 is what stops it, not the busy check');
		$this->assertStringContainsString('gate 2', $out, 'it reached the post-export gate…');
		$this->assertStringNotContainsString('looks busy', $out, '…so the busy check let it through');
		$this->assertFileExists($gy->transcriptMdPath(self::SID), 'having already archived a transcript');
	}

	/**
	 * --force still overrides, on the unreadable branch too: it is an operator saying
	 * "I have looked", and taking that away would strand any session whose transport
	 * has stopped answering.
	 */
	public function testForceStillOverridesAnUnreadableScreen(): void
	{
		[$gy] = $this->buryFixture(screen: null);

		ob_start();
		$refused = $gy->buryOne($this->probedRow(), true, true);
		$out = (string) ob_get_clean();

		$this->assertFalse($refused, 'GATE 2 still refuses; --force is not a bypass for it');
		$this->assertStringContainsString('--force given', $out);
		$this->assertStringContainsString('gate 2', $out);
	}

	# =====================================================================
	# The codex path, whose GATE 1 reads no screen at all.
	# =====================================================================

	public function testTheCodexBusyCheckAlsoRefusesAnUnreadableScreen(): void
	{
		$sid  = 'cccc1111-2222-3333-4444-777777777777';
		$fake = new FakeTransport(name: 'cmux', screen: null);
		$gy   = new class ($this->cli, $fake) extends Graveyard {
			// GATE 1 (codex) is an OS-exact host check — it shells out to ps. Held open
			// here so the busy check below it is the thing under test; it is the very
			// asymmetry that makes an unreadable screen dangerous on this path.
			public function codexSurfaceHostsSession(string $surfaceRef, string $sessionId): bool { return true; }
		};

		$row = [
			'session_id' => $sid, 'agent' => 'codex', 'cwd' => '/tmp', 'model' => null, 'skip_perms' => false,
			'pid' => 0, 'surface_ref' => 's1', 'workspace_ref' => 'w1', 'workspace_title' => 'ws',
			'tab_title' => 'codex tab', 'idle_seconds' => 9000, 'targetable' => true, 'reason' => '',
			'rollout_path' => '/no/such/rollout.jsonl',
		];

		ob_start();
		$refused = $gy->buryOne($row, false, true);
		$out = (string) ob_get_clean();

		$this->assertFalse($refused);
		$this->assertStringContainsString('could not read its screen', $out);
		$this->assertSame(['readScreen'], $this->calledMethods($fake));
	}

	# =====================================================================
	# The other kind of caller: a poller must lose an iteration, not the job.
	# =====================================================================

	/**
	 * The /status probe POLLS the screen while waiting for the identity modal, so it
	 * coalesces a failed read to '' and keeps waiting — the opposite of what the busy
	 * check wants from the same seam. Without that coalesce a null reaches
	 * parseStatusProbe(string) and the probe dies on a transient read instead of
	 * retrying.
	 */
	public function testTheStatusProbePollerSurvivesAFailedRead(): void
	{
		$fake = new FakeTransport(name: 'cmux', screen: null);
		$gy   = new Graveyard($this->cli, $fake);

		$this->assertNull($gy->probeSurfaceIdentity('s1', 'w1', 1), 'no identity found, but no crash either');
		$this->assertSame(['sendText', 'sendKey', 'readScreen'], $this->calledMethods($fake), 'and the modal is still dismissed');
	}

	/**
	 * The workspace-bury classifier reads every surface's screen to tell a Claude tab
	 * from a shell. That is a poller-shaped read too: an unreadable surface is simply
	 * not a Claude surface here, which is the same answer a blank one gives — and both
	 * are safe, because a misclassified Claude tab is refused by bury's own gates
	 * rather than torn down.
	 */
	public function testTheWorkspaceClassifierSurvivesAFailedRead(): void
	{
		$fake = new FakeTransport(
			name: 'cmux',
			surfaces: [['surface_ref' => 's1', 'surface_id' => 's1', 'type' => 'terminal', 'title' => 'a tab',
				'tty' => '', 'workspace_ref' => 'w1', 'workspace_title' => 'ws', 'script' => null]],
			screen: null
		);
		$gy = new Graveyard($this->cli, $fake);

		$cls = $gy->buildBuryClassification($fake->surfaces('w1'), 'w1', 'ws');

		$this->assertSame([], $cls['members'], 'no session was bound, so nothing is a member');
		$this->assertNotSame([], $cls, 'and the classifier answered rather than dying on a null');
	}

	/**
	 * peek/preview PRINTS a screen, so it is the third kind of caller: it has to say it
	 * could not read one rather than dying on a null.
	 */
	public function testAPreviewSaysSoWhenTheScreenCannotBeRead(): void
	{
		$fake = new FakeTransport('cmux', [FakeTransport::row('cmux', 'dddddddd-preview')], screen: null);
		$gy   = new Graveyard($this->cli, $fake);

		ob_start();
		$gy->printPreview('dddddddd-preview');
		$out = (string) ob_get_clean();

		$this->assertStringContainsString('could not read the screen', $out);
	}

	# =====================================================================
	# A probed row's idle is inherited, not asserted to be infinite.
	# =====================================================================

	/**
	 * PHP_INT_MAX is this codebase's "idle is unmeasurable" sentinel, and the views
	 * that meet it SKIP the row. A busy check does not skip, it compares — and
	 * PHP_INT_MAX compares as infinitely idle, the strongest possible not-busy vote,
	 * on the one row shape GATE 1 does not backstop. The probed session's own live row
	 * carries a measured idle; use it.
	 */
	public function testAProbedRowInheritsTheLiveRowsMeasuredIdle(): void
	{
		$live = [['session_id' => self::SID, 'cwd' => '/Users/JT', 'pid' => 4242, 'idle_seconds' => 7, 'targetable' => false]];
		$surf = ['surface_ref' => 's1', 'surface_id' => 's1', 'title' => 't', 'workspace_title' => 'w'];

		$row = $this->gy->synthesizeProbedRow('s1', 'w1', ['session_id' => self::SID, 'cwd' => '/x'], $live, $surf);
		$this->assertSame(7, $row['idle_seconds']);
		$this->assertTrue($this->gy->isBusy((int) $row['idle_seconds'], Graveyard::IDLE_FLOOR_DEFAULT, ''),
			'an inherited idle inside the floor now actually fires it');

		// Unmeasurable at the source stays unmeasurable — the sentinel keeps its meaning.
		$noIdle = [['session_id' => self::SID, 'cwd' => '/Users/JT', 'pid' => 4242, 'targetable' => false]];
		$this->assertSame(PHP_INT_MAX, $this->gy->synthesizeProbedRow('s1', 'w1', ['session_id' => self::SID, 'cwd' => '/x'], $noIdle, $surf)['idle_seconds']);

		// An explicit caller argument still wins.
		$this->assertSame(99, $this->gy->synthesizeProbedRow('s1', 'w1', ['session_id' => self::SID, 'cwd' => '/x'], $live, $surf, 99)['idle_seconds']);
	}

	/**
	 * buryIdle deliberately SKIPS unmeasurable rows rather than treating them as
	 * ancient, and that skip reads the same sentinel this change touches.
	 */
	public function testBuryIdleStillSkipsRowsWhoseIdleIsUnmeasurable(): void
	{
		$fake = new FakeTransport('cmux', [
			FakeTransport::row('cmux', 'aaaaaaaa-measured', ['idle_seconds' => 99999]),
			FakeTransport::row('cmux', 'bbbbbbbb-unknowable', ['idle_seconds' => PHP_INT_MAX]),
		]);
		$gy = new Graveyard($this->cli, $fake);

		// autoConfirm, so no prompt reads stdin (buryIdle's confirm has no tty guard and
		// would hang one). Nothing is buried anyway: neither row is _probed, so GATE 1
		// refuses each on the fake's blank screen.
		ob_start();
		$gy->buryIdle(600, true);
		$out = (string) ob_get_clean();

		$this->assertStringContainsString('1 session(s) with unmeasurable idle time', $out);
		$this->assertStringContainsString('aaaaaaaa  idle=99999s', $out, 'the measured row is listed');
		$this->assertStringNotContainsString('bbbbbbbb', $out, 'the unmeasurable row never reaches the list');
	}

	# =====================================================================
	# Fixture.
	# =====================================================================

	/** @return array{0:Graveyard,1:FakeTransport} */
	private function buryFixture(?string $screen): array
	{
		// A JSONL with real turns, so GATE 2 has needles to fail against. Without one
		// transcriptBelongsToSession() opens (no needles = nothing to contradict), and a
		// bury that got this far would proceed to teardown.
		$this->oldHome = getenv('HOME') ?: '';
		$home = $this->graveyardRoot . '/home';
		@mkdir($home, 0755, true);
		putenv('HOME=' . $home);

		$jsonl = $this->cmux->jsonlPathFor(self::SID, '/Users/JT/probed');
		@mkdir(dirname($jsonl), 0755, true);
		file_put_contents($jsonl, json_encode([
			'type' => 'user', 'uuid' => 'u1', 'sessionId' => self::SID, 'cwd' => '/Users/JT/probed',
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A NEEDLE THE EXPORT WILL NOT CONTAIN']]],
		]) . "\n");

		// Export via the injected binary rather than by typing /export into a REPL, so
		// the contrast case cannot sit in a 30s poll — and so a bury that DOES get past
		// the busy check leaves a transcript on disk as its receipt.
		$bin = $this->graveyardRoot . '/export-stub';
		file_put_contents($bin, "#!/bin/sh\necho '# unrelated export body'\n");
		chmod($bin, 0755);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin);

		$fake = new FakeTransport(name: 'cmux', screen: $screen);

		return [new Graveyard($this->cli, $fake), $fake];
	}

	/** A GATE-1-exempt row: the /status probe already proved surface↔session identity. */
	private function probedRow(): array
	{
		return [
			'session_id' => self::SID, 'agent' => 'claude', 'cwd' => '/Users/JT/probed', 'model' => 'opus',
			'skip_perms' => false, 'pid' => 0, 'surface_ref' => 's1', 'workspace_ref' => 'w1',
			'workspace_title' => 'ws', 'tab_title' => 'probed tab', 'idle_seconds' => PHP_INT_MAX,
			'targetable' => true, 'reason' => 'bound via /status probe', '_probed' => true,
		];
	}

	private function calledMethods(FakeTransport $t): array
	{
		return array_values(array_unique(array_column($t->calls, 0)));
	}
}
