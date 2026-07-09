<?php
namespace JT;

class Graveyard {

	protected $cli;
	protected $cmux;

	public function __construct($cli, $cmux) {
		$this->cli  = $cli;
		$this->cmux = $cmux;
	}

	public function storeRoot(): string {
		$env = getenv('GRAVEYARD_ROOT');
		return $env ?: $this->cli->convertPathToAbsolute('~/.claude-graveyard');
	}

	public function sessionDir(string $id): string { return $this->storeRoot() . "/sessions/{$id}"; }
	public function transcriptPath(string $id): string { return $this->sessionDir($id) . '/transcript.txt'; }
	public function metaPath(string $id): string { return $this->sessionDir($id) . '/meta.json'; }
	public function indexPath(): string { return $this->storeRoot() . '/index.json'; }

	public function parseDuration(string $s): int {
		if (!preg_match('/^(\d+)([smhd]?)$/', trim($s), $m)) {
			throw new \InvalidArgumentException("Invalid duration: {$s}");
		}
		$n = (int) $m[1];
		switch ($m[2]) {
			case 'd': return $n * 86400;
			case 'h': return $n * 3600;
			case 'm': return $n * 60;
			default:  return $n; // 's' or bare
		}
	}

	public function readIndex(): array {
		$path = $this->indexPath();
		if (!file_exists($path)) {
			return ['version' => 1, 'tombstones' => []];
		}
		$data = json_decode((string) file_get_contents($path), true);
		return $data ?: ['version' => 1, 'tombstones' => []];
	}

	public function upsertIndex(array $entry): void {
		$idx = $this->readIndex();
		$idx['tombstones'] = array_values(array_filter(
			$idx['tombstones'],
			fn($t) => ($t['session_id'] ?? null) !== ($entry['session_id'] ?? null)
		));
		$idx['tombstones'][] = $entry;
		$dir = dirname($this->indexPath());
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		file_put_contents($this->indexPath(), json_encode($idx, JSON_PRETTY_PRINT));
	}

	public function buildTombstone(array $session, array $surface, string $summary, string $buriedAt): array {
		$sessionId = $session['session_id'];
		$cwd       = $session['cwd'] ?? '';
		$lastActive = null;
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (is_file($jsonl)) {
			$lastActive = gmdate('Y-m-d\TH:i:s\Z', filemtime($jsonl));
		}
		return [
			'session_id'      => $sessionId,
			'workspace_title' => $surface['workspace_title'] ?? '',
			'tab_title'       => $surface['tab_title'] ?? '',
			'cwd'             => $cwd,
			'model'           => $session['model'] ?? null,
			'skip_perms'      => (bool) ($session['skip_perms'] ?? false),
			'summary'         => $summary,
			'buried_at'       => $buriedAt,
			'last_active'     => $lastActive,
		];
	}
}
