<?php
namespace JT\Transport;

use LogicException;

/**
 * The transport for store-only page/serve requests.
 *
 * The HTTP router must render archived data without a running multiplexer, but it
 * must never accidentally drive a surface. Read-only discovery says "nothing live";
 * anything that could target, create or tear down a surface fails loudly rather
 * than silently no-op'ing, because a silent no-op in this position would look like
 * a successful restore.
 */
final class NullTransport implements SessionTransport
{
	use \JT\Helpers\TitleGlyphTrait;

	protected $cli;

	public function __construct($cli) { $this->cli = $cli; }

	public function name(): string { return 'null'; }

	/** Never "available": there is nothing behind it to reach. */
	public function available(): bool { return false; }

	/** Hosts nobody, so it never claims the caller — whatever the environment says. */
	public function selfSurfaceRef(): ?string { return null; }

	public function supportsNonTerminalSurfaces(): bool { return false; }

	public function liveSessions(): array { return []; }

	public function surfaces(?string $workspaceRef = null): array { return []; }

	/** Nothing is hosted here, so no handle can still exist. */
	public function windowExists(string $windowRef): bool { return false; }

	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): string { return ''; }

	/** Same shape Cmux produces for a handle it cannot find in the tree. */
	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string {
		return $fallbackTitle !== '' ? sprintf('"%s" (%s)', $this->stripGlyph($fallbackTitle), $handle) : $handle;
	}

	public function resolveWorkspace(string $nameOrRef): ?array { return null; }

	public function workspaceSurfaceCount(string $workspaceRef): int { return 0; }

	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string { return null; }

	public function layoutTreeSurfaceCount(array $node): int { return 0; }

	public function captureLayoutTree(string $workspaceRef): ?array { return null; }

	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array { return $node; }

	private function unavailable(string $method): never
	{
		throw new LogicException("{$method} is unavailable in the transport-free page server.");
	}

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void { $this->unavailable(__FUNCTION__); }
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void { $this->unavailable(__FUNCTION__); }
	public function selectSurface(string $workspaceRef, string $surfaceRef): bool { $this->unavailable(__FUNCTION__); }
	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string { $this->unavailable(__FUNCTION__); }
	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string { $this->unavailable(__FUNCTION__); }
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array { $this->unavailable(__FUNCTION__); }
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array { $this->unavailable(__FUNCTION__); }
	public function closeWorkspace(string $workspaceRef): array { $this->unavailable(__FUNCTION__); }
	public function closeSurface(string $surfaceRef): array { $this->unavailable(__FUNCTION__); }
}
