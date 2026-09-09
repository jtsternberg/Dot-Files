<?php
namespace JT\Tests\Transport;

use JT\Transport\SessionTransport;

/**
 * A transport that answers from arrays instead of a multiplexer.
 *
 * It IMPLEMENTS the interface rather than subclassing a real transport, which is
 * what keeps it out of the trap this effort hit four times: a subclass override of
 * a body that has since moved doubles nothing and the test goes on passing green,
 * whereas an interface implementation is a hard PHP error the moment the contract
 * changes under it. Nothing here can go quietly dead.
 *
 * Every create/drive call is recorded in $calls so a test can assert that a declined
 * confirm created NOTHING, rather than only that it printed a refusal.
 */
final class FakeTransport implements SessionTransport
{
	/** @var list<array{0:string,1:array}> */
	public array $calls = [];

	public function __construct(
		private string $name,
		private array $liveSessions = [],
		private bool $available = true,
		private bool $nonTerminal = false,
		private array $surfaces = [],
		private ?array $layoutTree = null,
		/** Non-null when this fake is the transport hosting the caller. */
		private ?string $selfSurfaceRef = null,
		/** What readScreen() answers: '' = read a blank surface, null = the read failed. */
		public ?string $screen = ''
	) {}

	/** A live row carrying just enough for liveness annotation and row routing. */
	public static function row(string $transport, string $sessionId, array $extra = []): array
	{
		return array_merge([
			'transport'   => $transport,
			'session_id'  => $sessionId,
			'agent'       => 'claude',
			'cwd'         => '/tmp',
			// The contract's row shape, not a minimal one: candidateRowFor() reads these
			// by name, so a fixture missing them tests a row no transport ever emits.
			'model'       => 'opus',
			'skip_perms'  => false,
			'opts'        => [],
			'pid'         => 4242,
			'tty'         => null,
			'no_bridge'   => false,
			'idle_seconds'=> 60,
			'targetable'  => true,
			'reason'      => null,
			'surface_ref' => 's1',
			'workspace_ref' => 'w1',
			'workspace_title' => 'fake',
			'tab_title'   => 'fake',
		], $extra);
	}

	public function name(): string { return $this->name; }
	public function available(): bool { return $this->available; }
	public function selfSurfaceRef(): ?string { return $this->selfSurfaceRef; }
	public function supportsNonTerminalSurfaces(): bool { return $this->nonTerminal; }
	public function liveSessions(): array { return $this->liveSessions; }
	public function surfaces(?string $workspaceRef = null): array { return $this->surfaces; }
	public function windowExists(string $windowRef): bool { return false; }

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void {
		$this->calls[] = ['sendText', [$surfaceRef, $workspaceRef, $text]];
	}
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void {
		$this->calls[] = ['sendKey', [$surfaceRef, $workspaceRef, $key]];
	}
	/**
	 * '' by default — a read that SUCCEEDED against a blank surface, which is what the
	 * gates then refuse on. Set $screen to null to fake a read that FAILED; that is a
	 * different answer and the busy check treats it as busy. @see SessionTransport.
	 */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): ?string {
		$this->calls[] = ['readScreen', [$surfaceRef, $workspaceRef, $lines]];
		return $this->screen;
	}

	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string {
		return $fallbackTitle !== '' ? "\"{$fallbackTitle}\" ({$handle})" : $handle;
	}
	public function resolveWorkspace(string $nameOrRef): ?array { return null; }
	public function workspaceSurfaceCount(string $workspaceRef): int { return count($this->surfaces); }

	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array {
		$this->calls[] = ['newWorkspace', [$title, $cwd, $windowRef]];
		return ['ref' => 'new-ws', 'id' => 'new-ws', 'firstPaneRef' => 'new-pane', 'firstSurfRef' => 'new-surf'];
	}
	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string {
		$this->calls[] = ['newSurface', [$workspaceRef, $paneRef, $type, $command]];
		return $type === 'terminal' ? 'surf-' . count($this->calls) : null;
	}
	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string {
		$this->calls[] = ['newSplit', [$workspaceRef, $fromSurfaceRef, $direction]];
		return 'split-' . count($this->calls);
	}
	public function selectSurface(string $workspaceRef, string $surfaceRef): bool {
		$this->calls[] = ['selectSurface', [$workspaceRef, $surfaceRef]];
		return true;
	}
	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string { return $surfaceRef; }

	public function closeWorkspace(string $workspaceRef): array {
		$this->calls[] = ['closeWorkspace', [$workspaceRef]];
		return ['output' => '', 'error' => '', 'exitCode' => 0];
	}
	public function closeSurface(string $surfaceRef): array {
		$this->calls[] = ['closeSurface', [$surfaceRef]];
		return ['output' => '', 'error' => '', 'exitCode' => 0];
	}

	public function captureLayoutTree(string $workspaceRef): ?array { return $this->layoutTree; }
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array {
		$this->calls[] = ['newWorkspaceWithLayout', [$title, $cwd, $layoutTree, $windowRef]];
		return $this->layoutTree === null ? null : ['ref' => 'new-ws', 'panes' => []];
	}
	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array { return $node; }
	public function layoutTreeSurfaceCount(array $node): int { return $this->layoutTree === null ? 0 : count($node); }

	/** Which of $calls created or drove something. */
	public function calledMethods(): array { return array_column($this->calls, 0); }
}
