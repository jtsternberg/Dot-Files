<?php
namespace JT\Helpers;

use LogicException;

/**
 * Cmux implementation for store-only page/serve requests.
 *
 * The HTTP router must render archived data without a running cmux, but it must
 * never accidentally drive a surface. Read-only discovery says "nothing live";
 * any operation that could target a surface fails loudly.
 */
final class NullCmux extends Cmux
{
	public function cmuxBin(): string { return ''; }
	public function tree(): array { return []; }
	public function jsonlPathFor(string $sessionId, string $cwd): string { return ''; }
	public function loadClaudeSessions(): array { return []; }
	public function pidIsAlive(int $pid) { return false; }
	public function getTtyForPid(int $pid) { return null; }
	public function debugTerminals(): string { return ''; }
	public function psProcTable(): string { return ''; }
	public function descendantClaudePid(array $proc, int $root): ?int { return null; }
	public function descendantPids(array $proc, int $root): array { return []; }
	public function loadClaudeSessionsByPid(): array { return []; }
	public function codexSessionIdForPid(int $pid): ?string { return null; }
	public function codexRolloutPathFor(string $sessionId): ?string { return null; }
	public function loadCodexSessionsByPid(): array { return []; }
	public function codexSurfaceIdsByPid(): array { return []; }
	public function mapSurfaceUuids(array $tree): array { return []; }
	public function joinCodexToSurfaces(array $codexSessions, array $surfaceUuids): array { return []; }
	public function joinSessionsToSurfaces(array $sessions, array $proc, array $debug): array { return []; }
	public function lastRealActivity(string $sessionId, string $cwd): ?int { return null; }
	public function readSessionJsonl(?string $sessionId, ?string $cwd): array { return []; }

	private function mutation(string $method): never
	{
		throw new LogicException("{$method} is unavailable in the cmux-free page server.");
	}

	public function sendToSurface(string $surfRef, string $wsRef, string $text): void { $this->mutation(__FUNCTION__); }
	public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void { $this->mutation(__FUNCTION__); }
	public function selectSurface(string $wsRef, string $surfRef): bool { $this->mutation(__FUNCTION__); }
	public function createSurface(string $wsRef, ?string $paneRef, string $type, ?string $url): ?string { $this->mutation(__FUNCTION__); }
	public function newSplit(string $wsRef, string $fromSurfRef, string $direction): ?string { $this->mutation(__FUNCTION__); }
	public function newPane(string $wsRef, string $direction = 'right'): ?array { $this->mutation(__FUNCTION__); }
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): array { $this->mutation(__FUNCTION__); }
	public function newWorkspaceOrNull(string $title, ?string $cwd, ?string $windowRef = null): ?array { $this->mutation(__FUNCTION__); }
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array { $this->mutation(__FUNCTION__); }
}
