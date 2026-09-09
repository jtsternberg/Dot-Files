<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * The post-bury note offer: after burying ONE note-target, `graveyard bury` offers to
 * author that target's NOTES.md right then (JT, 2026-09-09) — reusing the `note` verb's
 * ensure* + editor launch rather than a second command later.
 *
 * Two halves are pinned here: the bury verbs REPORT what they buried (so the bin knows
 * whether a single note target exists at all), and noteOffer() decides whether to ask
 * and with which noun. The editor launch itself is bin/graveyard's untestable seam, so
 * nothing here may open one — the tests assert the store stays note-free where the
 * offer must be skipped.
 */
final class GraveyardBuryNoteOfferTest extends TestCase
{
	/** A Graveyard whose single-session bury succeeds without touching a real session. */
	private function stubBury(): Graveyard
	{
		return new class($this->cli, $this->transport) extends Graveyard {
			public array $buried = [];
			public function selfSessionId(): ?string { return null; }
			public function selfSurfaceId(): ?string { return null; }
			public function resolveLiveBySessionId(string $sid): ?array
			{
				return ['session_id' => $sid, 'idle_seconds' => 10, 'workspace_title' => 'WS', 'tab_title' => 'Tab'];
			}
			public function buryOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool
			{
				$this->buried[] = $sess['session_id'];
				return true;
			}
		};
	}

	// --- The bury verbs report what they buried ----------------------------

	public function testBuryIdsReportsASingleSessionNoteTarget(): void
	{
		$gy = $this->stubBury();
		$this->assertSame(
			['note_target' => ['kind' => 'session', 'id' => 'solo1234-full']],
			$gy->buryIds(['solo1234-full'], true)
		);
	}

	public function testBuryIdsReportsNoNoteTargetForAMultiSessionBury(): void
	{
		// Several sessions buried at once have no single note target, and one prompt per
		// session would be noise — so the bulk case reports nothing to offer.
		$gy = $this->stubBury();
		$this->assertSame(
			['note_target' => null],
			$gy->buryIds(['aaa11111-full', 'bbb22222-full'], true)
		);
	}

	public function testBuryIdsReportsNoNoteTargetWhenNothingWasBuried(): void
	{
		$this->assertSame(['note_target' => null], $this->gy->buryIds([], true));
	}

	public function testBuryClassifiedAsGroupReportsTheGroupNoteTarget(): void
	{
		$gy = new class($this->cli, $this->transport) extends Graveyard {
			public array $stampedGroups = [];
			public function selfSessionId(): ?string { return null; }
			public function buryOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool
			{
				$this->stampedGroups[] = (string) ($group['group_id'] ?? '');
				return true;
			}
		};

		// `_probed` makes memberRowToBury use the member row verbatim, so the group bury
		// needs no live cmux behind it.
		$m = ['session_id' => 'gm111111-full', 'group_pos' => 0, 'tab_title' => 't', 'cwd' => '/x', '_probed' => true];
		$cls = ['members' => [$m], 'untargetable' => [], 'layout' => [['kind' => 'claude', 'ref' => 'r1']]];

		$out = $gy->buryClassifiedAsGroup($cls, 'workspace:1', 'A Plot', 'window:1', false, true, [
			'label' => 'pane group', 'captureLayoutTree' => false, 'close' => 'surfaces', 'rerun' => 'x',
		]);

		$this->assertSame('group', $out['note_target']['kind']);
		// The reported id is the group the members were actually stamped into — the same
		// id `note -ws <group>` resolves, not a fresh one.
		$this->assertSame($gy->stampedGroups, [(string) $out['note_target']['id']]);
	}

	public function testBuryByRefPassesThroughEachPasteFormsNoteTarget(): void
	{
		$mk = function (array $cls) {
			return new class($this->cli, $this->transport, $cls) extends Graveyard {
				public array $clsToReturn;
				public function __construct($cli, $t, array $cls) { parent::__construct($cli, $t); $this->clsToReturn = $cls; }
				public function findPaneSurfaces(array $surfaces, string $id): ?array
				{
					return ['surfaces' => [], 'ws_ref' => 'workspace:29', 'ws_title' => 'boss', 'window_ref' => 'window:1'];
				}
				public function buildBuryClassification(array $s, string $wsRef, string $wsTitle): array { return $this->clsToReturn; }
				public function selfSessionId(): ?string { return null; }
				public function buryIds(array $ids, bool $auto, bool $force = false): array
				{
					return ['note_target' => ['kind' => 'session', 'id' => $ids[0]]];
				}
				public function buryWorkspace(string $nameOrRef, bool $force, bool $auto): array
				{
					return ['note_target' => ['kind' => 'group', 'id' => 'ws-group']];
				}
				public function buryClassifiedAsGroup(array $cls, string $wsRef, string $title, string $windowRef, bool $force, bool $autoConfirm, array $opts): array
				{
					return ['note_target' => ['kind' => 'group', 'id' => 'pane-group']];
				}
			};
		};

		// A workspace paste is a plot bury → the plot note.
		$ws = $mk(['members' => [], 'untargetable' => []]);
		$this->assertSame('group', $ws->buryByRef('workspace_ref=workspace:29', false, true)['note_target']['kind']);

		// A one-session pane paste is a plain single bury → the session note.
		$one = $mk(['members' => [['session_id' => 'solo']], 'untargetable' => [], 'layout' => []]);
		$this->assertSame(
			['kind' => 'session', 'id' => 'solo'],
			$one->buryByRef('pane_id=45AC', false, true)['note_target']
		);

		// A multi-session pane paste becomes a group → the plot note.
		$many = $mk(['members' => [['session_id' => 'a'], ['session_id' => 'b']], 'untargetable' => [], 'layout' => []]);
		$this->assertSame(
			['kind' => 'group', 'id' => 'pane-group'],
			$many->buryByRef('pane_id=45AC', false, true)['note_target']
		);
	}

	public function testBuryIntoGroupDeclaresNoNoteTarget(): void
	{
		// Finishing a half-buried workspace must NOT re-offer the plot note: the group was
		// created by an earlier `bury -ws` that already offered it. The void return type IS
		// that contract — there is no outcome for the bin to prompt from.
		$rt = (new \ReflectionMethod(Graveyard::class, 'buryIntoGroup'))->getReturnType();
		$this->assertSame('void', (string) $rt);
	}

	// --- noteOffer: whether to ask, and with which noun --------------------

	public function testNoteOfferNamesTheSessionAndThePlot(): void
	{
		$this->cli->forceInteractive = true;

		$this->assertSame(
			['kind' => 'session', 'ref' => 'solo1234-full', 'noun' => 'session'],
			$this->gy->noteOffer(['note_target' => ['kind' => 'session', 'id' => 'solo1234-full']])
		);
		$this->assertSame(
			['kind' => 'group', 'ref' => 'grp-uuid', 'noun' => 'plot'],
			$this->gy->noteOffer(['note_target' => ['kind' => 'group', 'id' => 'grp-uuid']])
		);
	}

	public function testNoteOfferIsNullWithoutASingleNoteTarget(): void
	{
		$this->cli->forceInteractive = true;
		$this->assertNull($this->gy->noteOffer(null));
		$this->assertNull($this->gy->noteOffer(['note_target' => null]));
		$this->assertNull($this->gy->noteOffer([]));
		$this->assertNull($this->gy->noteOffer(['note_target' => ['kind' => 'session', 'id' => '']]));
	}

	public function testNoteOfferSkipsUnattendedBuries(): void
	{
		// You cannot hand-author a note in an unattended bury, and blocking one on an
		// editor is a bug. -y is the sharp edge: $cli->confirm() answers YES under -y, so
		// the skip has to happen HERE, before any prompt or launch is reached.
		$outcome = ['note_target' => ['kind' => 'session', 'id' => 'solo1234-full']];
		$this->cli->forceInteractive = true;

		$this->cli->setArgs(['graveyard', 'bury', 'x', '-y']);
		$this->assertNull($this->gy->noteOffer($outcome), '-y skips the offer');

		$this->cli->setArgs(['graveyard', 'bury', 'x', '--yes']);
		$this->assertNull($this->gy->noteOffer($outcome), '--yes skips the offer');

		$this->cli->setArgs(['graveyard', 'bury', 'x', '--porcelain']);
		$this->assertNull($this->gy->noteOffer($outcome), '--porcelain skips the offer');

		$this->cli->setArgs(['graveyard', 'bury', 'x', '--silent']);
		$this->assertNull($this->gy->noteOffer($outcome), '--silent skips the offer');

		// No tty to read an answer from: a prompt would block until the process is killed.
		$this->cli->setArgs(['graveyard', 'bury', 'x']);
		$this->cli->forceInteractive = false;
		$this->assertNull($this->gy->noteOffer($outcome), 'a non-interactive bury skips the offer');
	}

	public function testAnAutoconfirmedBuryLeavesNoNoteBehind(): void
	{
		// End to end on the class side: a scripted `bury <id> -y` buries, reports its
		// target, and still gets NO offer — so nothing creates a NOTES.md and nothing
		// would have launched an editor.
		$this->cli->setArgs(['graveyard', 'bury', 'solo1234-full', '-y']);
		$gy = $this->stubBury();

		$outcome = $gy->buryIds(['solo1234-full'], true);
		$this->assertSame(['solo1234-full'], $gy->buried);
		$this->assertNull($gy->noteOffer($outcome));
		$this->assertFileDoesNotExist($gy->noteSessionPath('solo1234-full'));
	}
}
