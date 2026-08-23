<?php
namespace JT;

class Graveyard {

	// stripGlyph(): shared with Helpers\Cmux via the trait, NOT borrowed off
	// $this->cmux — the served page renders with a null cmux (graveyard_router.php).
	use \JT\Helpers\TitleGlyphTrait;

	// Active-turn markers Claude Code prints while a turn is running. Absence-based
	// detection (NOT prompt matching, which is fragile with custom/powerline prompts).
	const ACTIVE_TURN_RE = '/(esc to interrupt|\(\s*[\d.]+k?\s+tokens\s*\)|Cogitating|Thinking…|Pondering|Deciphering)/i';

	const IDLE_FLOOR_DEFAULT = 15; // seconds; JSONL must be quiet at least this long

	// Raw Ctrl-C byte. Clears whatever is half-typed in Claude Code's prompt box
	// before we issue /export. `cmux send-key ctrl+c` does NOT reach the REPL as a
	// working interrupt (verified against Claude Code v2.1.216); sending this raw
	// byte through the text `send` path does, and clears the input reliably.
	const CLEAR_PROMPT = "\x03";

	// Flags the members of a buried workspace that a search term actually hit; the
	// unmatched siblings get a blank column of the same width so titles stay aligned.
	const MATCH_MARK = '✱';

	protected $cli;
	protected $cmux;

	/** Memoised [session_id => agent] for liveness annotation; null until first resolved. */
	protected ?array $liveIdCache = null;

	/**
	 * Memoised codex rollout reader. Held rather than newed per call because its
	 * per-file parse cache lives on the instance, and one `search --full-text` or page
	 * render can ask about the same 60 MB rollout more than once.
	 */
	protected ?Helpers\CodexRollout $codexRollout = null;

	public function __construct($cli, Helpers\Cmux $cmux) {
		$this->cli  = $cli;
		$this->cmux = $cmux;
	}

	public function storeRoot(): string {
		$env = getenv('GRAVEYARD_ROOT');
		return $env ?: $this->cli->convertPathToAbsolute('~/.claude-graveyard');
	}

	public function sessionDir(string $id): string { return $this->storeRoot() . "/sessions/{$id}"; }
	/**
	 * The archived transcript for a session, whichever renderer wrote it (dotfiles-36a).
	 *
	 * The extension follows the RENDERER, not the version: export-session.mjs emits
	 * markdown SOURCE (transcript.md — so `graveyard show` gets a markdown preview and the
	 * page can render it), while Claude Code's own /export emits already-rendered TUI text
	 * (transcript.txt — glyphs and hard wrapping that read correctly raw). The store is
	 * permanently mixed: every archive buried before the seam landed is TUI .txt and stays
	 * that way, so this is the resolver EVERY reader goes through — prefer .md, fall back
	 * to .txt. Writers use transcriptMdPath()/transcriptTxtPath() explicitly instead.
	 *
	 * With nothing archived it returns the legacy .txt name, so a missing-transcript error
	 * still reads the way it always did.
	 */
	public function transcriptPath(string $id): string {
		$md = $this->transcriptMdPath($id);
		return is_file($md) ? $md : $this->transcriptTxtPath($id);
	}

	/** Where exportTranscriptViaBin() writes: markdown source from export-session.mjs. */
	public function transcriptMdPath(string $id): string { return $this->sessionDir($id) . '/transcript.md'; }

	/**
	 * The archived transcript for a tombstone, RENDERING a codex rollout into one first if
	 * that is all there is. Returns the path either way; the caller's existing
	 * missing-transcript behaviour is preserved when there is nothing to render.
	 *
	 * This is what makes codex archives readable without teaching five readers a second
	 * format. `show`, `search --full-text`, the page modal, the copy-path button and
	 * resurrect-from-transcript all resolve ONE markdown archive; codex bury preserves a raw
	 * rollout, which none of them understand. Rendering it into the same markdown the Claude
	 * path writes means every one of them keeps its single input.
	 *
	 * Rendering LAZILY on read, not only at bury, is deliberate: it also heals sessions
	 * buried before this existed, so the store's already-buried codex tombstone gains a
	 * transcript on its next view with no `repair` pass.
	 *
	 * Never writes an empty archive, and never writes next to a surviving .txt — the
	 * resolver prefers .md, so adding one beside a legacy .txt would silently move every
	 * reader onto the new file (the same hazard dropSupersededArchive() exists for).
	 */
	public function ensureTranscript(array $t): string {
		$id = (string) ($t['session_id'] ?? '');
		if ($id === '' || $this->tombstoneAgent($t) !== 'codex') { return $this->transcriptPath($id); }

		$existing = $this->transcriptPath($id);
		if (is_file($existing)) { return $existing; }

		$rollout = $this->codexRolloutArchivePath($id);
		if (!is_file($rollout)) { return $existing; }

		// A rollout with no readable turns must not become a header-only archive. That is
		// the same rule exportTranscriptViaBin() enforces for Claude — an empty transcript
		// is a failure, not an archive — and it matters more here, because resurrect points
		// a fresh agent at this file and tells it to re-orient from it.
		if (!$this->codexRollout()->genuineTurns($rollout)) { return $existing; }

		$md = $this->codexRollout()->toMarkdownArchive($rollout, ['title' => $this->titleizeSummary($t)]);
		if (trim($md) === '') { return $existing; }

		$dest = $this->transcriptMdPath($id);
		$dir  = dirname($dest);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return $existing; }

		// Temp-then-rename, so a reader can never catch a half-written transcript and treat
		// it as the whole conversation.
		$tmp = $dir . '/.transcript.md.tmp';
		if (@file_put_contents($tmp, $md) === false) { @unlink($tmp); return $existing; }
		if (!@rename($tmp, $dest)) { @unlink($tmp); return $existing; }

		return $dest;
	}

	/**
	 * Where a buried codex session's rollout is archived.
	 *
	 * Codex is archived by COPYING its rollout, not by rendering it: there is no
	 * `/export` equivalent to type into the REPL, and the rollout is the complete
	 * record. Kept as raw .jsonl so nothing is lost now — rendering it as TUI-style
	 * text for ls/search/page needs its own reader (session_meta / turn_context /
	 * response_item envelopes, nothing like Claude's), tracked as dotfiles-6me.
	 */
	public function codexRolloutArchivePath(string $id): string { return $this->sessionDir($id) . '/rollout.jsonl'; }

	/** Where exportTranscriptViaRepl() writes: TUI-rendered text from Claude Code's /export. */
	public function transcriptTxtPath(string $id): string { return $this->sessionDir($id) . '/transcript.txt'; }
	public function metaPath(string $id): string { return $this->sessionDir($id) . '/meta.json'; }

	/**
	 * One buried session's stored tombstone, read from its own meta.json.
	 *
	 * For readers that need a single record WITHOUT the liveness annotation tombstones()
	 * adds — specifically the page server, which bin/graveyard runs as a store-only verb
	 * with no cmux ping, so anything that shells out to cmux to answer a page request turns
	 * "show me this transcript" into "is cmux up?". meta.json is a byte-identical copy of
	 * the index entry, written at bury.
	 */
	public function sessionMeta(string $id): ?array {
		if ($id === '') { return null; }

		// The index first — it is the record every other reader resolves against — then the
		// per-session copy, which survives an index that has not been written yet.
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (($t['session_id'] ?? '') === $id) { return $t; }
		}

		$path = $this->metaPath($id);
		if (!is_file($path)) { return null; }
		$meta = json_decode((string) @file_get_contents($path), true);
		return is_array($meta) ? $meta : null;
	}
	public function workspaceGroupDir(string $group): string { return $this->storeRoot() . "/workspaces/{$group}"; }
	public function manifestPath(string $group): string { return $this->workspaceGroupDir($group) . '/manifest.json'; }
	public function indexPath(): string { return $this->storeRoot() . '/index.json'; }
	public function pageFilePath(): string { return $this->storeRoot() . '/index.html'; }
	public function pageDataDir(): string { return $this->storeRoot() . '/page-data'; }
	public function transcriptJsPath(string $id): string { return $this->pageDataDir() . "/{$id}.js"; }

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
		$this->mutateIndex(function (array &$idx) use ($entry): void {
			$idx['tombstones'] = array_values(array_filter(
				$idx['tombstones'],
				fn($t) => ($t['session_id'] ?? null) !== ($entry['session_id'] ?? null)
			));
			$idx['tombstones'][] = $entry;
		});
	}

	/** I/O. Serialize an index read-modify-write operation across graveyard processes. */
	public function mutateIndex(callable $mutation): mixed {
		$dir = dirname($this->indexPath());
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		$lock = fopen($this->indexPath() . '.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Could not lock graveyard index.'); }
		try {
			$idx = $this->readIndex();
			$result = $mutation($idx);
			$this->writeIndex($idx);
			return $result;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** I/O. Write the whole index atomically-ish (LOCK_EX). See dotfiles-8tp for full RMW locking. */
	public function writeIndex(array $idx): void {
		$dir = dirname($this->indexPath());
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		file_put_contents($this->indexPath(), json_encode($idx, JSON_PRETTY_PRINT), LOCK_EX);
	}

	/**
	 * I/O. Stamp a custom display name on a buried session (exact session id).
	 * titleizeSummary and the fuzzy resolver both prefer it, so the page shows
	 * it and `resurrect <name>` finds it. Returns false if no such session.
	 */
	public function setSessionName(string $sessionId, string $name): bool {
		$name = trim($name);
		$idx  = $this->readIndex();
		$found = false;
		foreach ($idx['tombstones'] as &$t) {
			if (($t['session_id'] ?? null) === $sessionId) { $t['name'] = $name; $found = true; }
		}
		unset($t);
		if ($found) { $this->writeIndex($idx); }
		return $found;
	}

	/**
	 * I/O. Retitle a whole workspace group: every member tombstone's group_title
	 * plus the manifest's group_title (so ls/page/resurrect all agree). Returns
	 * the number of member tombstones retitled.
	 */
	public function setGroupName(string $groupId, string $name): int {
		$name = trim($name);
		$idx  = $this->readIndex();
		$n = 0;
		foreach ($idx['tombstones'] as &$t) {
			if (($t['group_id'] ?? null) === $groupId) { $t['group_title'] = $name; $n++; }
		}
		unset($t);
		if ($n > 0) { $this->writeIndex($idx); }
		$mp = $this->manifestPath($groupId);
		if (is_file($mp)) {
			$m = json_decode((string) @file_get_contents($mp), true);
			if (is_array($m)) { $m['group_title'] = $name; file_put_contents($mp, json_encode($m, JSON_PRETTY_PRINT), LOCK_EX); }
		}
		return $n;
	}

	/** Verb. Resolve a session (fuzzy) and give it a custom display name. */
	public function renameSession(string $ref, string $name): void {
		$name = trim($name);
		if ($name === '') { $this->cli->exitErr('Refusing to set an empty name.'); }
		$res = $this->resolveTombstoneFuzzy($ref);
		$t   = $res['match'];
		if (!$t) {
			if ($res['ambiguous']) { $this->cli->exitErr("'{$ref}' is ambiguous — narrow it or pass a full session-id."); }
			$this->cli->exitErr("No buried session matches '{$ref}'.");
		}
		$this->setSessionName((string) $t['session_id'], $name);
		$this->cli->successMsg('Renamed ' . substr((string) $t['session_id'], 0, 8) . " → \"{$name}\".");
	}

	/** Verb. Resolve a workspace group (by id prefix) and retitle the whole plot. */
	public function renameGroup(string $prefix, string $name): void {
		$name = trim($name);
		if ($name === '') { $this->cli->exitErr('Refusing to set an empty name.'); }
		$m = $this->resolveGroup($prefix);
		if (!$m) { $this->cli->exitErr("No single workspace group matches '{$prefix}'."); return; }
		$n = $this->setGroupName((string) $m['group_id'], $name);
		$this->cli->successMsg('Renamed workspace ' . substr((string) $m['group_id'], 0, 8)
			. " ({$n} session" . ($n === 1 ? '' : 's') . ") → \"{$name}\".");
	}

	/** I/O. Recursively remove a directory (or a file). No-op if absent. */
	protected function rmrf(string $path): void {
		if (is_file($path) || is_link($path)) { @unlink($path); return; }
		if (!is_dir($path)) { return; }
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
		@rmdir($path);
	}

	/**
	 * I/O. PERMANENTLY remove one buried session: its index entry, its export
	 * dir (sessions/<id>/), and its page-data transcript (page-data/<id>.js).
	 * Returns which pieces were actually removed. Unknown id → harmless no-op.
	 */
	public function purgeSession(string $sessionId): array {
		$removed = ['session_id' => $sessionId, 'index' => false, 'dir' => false, 'js' => false];
		$idx    = $this->readIndex();
		$before = count($idx['tombstones'] ?? []);
		$idx['tombstones'] = array_values(array_filter(
			$idx['tombstones'] ?? [],
			fn($t) => ($t['session_id'] ?? null) !== $sessionId
		));
		if (count($idx['tombstones']) !== $before) { $this->writeIndex($idx); $removed['index'] = true; }

		$dir = $this->sessionDir($sessionId);
		if (is_dir($dir)) { $this->rmrf($dir); $removed['dir'] = true; }
		$js = $this->transcriptJsPath($sessionId);
		if (is_file($js)) { @unlink($js); $removed['js'] = true; }
		return $removed;
	}

	/**
	 * I/O. PERMANENTLY remove a whole workspace group: every member session
	 * (index + artifacts) and the group dir (workspaces/<gid>/, incl. manifest).
	 * Returns the count + session ids removed.
	 */
	public function purgeGroup(string $groupId): array {
		$members = array_values(array_filter(
			$this->readIndex()['tombstones'] ?? [],
			fn($t) => ($t['group_id'] ?? null) === $groupId
		));
		$sids = array_map(fn($t) => (string) $t['session_id'], $members);
		foreach ($sids as $sid) { $this->purgeSession($sid); }
		$wd = $this->workspaceGroupDir($groupId);
		if (is_dir($wd)) { $this->rmrf($wd); }
		return ['group_id' => $groupId, 'removed' => count($sids), 'sessions' => $sids];
	}

	/** Verb. Resolve a session (fuzzy), confirm unless -y, then permanently purge it. */
	public function deleteSession(string $ref, bool $autoconfirm = false): void {
		$res = $this->resolveTombstoneFuzzy($ref);
		$t   = $res['match'];
		if (!$t) {
			if ($res['ambiguous']) { $this->cli->exitErr("'{$ref}' is ambiguous — narrow it or pass a full session-id."); }
			$this->cli->exitErr("No buried session matches '{$ref}'.");
		}
		$sid   = (string) $t['session_id'];
		$title = $this->titleizeSummary($t);
		if (!$autoconfirm && !$this->cli->confirm("Permanently delete " . substr($sid, 0, 8) . " \"{$title}\"? This cannot be undone.")) {
			$this->cli->msg('Left undisturbed.', 'yellow');
			return;
		}
		$this->purgeSession($sid);
		$this->cli->successMsg('Deleted ' . substr($sid, 0, 8) . " \"{$title}\".");
	}

	/** Verb. Resolve a workspace group (by id prefix), confirm unless -y, then purge the whole plot. */
	public function deleteGroup(string $prefix, bool $autoconfirm = false): void {
		$m = $this->resolveGroup($prefix);
		if (!$m) { $this->cli->exitErr("No single workspace group matches '{$prefix}'."); return; }
		$gid   = (string) $m['group_id'];
		$title = trim((string) ($m['group_title'] ?? '')) ?: substr($gid, 0, 8);
		$count = 0;
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (($t['group_id'] ?? null) === $gid) { $count++; }
		}
		if (!$autoconfirm && !$this->cli->confirm("Permanently delete the whole \"{$title}\" plot ({$count} session" . ($count === 1 ? '' : 's') . ")? This cannot be undone.")) {
			$this->cli->msg('Left undisturbed.', 'yellow');
			return;
		}
		$res = $this->purgeGroup($gid);
		$this->cli->successMsg("Deleted the \"{$title}\" plot ({$res['removed']} session" . ($res['removed'] === 1 ? '' : 's') . ").");
	}

	public function buildTombstone(array $session, array $surface, string $summary, string $buriedAt, ?array $group = null): array {
		$sessionId = $session['session_id'];
		$cwd       = $session['cwd'] ?? '';
		$agent      = $session['agent'] ?? 'claude';
		$lastActive = null;
		$src = $agent === 'codex'
			? $this->cmux->codexRolloutPathFor($sessionId)
			: $this->cmux->jsonlPathFor($sessionId, $cwd);
		if ($src !== null && is_file($src)) {
			$lastActive = gmdate('Y-m-d\TH:i:s\Z', filemtime($src));
		}
		$tomb = [
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
		// Where this tab was buried FROM, so resurrect can put it back rather than
		// building a fresh workspace next to the one you are still sitting in. Only
		// written when known, so a tombstone never carries a home resurrect would aim
		// at and miss.
		if (!empty($session['home_workspace_id'])) {
			$tomb['home_workspace_id']  = $session['home_workspace_id'];
			$tomb['home_pane_id']       = $session['home_pane_id'] ?? null;
			$tomb['home_index_in_pane'] = $session['home_index_in_pane'] ?? null;
		}
		if (!empty($session['window_ref'])) { $tomb['window_ref'] = $session['window_ref']; }

		// ADDITIVE schema (dotfiles-51b): only non-claude tombstones gain keys, so every
		// archive written before codex support — and every claude archive written after —
		// keeps exactly the shape it had. Readers dispatch via tombstoneAgent(), which
		// treats a missing kind as claude. agent_opts carries the sandbox/approval/effort
		// that `codex resume` will NOT rehydrate on its own (see Cmux::buildCodexResumeCommand).
		if ($agent !== 'claude') {
			$tomb['kind']       = $agent;
			$tomb['agent_opts'] = is_array($session['opts'] ?? null) ? $session['opts'] : [];

			// AN HONEST ARCHIVE (dotfiles-f1n). sandbox/approval only exist in the
			// rollout's turn_context records, and ~45% of real rollouts have none — every
			// Codex Desktop (source=vscode) session, permanently. Such a session used to
			// get null agent_opts and come back on whatever ~/.codex/config.toml defaults
			// to, with nothing recorded and nothing said. Stamp it instead, so the
			// tombstone says "unknown" rather than implying "nothing was set".
			//
			// Read from the rollout rather than trusting $session['opts'] to be empty:
			// what could not be preserved is a fact about the SOURCE, and the opts carry
			// has been dropped by plumbing before (see the round-trip regression test).
			// A missing/unreadable rollout counts as absent too — nothing was preserved
			// from it either way.
			if ($agent === 'codex' && !$this->cmux->codexRolloutContext((string) $src)['has_turn_context']) {
				$tomb['agent_opts_unknown'] = true;
			}
		}

		// Grouped (workspace) bury: stamp the shared group id + layout position so
		// ls/resurrect can operate on the workspace as a unit, while the member
		// tombstone stays self-sufficient for single-member resurrect.
		if ($group !== null) {
			$tomb['group_id']    = $group['group_id'] ?? null;
			$tomb['group_title'] = $group['group_title'] ?? null;
			$tomb['group_pos']   = $group['group_pos'] ?? null;
		}
		return $tomb;
	}

	/**
	 * PURE. Re-derive a tombstone's config (skip_perms, model) from a freshly-read
	 * JSONL meta (permission_mode + model). Returns [updatedTomb, changes] where
	 * changes maps field => [old, new]. Only NON-NULL jsonl values overwrite, so a
	 * jsonl that lacks a permission-mode/model entry never downgrades stored data —
	 * we correct false-negatives (skip_perms recorded false / model null because the
	 * jsonl was missing at burial), never clobber good values with defaults.
	 */
	public function reconcileTombstoneConfig(array $tomb, array $jsonlMeta): array {
		$changes = [];
		if (($jsonlMeta['permission_mode'] ?? null) !== null) {
			$new = $jsonlMeta['permission_mode'] === 'bypassPermissions';
			if ($new !== (bool) ($tomb['skip_perms'] ?? false)) {
				$changes['skip_perms'] = [(bool) ($tomb['skip_perms'] ?? false), $new];
				$tomb['skip_perms'] = $new;
			}
		}
		if (($jsonlMeta['model'] ?? null) !== null && $jsonlMeta['model'] !== ($tomb['model'] ?? null)) {
			$changes['model'] = [$tomb['model'] ?? null, $jsonlMeta['model']];
			$tomb['model'] = $jsonlMeta['model'];
		}
		return [$tomb, $changes];
	}

	/**
	 * Backfill stored config for already-buried sessions from their live JSONL, when
	 * it still exists. Sessions buried while the jsonl was missing/unreadable recorded
	 * defaults (skip_perms=false, model=null); this re-derives the truth wherever the
	 * jsonl survived. Syncs BOTH the index and the per-session meta.json. With
	 * $apply=false it only reports. Returns per-session rows:
	 * [ session_id, status(changed|ok|no-jsonl), changes ].
	 */
	public function repairBuriedConfigs(bool $apply): array {
		$idx    = $this->readIndex();
		$rows   = $idx['tombstones'] ?? [];
		$report = [];
		foreach ($rows as $i => $t) {
			$sid = $t['session_id'] ?? null;
			if (!$sid) { continue; }
			$jsonl = $this->cmux->jsonlPathFor($sid, $t['cwd'] ?? '');
			if (!is_file($jsonl)) {
				$report[] = ['session_id' => $sid, 'status' => 'no-jsonl', 'changes' => []];
				continue;
			}
			$meta = $this->cmux->readSessionJsonl($sid, $t['cwd'] ?? '');
			[$updated, $changes] = $this->reconcileTombstoneConfig($t, $meta);
			if ($changes) {
				$rows[$i] = $updated;
				if ($apply) {
					$mp = $this->metaPath($sid);
					if (is_file($mp)) {
						$m = json_decode((string) file_get_contents($mp), true) ?: [];
						foreach ($changes as $k => $pair) { $m[$k] = $pair[1]; }
						file_put_contents($mp, json_encode($m, JSON_PRETTY_PRINT));
					}
				}
			}
			$report[] = ['session_id' => $sid, 'status' => $changes ? 'changed' : 'ok', 'changes' => $changes];
		}
		if ($apply) {
			$idx['tombstones'] = $rows;
			$this->writeIndex($idx);
		}
		return $report;
	}

	/** I/O + output. Drive repairBuriedConfigs and print a human summary. */
	public function printRepair(bool $apply): void {
		$report  = $this->repairBuriedConfigs($apply);
		$changed = array_values(array_filter($report, fn($r) => $r['status'] === 'changed'));
		$noJsonl = array_values(array_filter($report, fn($r) => $r['status'] === 'no-jsonl'));

		if (!$changed) {
			$this->cli->msg('No config drift found in ' . count($report) . ' buried session(s).', 'green');
		} else {
			$this->cli->msg(($apply ? 'Corrected ' : 'Would correct ') . count($changed) . ' session(s):', 'yellow');
			foreach ($changed as $r) {
				$parts = [];
				foreach ($r['changes'] as $field => $pair) {
					$fmt = fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : ($v === null ? 'null' : (string) $v);
					$parts[] = sprintf('%s %s→%s', $field, $fmt($pair[0]), $fmt($pair[1]));
				}
				$this->cli->msg('  ' . substr($r['session_id'], 0, 8) . '  ' . implode(', ', $parts), 'cyan');
			}
		}
		if ($noJsonl) {
			$this->cli->msg(count($noJsonl) . ' session(s) skipped (no surviving JSONL — cannot re-derive).', 'yellow');
		}
		$orphans = $this->orphanedManifestGroups();
		if ($orphans) {
			$this->cli->msg(($apply ? 'Pruned ' : 'Would prune ') . count($orphans) . ' orphaned workspace manifest(s) (no tombstone references them):', 'yellow');
			foreach ($orphans as $gid) { $this->cli->msg('  ' . substr($gid, 0, 8), 'cyan'); }
			if ($apply) { $this->pruneOrphanedManifests(); }
		}

		if (!$apply && ($changed || $orphans)) {
			$this->cli->msg('Dry run — re-run with --apply to write these corrections.', 'blue');
		}
	}

	public function selfSurfaceId(): ?string {
		return getenv('CMUX_SURFACE_ID') ?: null;
	}

	public function selfSessionId(): ?string {
		$sid = $this->selfSurfaceId();
		if (!$sid) { return null; }
		foreach ($this->liveSessions() as $s) {
			if (($s['surface_id'] ?? null) === $sid || ($s['surface_ref'] ?? null) === $sid) {
				return $s['session_id'];
			}
		}
		return null;
	}

	public function resolveLiveBySessionId(string $sessionId): ?array {
		foreach ($this->liveSessions() as $s) {
			if ($s['session_id'] === $sessionId) { return $s; }
		}
		return null;
	}

	public function filterSelf(array $sessions, ?string $selfSurfaceId, ?string $selfSessionId): array {
		return array_values(array_filter($sessions, function ($s) use ($selfSurfaceId, $selfSessionId) {
			if ($selfSurfaceId && ($s['surface_ref'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSurfaceId && ($s['surface_id'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSessionId && ($s['session_id'] ?? null) === $selfSessionId) { return false; }
			return true;
		}));
	}

	public function isBusy(int $idleSeconds, int $idleFloor, string $lastScreen): bool {
		if ($idleSeconds < $idleFloor) { return true; }
		return (bool) preg_match(self::ACTIVE_TURN_RE, $lastScreen);
	}

	public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string {
		$cmd = escapeshellcmd($this->cmux->cmuxBin()) . ' read-screen --surface ' . escapeshellarg($surfaceRef)
			 . ' --workspace ' . escapeshellarg($workspaceRef)
			 . ' --lines ' . (int) $lines . ' 2>/dev/null';
		return (string) shell_exec($cmd);
	}

	/**
	 * Idle clock for a codex session: the timestamp of the last complete record in
	 * its rollout. Scanned backward, because rollouts run to megabytes.
	 *
	 * An unparseable tail line is skipped rather than treated as "no activity" — a
	 * rollout being appended to can end mid-write, and reporting no activity would
	 * read as infinitely idle, i.e. make a live session look buryable.
	 */
	public function codexLastActivity(string $rolloutPath): ?int {
		$ts = null;
		$this->cmux->eachLineReverse($rolloutPath, function (string $line) use (&$ts) {
			$rec = json_decode(trim($line), true);
			if (!is_array($rec) || empty($rec['timestamp'])) {
				return true; // partial/blank tail line — keep walking back
			}
			$parsed = strtotime((string) $rec['timestamp']);
			if ($parsed === false) { return true; }
			$ts = $parsed;
			return false;
		});
		return $ts;
	}

	public function liveSessions(): array {
		// Deterministic session<->surface joins. Both agents bind on CMUX_SURFACE_ID
		// against the tree's per-surface id (dotfiles-zcm, dotfiles-dr9); Claude also
		// bridges through a resume script when it was resurrected behind one
		// (dotfiles-yt2). Never by tty — tty numbers are recycled across live
		// surfaces, so a tty join mis-pairs. BOTH joins need the surface-UUID map:
		// starve the Claude join of it and every cmux-launched session goes unbound.
		$sessions     = $this->cmux->loadClaudeSessionsByPid();
		$proc         = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$debug        = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$tree         = $this->cmux->tree();
		$surfaceUuids = $this->cmux->mapSurfaceUuids($tree);
		$joined       = array_merge(
			$this->cmux->joinSessionsToSurfaces($sessions, $proc, $debug, $surfaceUuids),
			$this->cmux->joinCodexToSurfaces(
				$this->cmux->loadCodexSessionsByPid(),
				$surfaceUuids
			)
		);

		// Tree supplies stable surface UUID + workspace/surface titles, keyed by ref.
		$treeIx = $this->treeIndex($tree);
		$now    = time();
		$out    = [];

		foreach ($joined as $j) {
			if (!$j['session_id']) { continue; }
			$agent = $j['agent'] ?? 'claude';
			if ($agent === 'codex') {
				$rollout = $this->cmux->codexRolloutPathFor($j['session_id']);
				$ts      = $rollout !== null ? $this->codexLastActivity($rollout) : null;
			} else {
				$ts = $this->cmux->lastRealActivity($j['session_id'], $j['cwd']);
			}
			$idle = $ts !== null ? ($now - $ts) : PHP_INT_MAX;
			$ref  = $j['surface_ref'];
			$out[] = [
				'session_id'      => $j['session_id'],
				'agent'           => $agent,
				'cwd'             => $j['cwd'],
				'model'           => $j['model'],
				'skip_perms'      => $j['skip_perms'],
				// Agent-specific knobs (codex sandbox/approval/effort). MUST be carried:
				// buildTombstone() stores them as agent_opts and resurrect replays them,
				// and `codex resume` re-reads config rather than rehydrating turn_context —
				// so dropping them here silently widens a restored session's sandbox.
				'opts'            => $j['opts'] ?? [],
				'pid'             => $j['pid'],
				'tty'             => $j['tty'],
				'surface_ref'     => $ref,
				'surface_id'      => $treeIx['surface'][$ref]['id'] ?? $ref,
				// Where it currently lives, so bury can record a home to resurrect into.
				'home_workspace_id'  => $treeIx['surface'][$ref]['workspace_id'] ?? null,
				'home_pane_id'       => $treeIx['surface'][$ref]['pane_id'] ?? null,
				'home_index_in_pane' => $treeIx['surface'][$ref]['index_in_pane'] ?? null,
				'workspace_ref'   => $j['workspace_ref'],
				'window_ref'      => $treeIx['workspace_window'][$j['workspace_ref']] ?? null,
				'workspace_title' => $treeIx['workspace'][$j['workspace_ref']] ?? '',
				'tab_title'       => $treeIx['surface'][$ref]['title'] ?? $j['title'],
				'idle_seconds'    => $idle,
				'targetable'      => $j['targetable'],
				'reason'          => $j['reason'],
				'no_bridge'       => $j['no_bridge'] ?? false,
			];
		}

		// Second pass (dotfiles-c15): content-probe fallback for Claude sessions the
		// ancestry join left unbound (fresh / non-cmux-resumed). Bind each to a still-
		// unbound terminal surface by matching its on-screen statusline cwd, uniquely.
		$out = $this->bindUnresolvedByContentProbe($out, $debug, $treeIx);

		return $this->dedupBySessionId($out);
	}

	/**
	 * PURE. Index a cmux tree for ref-keyed lookups:
	 *   ['surface' => [surface_ref => ['id','title']], 'workspace' => [workspace_ref => title]].
	 */
	public function treeIndex(array $tree): array {
		$ix = ['surface' => [], 'workspace' => [], 'workspace_window' => []];
		foreach ($tree['windows'] ?? [] as $window) {
			$windowRef = $window['ref'] ?? null;
			foreach ($window['workspaces'] ?? [] as $ws) {
				$wref = $ws['ref'] ?? '';
				if ($wref) { $ix['workspace'][$wref] = $ws['title'] ?? ''; }
				if ($wref && $windowRef) { $ix['workspace_window'][$wref] = $windowRef; }
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$ref = $surf['ref'] ?? '';
						if (!$ref) { continue; }
						$ix['surface'][$ref] = [
							'id'    => $surf['id'] ?? $ref,
							'title' => $surf['title'] ?? '',
							// Where this tab lives, by UUID, so a tombstone can be resurrected
							// back into it. Refs are positional and get reassigned, so they are
							// useless for something read back minutes or days later.
							'workspace_id'  => $ws['id'] ?? null,
							'pane_id'       => $pane['id'] ?? null,
							'index_in_pane' => $surf['index_in_pane'] ?? 0,
						];
					}
				}
			}
		}
		return $ix;
	}

	/**
	 * I/O wrapper for the content-probe fallback (dotfiles-c15). Finds Claude sessions
	 * for which the join had NO bridge at all (no_bridge — neither a resume-script
	 * ancestor nor a CMUX_SURFACE_ID), reads each still-unbound terminal surface's
	 * screen, and upgrades a row to targetable when contentProbeBind() finds it a
	 * unique cwd match.
	 *
	 * Now a genuine last resort: CMUX_SURFACE_ID binds cmux-launched sessions exactly
	 * (dotfiles-dr9), so this fires only for a session cmux never labelled — or one
	 * whose env we could not read. A row that names a closed surface is deliberately
	 * NOT a candidate: it is somewhere else, so a cwd guess would mis-bind it.
	 */
	protected function bindUnresolvedByContentProbe(array $rows, array $debug, array $treeIx): array {
		$bound = [];
		$fresh = [];
		foreach ($rows as $i => $r) {
			if ($r['targetable']) { if ($r['surface_ref'] !== '') { $bound[$r['surface_ref']] = true; } continue; }
			if (!empty($r['no_bridge'])) {
				$r['_i'] = $i;
				$r['tty'] = $this->cmux->getTtyForPid((int) $r['pid']) ?: ($r['tty'] ?? '');
				$fresh[] = $r;
			}
		}
		if (!$fresh) { return $rows; }

		// Candidate surfaces: terminal surfaces (debug-terminals lists only these) not
		// already claimed by a deterministic bind.
		$unbound = [];
		$screenByRef = [];
		foreach ($debug as $ref => $d) {
			if (isset($bound[$ref])) { continue; }
			$unbound[$ref] = ['tty' => $d['tty'] ?? '', 'workspace_ref' => $d['workspace_ref'] ?? ''];
			$screenByRef[$ref] = $this->readLastScreen($ref, $d['workspace_ref'] ?? '', 8);
		}

		$binds = $this->contentProbeBind($fresh, $unbound, $screenByRef);
		foreach ($fresh as $r) {
			$ref = $binds[$r['session_id']] ?? null;
			if (!$ref) { continue; }
			$wref = $unbound[$ref]['workspace_ref'] ?? '';
			$i = $r['_i'];
			$rows[$i]['surface_ref']     = $ref;
			$rows[$i]['surface_id']      = $treeIx['surface'][$ref]['id'] ?? $ref;
			$rows[$i]['workspace_ref']   = $wref;
			$rows[$i]['workspace_title'] = $treeIx['workspace'][$wref] ?? '';
			$rows[$i]['tab_title']       = $treeIx['surface'][$ref]['title'] ?? '';
			$rows[$i]['tty']             = $unbound[$ref]['tty'] ?? '';
			$rows[$i]['targetable']      = true;
			$rows[$i]['reason']          = 'bound via content-probe (fresh session)';
		}
		return $rows;
	}

	/**
	 * Keep the first row for each session_id, preserving order. A single Claude
	 * session can surface under multiple cmux panes/surfaces; liveSessions()
	 * builds one row per surface, so this collapses those back to one per session.
	 */
	public function dedupBySessionId(array $rows): array {
		$seen = [];
		$out  = [];
		foreach ($rows as $row) {
			$id = $row['session_id'] ?? null;
			if ($id !== null && isset($seen[$id])) {
				continue;
			}
			if ($id !== null) {
				$seen[$id] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * PURE. Pick a STABLE workspace group id so re-burying the same workspace keeps
	 * the same resurrect id (and overwrites its manifest in place instead of minting
	 * a fresh one + orphaning the old). Resurrect leaves tombstones in place and
	 * `claude --resume` keeps each session's id, so a resurrected member still carries
	 * its original group_id in its tombstone — we read it back rather than re-minting.
	 *
	 *   $memberSids   the classified members' session ids (this bury).
	 *   $sidToGroup   [session_id => group_id] from surviving tombstones.
	 *   $mintFresh    callable(): string — a new random id (uuidv4), used only when
	 *                 the members map to no single prior group.
	 *
	 * Distinct prior group_ids among the members decide it:
	 *   0 groups → brand-new workspace           → mint fresh.
	 *   1 group  → the common re-bury case        → reuse it (stable id).
	 *   >1 group → sessions from different plots merged into one workspace → treat as a
	 *              genuinely new workspace → mint fresh.
	 */
	public function stableGroupId(array $memberSids, array $sidToGroup, callable $mintFresh): string {
		$priorGroups = [];
		foreach ($memberSids as $sid) {
			$g = $sidToGroup[(string) $sid] ?? null;
			if ($g !== null && $g !== '') { $priorGroups[$g] = true; }
		}
		return count($priorGroups) === 1 ? (string) array_key_first($priorGroups) : $mintFresh();
	}

	/**
	 * PURE. The group_pos a group manifest already reserves for $sessionId, or null
	 * if that session is not one of the manifest's members. The layout slot (position,
	 * pane, geometry) is stamped for EVERY member at the workspace bury — including one
	 * whose own bury later failed — so finishing a half-bury just reuses this pos.
	 */
	public function reservedGroupPos(array $manifest, string $sessionId): ?int {
		foreach ($manifest['layout'] ?? [] as $e) {
			if (($e['claude_session_id'] ?? '') === $sessionId) {
				return isset($e['group_pos']) ? (int) $e['group_pos'] : null;
			}
		}
		return null;
	}

	/**
	 * PURE. The copy-paste command that finishes burying one still-alive session into
	 * its group after a partial workspace bury. Uses 8-char id prefixes (both resolve
	 * by prefix) so the printed line stays short and readable.
	 */
	public function finishBuryCommand(string $sessionId, string $groupId): string {
		return 'graveyard bury ' . substr($sessionId, 0, 8) . ' --group ' . substr($groupId, 0, 8);
	}

	/** Full group ids whose dir-name prefix-matches $ref and carry a manifest (exact match wins alone). */
	public function matchGroupIds(string $ref): array {
		$root = $this->storeRoot() . '/workspaces';
		if ($ref !== '' && is_file($root . '/' . $ref . '/manifest.json')) { return [$ref]; }
		$out = [];
		foreach (glob($root . '/*/manifest.json') ?: [] as $mf) {
			$gid = basename(dirname($mf));
			if ($ref !== '' && strpos($gid, $ref) === 0) { $out[] = $gid; }
		}
		return $out;
	}

	/**
	 * Whether the archived transcript already reflects all genuine activity, so
	 * bury can skip re-exporting. True iff a transcript exists on disk AND no real
	 * (non-synthetic) turn has landed since it was written — i.e. the newest genuine
	 * turn is older than the transcript's mtime. /export appends only synthetic
	 * command turns (ignored by lastRealActivity), so a repeat bury, or a bury right
	 * after a manual /export, costs no export round-trip. A stale skip is still
	 * backstopped by GATE 2, which re-checks recent turns against the kept transcript.
	 */
	public function transcriptUpToDate(string $sessionId, string $cwd): bool {
		$tp = $this->transcriptPath($sessionId);
		if (!is_file($tp)) { return false; }
		$lastReal = $this->cmux->lastRealActivity($sessionId, $cwd);
		if ($lastReal === null) { return true; } // transcript exists, nothing genuine to capture
		clearstatcache(true, $tp);
		return $lastReal < filemtime($tp);
	}

	# =========================================================================
	# Codex bury/resurrect (dotfiles-nvf, schema per dotfiles-51b).
	#
	# Codex does not reuse Claude's gates — it gets stronger ones. Claude's GATE 1
	# scrapes the REPL statusline for a cwd because its session<->surface join is
	# heuristic; codex can be proved outright. The live codex on a surface is
	# identified by CMUX_SURFACE_ID (read from the process's own environment) and
	# its session id by the rollout it holds open — both from the OS, not the
	# screen. So GATE 1 becomes "the codex process on this surface IS this session",
	# and GATE 2 becomes an exact session_meta match on the archived copy instead of
	# fuzzy turn-text matching.
	# =========================================================================

	/** Archive a codex session by copying its rollout. False (never an empty archive) if unavailable. */
	public function archiveCodexRollout(array $sess): bool {
		$sid  = (string) $sess['session_id'];
		$live = $this->cmux->codexRolloutPathFor($sid);
		if ($live === null || !is_file($live) || filesize($live) === 0) {
			return false;
		}

		$dest = $this->codexRolloutArchivePath($sid);
		$dir  = dirname($dest);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }

		// Copy to a temp name then rename, so a partially-copied rollout can never be
		// mistaken for a complete archive by the gate that follows.
		$tmp = $dest . '.tmp';
		if (!@copy($live, $tmp) || filesize($tmp) === 0) {
			@unlink($tmp);
			return false;
		}
		return @rename($tmp, $dest);
	}

	/**
	 * GATE 2 (codex): the archived rollout must BE this session's thread.
	 *
	 * Identity is the file's own session_meta — the LAST one — read via
	 * CodexRollout::selfMeta(), and compared on `id`. The two things that look like
	 * shortcuts here are both wrong:
	 *
	 * - `session_id` on the first record. That is what this gate shipped with, and it names
	 *   the ANCESTOR thread on anything forked or resumed. Codex writes a fresh rollout per
	 *   resume, so it refused every resumed session — bury → resurrect → bury could never
	 *   complete — and reported it as "the archive is not this session's".
	 * - any session_meta whose id matches. A subagent thread's rollout opens with a verbatim
	 *   copy of its PARENT's records, header included, so that would accept a child archived
	 *   under its parent's id. Only the file's own thread counts.
	 */
	public function codexArchiveBelongsToSession(string $path, string $sessionId): bool {
		$id = $this->codexRollout()->selfMeta($path)['id'];
		return $id !== null && $id === $sessionId;
	}

	/** Is the archived rollout at least as new as the live one? */
	public function codexArchiveUpToDate(string $sessionId): bool {
		$archived = $this->codexRolloutArchivePath($sessionId);
		if (!is_file($archived)) { return false; }
		$live = $this->cmux->codexRolloutPathFor($sessionId);
		if ($live === null || !is_file($live)) { return true; } // nothing left to capture
		clearstatcache(true, $archived);
		clearstatcache(true, $live);
		return filemtime($live) <= filemtime($archived);
	}

	/**
	 * [ surface_ref => session_id ] for every live codex bound to a surface.
	 * Public so tests can substitute it without shelling out to cmux/lsof.
	 */
	public function liveCodexBySurfaceRef(): array {
		$out = $this->cmux->joinCodexToSurfaces(
			$this->cmux->loadCodexSessionsByPid(),
			$this->cmux->mapSurfaceUuids($this->cmux->tree())
		);
		$bySurf = [];
		foreach ($out as $r) {
			if (($r['surface_ref'] ?? '') !== '' && !empty($r['session_id'])) {
				$bySurf[$r['surface_ref']] = $r['session_id'];
			}
		}
		return $bySurf;
	}

	/** Surface refs hosting any Codex TUI, including zero-turn sessions with no rollout. */
	public function liveCodexSurfaceRefs(): array {
		$surfaces = $this->cmux->mapSurfaceUuids($this->cmux->tree());
		$out = [];
		foreach ($this->cmux->codexSurfaceIdsByPid() as $surfaceId) {
			$ref = $surfaces[$surfaceId]['surface_ref'] ?? '';
			if ($ref !== '') { $out[$ref] = true; }
		}
		return $out;
	}

	/**
	 * GATE 1 (codex): re-derive, right now, which codex session occupies this surface
	 * and require it to be the one we're about to destroy. Replaces the statusline
	 * heuristic with OS-level proof; a surface hosting something else, or nothing,
	 * fails closed.
	 */
	public function codexSurfaceHostsSession(string $surfaceRef, string $sessionId): bool {
		return ($this->liveCodexBySurfaceRef()[$surfaceRef] ?? null) === $sessionId;
	}

	/** PURE. Which agent a tombstone describes. Archives written before codex support have no kind. */
	public function tombstoneAgent(array $tomb): string {
		return ($tomb['kind'] ?? '') === 'codex' ? 'codex' : 'claude';
	}

	/**
	 * PURE. The one line to say out loud when a codex tombstone cannot restore the
	 * session's sandbox/approval — null when it can, so callers just print what they
	 * get (dotfiles-f1n).
	 *
	 * Two ways in, because the flag is younger than the graveyard: buildTombstone
	 * stamps `agent_opts_unknown` from now on, and every Codex Desktop session buried
	 * BEFORE it existed is recognised by the consequence instead — empty
	 * sandbox+approval, which is exactly what makes `codex resume` fall through to
	 * config.toml. Claude never qualifies: `--resume` rehydrates its own permission
	 * mode, and skip_perms is recorded outright.
	 */
	public function agentOptsUnknownWarning(array $tomb): ?string {
		if ($this->tombstoneAgent($tomb) !== 'codex') { return null; }

		$opts     = is_array($tomb['agent_opts'] ?? null) ? $tomb['agent_opts'] : [];
		$nothing  = ($opts['sandbox'] ?? '') === '' && ($opts['approval'] ?? '') === '';
		if (empty($tomb['agent_opts_unknown']) && !$nothing) { return null; }

		return '  ⚠ sandbox/approval were NOT preserved for this codex session — its rollout '
			. 'recorded no turn_context, so it resumes under whatever ~/.codex/config.toml '
			. 'currently defaults to, not what it was running under.';
	}

	/**
	 * Bury a codex session. Mirrors buryOne's shape but with the codex gates, and
	 * kept as its own method so the Claude path stays byte-identical.
	 */
	protected function buryCodexOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool {
		$sid = (string) $sess['session_id'];
		$id  = substr($sid, 0, 8);

		// GATE 1 (codex): prove this surface really hosts this session.
		if (!$this->codexSurfaceHostsSession((string) $sess['surface_ref'], $sid)) {
			$this->cli->err("  Refusing to bury {$id} (gate 1): {$sess['surface_ref']} does not currently host this codex session — leaving it ALIVE.");
			return false;
		}

		// Busy check. Idle comes from the rollout's last record; the screen regex also
		// catches codex's "Esc to interrupt" working indicator.
		$screen = $this->readLastScreen((string) $sess['surface_ref'], (string) $sess['workspace_ref']);
		if ($this->isBusy((int) $sess['idle_seconds'], self::IDLE_FLOOR_DEFAULT, $screen)) {
			if (!$force) {
				$this->cli->msg("  Skipping {$sess['tab_title']} — session looks busy (use --force to override).", 'yellow');
				return false;
			}
			$this->cli->msg('  Session looks busy but --force given; proceeding.', 'yellow');
		}

		if ($this->codexArchiveUpToDate($sid)) {
			$this->cli->msg("  Rollout already archived for {$id} — skipping copy.", 'cyan');
		} else {
			$this->cli->msg("  Archiving rollout for {$id}…", 'cyan');
			if (!$this->archiveCodexRollout($sess)) {
				$this->cli->err('  Archive failed (no rollout copied) — leaving session ALIVE.');
				return false;
			}
		}

		// GATE 2 (codex): exact — the archived copy must be this session's rollout.
		if (!$this->codexArchiveBelongsToSession($this->codexRolloutArchivePath($sid), $sid)) {
			$this->cli->err("  Refusing to tear down {$id} (gate 2): archived rollout is not this session's — leaving it ALIVE (archive kept for inspection).");
			return false;
		}

		$summary = $this->deriveSummary($sess);
		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'), $group);
		file_put_contents($this->metaPath($sid), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		// Render the transcript now so a just-buried codex session is immediately greppable
		// by `search --full-text` and openable by `show`. A render failure is NOT a bury
		// failure: the lossless rollout is already archived and gates 2/3 have passed, and
		// ensureTranscript() will retry on the next read anyway.
		try { $this->ensureTranscript($tomb); } catch (\Throwable) {
			$this->cli->msg('  Could not render the rollout as a transcript — the raw rollout is archived.', 'yellow');
		}
		$this->cli->successMsg($this->ellipsizeText('  Buried [codex]: ' . $this->cleanSummaryText($summary, getenv('HOME') ?: ''), $this->termWidth()));
		// Say it at burial too, not only at resurrect: this is the moment the archive's
		// limits are decided, and it is the last moment the live session could be asked.
		if ($warn = $this->agentOptsUnknownWarning($tomb)) { $this->cli->msg($warn, 'yellow'); }

		if (!$autoConfirm && !$this->cli->confirm('  Close the cmux tab and kill this session now?')) {
			$this->cli->msg('  Left the tab open; rollout is archived.', 'yellow');
			return true;
		}

		$ok = $deferClose ? $this->killMember($sess) : $this->teardown($sess);
		if ($ok) {
			$this->cli->msg('  Process terminated — RAM freed.', 'green');
		} else {
			$this->cli->err('  Archived, but could not terminate the live session automatically — kill it manually (rollout is safe).');
		}
		return true;
	}

	/**
	 * Archive the session's transcript. Prefers export-session.mjs (reads the session
	 * JSONL straight off disk: works on a dead session, ~100ms, appends nothing to the
	 * target, mutates nothing) and falls back to typing /export into the live REPL when
	 * that binary is absent — or present but broken, which must not cost graveyard a
	 * capability it had before this seam existed.
	 *
	 * Either way GATE 2 (bury, post-export) re-checks the written transcript against the
	 * session's recent genuine turns before anything destructive happens.
	 */
	public function exportTranscript(array $sess, int $timeoutSecs = 30): bool {
		$bin = $this->exportBinPath();
		if ($bin !== '') {
			if ($this->exportTranscriptViaBin($sess, $bin)) { return true; }
			$this->cli->msg('  export-session.mjs produced nothing — falling back to typing /export into the REPL.', 'yellow');
		}
		return $this->exportTranscriptViaRepl($sess, $timeoutSecs);
	}

	/**
	 * Resolved path to export-session.mjs, or '' when it is not usable here (caller
	 * falls back to the REPL). GRAVEYARD_EXPORT_BIN overrides ABSOLUTELY — a set-but-
	 * missing override resolves to '' rather than quietly running the machine's install,
	 * which is what makes both branches of exportTranscript() unit-testable (same seam
	 * shape as GODO_DIRMAP_BIN in src/Godo.php).
	 *
	 * Otherwise: the claude-plugins working checkout first (that is the copy JT edits and
	 * the one Claude Code loads from), then any marketplace-installed session-tools cache,
	 * newest version dir first. All $HOME-relative, so this resolves on Linux too.
	 */
	public function exportBinPath(): string {
		$env = getenv('GRAVEYARD_EXPORT_BIN');
		if ($env !== false && $env !== '') {
			// Escape hatch: GRAVEYARD_EXPORT_BIN=off pins bury back to Claude Code's own
			// /export renderer without a code change. The archive-fidelity gap that first
			// motivated it (dropped slash-command prompts and tool output, dotfiles-6bx) is
			// fixed upstream, so the remaining difference is FORM: the binary emits markdown
			// source (transcript.md), /export emits rendered TUI text (transcript.txt).
			// Checked before the filesystem so the token means "off" even if a file by that
			// name happens to exist.
			if (in_array(strtolower($env), ['off', '0', 'none', 'false', 'repl'], true)) { return ''; }
			return $this->usableExportBin($env) ? $env : '';
		}

		$home  = getenv('HOME') ?: '';
		$leaf  = '/scripts/export-session.mjs';
		$cands = [$home . '/Code/claude-plugins/plugins/session-tools' . $leaf];
		$cache = glob($home . '/.claude/plugins/cache/*/session-tools/*' . $leaf) ?: [];
		rsort($cache);

		foreach (array_merge($cands, $cache) as $cand) {
			if ($this->usableExportBin($cand)) { return $cand; }
		}
		return '';
	}

	protected function usableExportBin(string $path): bool {
		return $path !== '' && is_file($path) && is_executable($path);
	}

	/**
	 * PURE. The export-session.mjs invocation graveyard wants.
	 *
	 * `--format md` is the full-fidelity renderer: every turn, no window, no per-turn
	 * character cap. The digest formats window recent turns and clip turn text, and
	 * graveyard is writing a PERMANENT archive that GATE 2 then matches against the tail
	 * of the session's genuine turns — so no --window/--truncate/--max-chars/--fast here.
	 * `--no-beads` skips a `bd show` round-trip whose output the md renderer never emits.
	 * `--cwd` only disambiguates name lookups; the full session id resolves ahead of it.
	 */
	public function exportBinCommand(string $bin, string $sessionId, string $cwd): string {
		$cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($sessionId) . ' --format md --no-beads';
		if ($cwd !== '') { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		return $cmd;
	}

	/**
	 * I/O. Render the transcript with export-session.mjs and land it atomically.
	 *
	 * A non-zero exit (no such session, ambiguous id, parse failure) or empty output is a
	 * FAILURE, not an empty archive: bury would otherwise tear a session down against a
	 * transcript with no turns in it.
	 */
	public function exportTranscriptViaBin(array $sess, string $bin): bool {
		$id  = (string) $sess['session_id'];
		$cwd = (string) ($sess['cwd'] ?? '');

		$out  = [];
		$code = 0;
		exec($this->exportBinCommand($bin, $id, $cwd) . ' 2>/dev/null', $out, $code);
		if ($code !== 0) { return false; }

		$text = trim(implode("\n", $out));
		if ($text === '') { return false; }

		$tmp = $this->transcriptTmpPath($sess);
		if (file_put_contents($tmp, $text . "\n") === false) { return false; }
		if (@rename($tmp, $this->transcriptMdPath($id))) {
			$this->dropSupersededArchive($this->transcriptTxtPath($id));
			return true;
		}
		@unlink($tmp);
		return false;
	}

	/**
	 * One archive per session, named for the renderer that wrote it. Called only AFTER the
	 * fresh export is safely in place, to remove the other renderer's file.
	 *
	 * Not housekeeping — correctness. transcriptPath() prefers .md, so an /export-rendered
	 * .txt landing next to a surviving .md would leave every reader (GATE 2 included) on
	 * the STALE file. Legacy archives are untouched: nothing re-exports them.
	 */
	protected function dropSupersededArchive(string $path): void {
		if (is_file($path)) { @unlink($path); }
	}

	/** The temp file an in-flight export writes before it is renamed into place. */
	protected function transcriptTmpPath(array $sess): string {
		$dir = $this->sessionDir((string) $sess['session_id']);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		$tmp = $dir . '/.transcript.' . ($sess['pid'] ?? getmypid()) . '.tmp';
		if (file_exists($tmp)) { @unlink($tmp); }
		return $tmp;
	}

	/**
	 * Legacy path: drive Claude Code's own /export by typing it into the live REPL and
	 * polling for the file to stop growing. Needs the session alive in a pane, appends a
	 * synthetic turn to it, and costs up to $timeoutSecs. Kept as the fallback for when
	 * export-session.mjs is unavailable.
	 */
	public function exportTranscriptViaRepl(array $sess, int $timeoutSecs = 30): bool {
		$id  = $sess['session_id'];
		$tmp = $this->transcriptTmpPath($sess);

		$this->sendExportCommand($sess, $tmp);

		$deadline = time() + $timeoutSecs;
		$lastSize = -1;
		while (time() < $deadline) {
			clearstatcache(true, $tmp);
			if (is_file($tmp)) {
				$size = filesize($tmp);
				if ($size > 0 && $size === $lastSize) {
					if (!@rename($tmp, $this->transcriptTxtPath($id))) { return false; }
					$this->dropSupersededArchive($this->transcriptMdPath($id));
					return true;
				}
				$lastSize = $size;
			}
			usleep(400000);
		}
		@unlink($tmp);
		return false;
	}

	/**
	 * Type "/export <tmp>" into the target REPL's prompt and submit it.
	 *
	 * Clears any half-typed text already in the prompt box FIRST. Without this a
	 * leftover fragment gets prepended — a stray "for t" turns "/export …" into
	 * "for t/export …", which Claude Code cannot parse, so the export silently
	 * never runs. The session is gate-1 + busy-checked idle before we reach here,
	 * so the clearing Ctrl-C only nukes the input buffer (it never interrupts a
	 * live turn). The clear rides the text `send` path, not send-key: `cmux
	 * send-key ctrl+c` does not reach Claude Code's REPL as a working interrupt.
	 *
	 * Inside a running Claude Code TUI a sent "\n" only inserts a newline in the
	 * prompt — it does not submit. So send the command text, then press Return as
	 * a real key event to submit it.
	 */
	public function sendExportCommand(array $sess, string $tmp): void {
		$this->cmux->sendToSurface($sess['surface_ref'], $sess['workspace_ref'], self::CLEAR_PROMPT);
		$this->cmux->sendToSurface($sess['surface_ref'], $sess['workspace_ref'], '/export ' . $tmp);
		$this->cmux->sendKeyToSurface($sess['surface_ref'], $sess['workspace_ref'], 'Return');
	}

	/**
	 * I/O. Ask a Claude REPL who it is by sending /status and reading the identity modal
	 * it prints ("Session ID: <uuid>" + "cwd: <path>"). Returns parseStatusProbe()'s
	 * result (['session_id'=>..,'cwd'=>..]) or null on timeout / no id.
	 *
	 * This is the last-resort bind for a surface the statusline content-probe cannot pin
	 * to a session (fresh/non-resumed sessions that cd'd: on-screen cwd drifted from the
	 * recorded launch cwd, and the drifted cwd collides with other sessions). /status
	 * bypasses both cwd-drift and tty-sharing — it prints the definitive identity.
	 *
	 * The prompt is cleared first (same reason as /export: a half-typed fragment would
	 * corrupt the command). /status opens a MODAL ("Esc to cancel"); we ALWAYS send Escape
	 * afterward — success or timeout — so the REPL is left clean for the /export that
	 * follows. Interactive + adds a turn, so it lives only in the bury path, never in the
	 * liveSessions()/ls hot path.
	 */
	public function probeSurfaceIdentity(string $surfaceRef, string $wsRef, int $timeoutSeconds = 6): ?array {
		$this->cmux->sendToSurface($surfaceRef, $wsRef, self::CLEAR_PROMPT);
		$this->cmux->sendToSurface($surfaceRef, $wsRef, '/status');
		$this->cmux->sendKeyToSurface($surfaceRef, $wsRef, 'Return');

		$found    = null;
		$deadline = time() + max(1, $timeoutSeconds);
		while (time() < $deadline) {
			usleep(400000); // 0.4s — let the modal render before reading
			$probe = $this->parseStatusProbe($this->readLastScreen($surfaceRef, $wsRef, 40));
			if ($probe) { $found = $probe; break; }
		}

		$this->cmux->sendKeyToSurface($surfaceRef, $wsRef, 'Escape'); // dismiss modal (always)
		return $found;
	}

	/**
	 * PURE: reduce a raw user-message body to a short human summary, or '' if the
	 * message is noise (empty, a tool/skill wrapper, a local-command dump).
	 *
	 * A slash-command invocation — which Claude Code records as
	 * "<command-message>..</command-message><command-name>/foo</command-name>"
	 * (optionally with <command-args>) — collapses to "/foo [args]" rather than
	 * leaking the raw tags into the tombstone summary.
	 */
	public function summarizeUserText(string $raw): string {
		$text = trim($raw);
		if ($text === '') { return ''; }

		// Slash-command invocation → "/command-name [args]".
		if (preg_match('#<command-name>\s*/?([^<]+?)\s*</command-name>#i', $text, $m)) {
			$cmd = '/' . trim($m[1]);
			if (preg_match('#<command-args>\s*(.*?)\s*</command-args>#is', $text, $a) && trim($a[1]) !== '') {
				$cmd .= ' ' . trim($a[1]);
			}
			return trim(mb_substr(preg_replace('/\s+/', ' ', $cmd), 0, 100));
		}

		// Machine-generated noise that isn't a human prompt — skip to the next entry.
		// Wrapper tags mirror Cmux::isSyntheticEntry (incl. <local-command-caveat>, the
		// one whose omission let a "Caveat: …" turn become a tombstone summary); the
		// plain-text prefixes catch the same content after tag-stripping.
		$noisePrefixes = [
			'<command-message>', '<command-args>', '<local-command-stdout>', '<local-command-caveat>',
			'Base directory for this skill:', 'Caveat: The messages below',
		];
		foreach ($noisePrefixes as $prefix) {
			if (stripos($text, $prefix) === 0) { return ''; }
		}

		// Strip any stray tags, collapse whitespace.
		$clean = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $text)));
		if ($clean === '') { return ''; }

		return mb_substr($clean, 0, 100);
	}

	/**
	 * PURE. Which agent a row belongs to, for rows of EITHER shape.
	 *
	 * A live session row carries `agent` while a tombstone carries `kind`, and code that
	 * reads both (deriveSummary, called at bury time with a live row and by repair with a
	 * tombstone) needs one answer. tombstoneAgent() stays the tombstone-only reader it was.
	 */
	public function rowAgent(array $row): string {
		if (($row['agent'] ?? '') === 'codex' || ($row['kind'] ?? '') === 'codex') { return 'codex'; }
		return 'claude';
	}

	/** The codex rollout reader, held so its per-file parse cache survives across views. */
	protected function codexRollout(): Helpers\CodexRollout {
		return $this->codexRollout ??= new Helpers\CodexRollout();
	}

	/**
	 * The best rollout to READ for a codex session: the live one if it still exists, else
	 * the archived copy, else ''.
	 *
	 * Live first because bury derives a summary while the session is still running, and the
	 * live file is the one with the newest turns in it. Archive second so everything keeps
	 * working after the session is gone — which is the whole point of archiving it.
	 */
	public function codexRolloutReadPath(string $sessionId): string {
		// NullCmux answers null here, so the page server skips the live lookup and reads
		// the archived copy without a special-case null guard.
		$live = $this->cmux->codexRolloutPathFor($sessionId);
		if ($live !== null && is_file($live)) { return $live; }
		$archived = $this->codexRolloutArchivePath($sessionId);
		return is_file($archived) ? $archived : '';
	}

	public function deriveSummary(array $sess): string {
		// Codex keeps its prompts in a rollout, not a Claude jsonl, so reading the jsonl
		// path found nothing and every codex headstone fell through to its tab title —
		// which is a DIRECTORY name. The live store's one codex tombstone reads
		// ".dotfiles" where its opening prompt was "/system-watchdog".
		//
		// The text is handed to summarizeUserText() unchanged rather than pre-cleaned: that
		// method is what turns a "<command-name>/foo</command-name>" wrapper into "/foo",
		// so a slash-command session names itself the same way for both agents.
		if ($this->rowAgent($sess) === 'codex') {
			$rollout = $this->codexRolloutReadPath((string) $sess['session_id']);
			if ($rollout !== '') {
				$summary = $this->summarizeUserText($this->codexRollout()->firstUserText($rollout));
				if ($summary !== '') { return $summary; }
			}
			return $this->summaryFallback($sess);
		}

		$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if (($e['type'] ?? '') === 'user') {
					$content = $e['message']['content'] ?? '';
					if (is_array($content)) {
						$text = '';
						foreach ($content as $c) { if (($c['type'] ?? '') === 'text') { $text = $c['text']; break; } }
						$content = $text;
					}
					if (is_string($content)) {
						$summary = $this->summarizeUserText($content);
						if ($summary !== '') {
							fclose($fh);
							return $summary;
						}
					}
				}
			}
			fclose($fh);
		}
		return $this->summaryFallback($sess);
	}

	/** PURE. The last resort for a summary: the tab title, minus the REPL's status glyph. */
	protected function summaryFallback(array $sess): string {
		$title = $sess['tab_title'] ?? '';
		// strip Claude Code's leading status glyph
		return trim(preg_replace('/^[^\x00-\x7F]+\s*/u', '', $title)) ?: '(no summary)';
	}

	/**
	 * PURE. The cwd token from a Claude REPL statusline ("📁 /foo"), or null if none.
	 * The cwd MAY CONTAIN SPACES ("/Southport UDO"), so capture the whole field after
	 * the 📁 glyph up to the next status separator (| or │) or end of line — never
	 * stop at the first space (that was the phase-1-family bug: '/Southport UDO' →
	 * '/Southport', making every spaced-path session fail gate 1).
	 */
	public function extractStatuslineCwd(string $screen): ?string {
		if (!preg_match('/📁\s*([^|│\x{2502}\n]+)/u', $screen, $m)) { return null; }
		$tok = trim($m[1]);
		return $tok === '' ? null : $tok;
	}

	/**
	 * PURE. Parse the identity block a Claude REPL prints in response to /status
	 * ("Session ID:  <uuid>" + "cwd:  <path>"). Returns ['session_id'=>.., 'cwd'=>..]
	 * when a session id is found (cwd is '' if its line is absent), or [] when no
	 * session id is present (the probe is useless without it). The cwd MAY CONTAIN
	 * SPACES, so capture the whole field to end of line — same rule as
	 * extractStatuslineCwd(). This bypasses the cwd-drift / tty-sharing that make a
	 * fresh (non-resumed) session's surface unbindable by the statusline content-probe.
	 */
	public function parseStatusProbe(string $screen): array {
		if (!preg_match('/Session ID:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $screen, $m)) {
			return [];
		}
		$cwd = '';
		if (preg_match('/\bcwd:\s*(.+)/i', $screen, $c)) {
			$cwd = trim($c[1]);
		}
		return ['session_id' => strtolower($m[1]), 'cwd' => $cwd];
	}

	/**
	 * PURE. Build a targetable liveSessions()-shaped row for a Claude surface bound via
	 * the /status probe (parseStatusProbe), so classifyWorkspaceLayout treats it as a
	 * member and buryOne can act on it. Returns null when the probe carried no session id
	 * or the id is not tracked by any live pid file (nothing to bury).
	 *
	 * $sessionsByPid is loadClaudeSessionsByPid() output (keyed by pid). The row's cwd is
	 * the session file's LAUNCH cwd — NOT the probe's current cwd — because every
	 * JSONL-based step downstream (gate 2 needles, deriveSummary, last_active, and
	 * resurrect's `claude --resume`) resolves ~/.claude/projects/<encoded-cwd>/<sid>.jsonl
	 * by the launch cwd; the drifted current cwd points at the wrong project dir. The
	 * mismatch the probe defeats (statusline shows current cwd) is instead handled by
	 * passesPreExportGate(), which bypasses gate 1 for _probed rows. The pid comes from the
	 * session file that records this session id, so killMember's gate 3 (sessionIdForPid
	 * === target) still holds. Marked _probed so buryWorkspace's member loop uses this row
	 * directly instead of re-resolving via liveSessions() (which cannot bind a fresh/drifted
	 * session — the whole reason we probed).
	 */
	public function synthesizeProbedRow(string $ref, string $wsRef, array $probe, array $sessionsByPid, array $treeIx, int $idleSeconds = PHP_INT_MAX): ?array {
		$sid = $probe['session_id'] ?? '';
		if ($sid === '') { return null; }

		$pid = null; $meta = null;
		foreach ($sessionsByPid as $p => $s) {
			if (($s['session_id'] ?? '') === $sid) { $pid = (int) $p; $meta = $s; break; }
		}
		if ($meta === null) { return null; }

		$cwd = (string) ($meta['cwd'] ?? '');

		return [
			'session_id'      => $sid,
			'cwd'             => $cwd,
			'model'           => $meta['model'] ?? null,
			'skip_perms'      => $meta['skip_perms'] ?? null,
			'pid'             => $pid,
			'tty'             => '',
			'surface_ref'     => $ref,
			'surface_id'      => $treeIx['surface'][$ref]['id'] ?? $ref,
			'workspace_ref'   => $wsRef,
			'workspace_title' => $treeIx['workspace'][$wsRef] ?? '',
			'tab_title'       => $treeIx['surface'][$ref]['title'] ?? '',
			'idle_seconds'    => $idleSeconds,
			'targetable'      => true,
			'reason'          => 'bound via /status probe',
			'_probed'         => true,
		];
	}

	/**
	 * PURE. Split a path into its non-empty components, dropping a leading ~ and any
	 * elision markers (…), so an abbreviated statusline path can be compared by its
	 * trailing components. "~/Documents/Southport UDO" → [Documents, Southport UDO];
	 * "…/Southport UDO" → [Southport UDO]; "/a/b" → [a, b].
	 */
	public function pathTailComponents(string $path): array {
		$out = [];
		foreach (preg_split('#/+#', trim($path)) as $p) {
			$p = trim($p);
			if ($p === '' || $p === '~' || $p === '…' || $p === '...') { continue; }
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * PURE. GATE 1 predicate: does the on-screen Claude statusline's cwd correspond to
	 * $sessionCwd? The statusline abbreviates (leading-component elision, ~-home, or
	 * just a trailing slice), so we match the statusline token's components as a
	 * TRAILING slice of the session cwd's components — robust to spaces, ~, and elision.
	 * Returns false when no statusline is found (surface is not a Claude REPL) — blocks.
	 */
	public function statuslineMatchesSession(string $screen, string $sessionCwd): bool {
		$tok = $this->extractStatuslineCwd($screen);
		if ($tok === null || $sessionCwd === '') { return false; }
		$tokComps  = $this->pathTailComponents($tok);
		$sessComps = $this->pathTailComponents($sessionCwd);
		$n = count($tokComps);
		if ($n === 0 || $n > count($sessComps)) { return false; }
		return array_slice($sessComps, -$n) === $tokComps;
	}

	/**
	 * PURE. GATE 1 decision for buryOne: may we type /export into this surface? For a
	 * normal row this is the statusline-cwd heuristic (statuslineMatchesSession). A
	 * _probed row is exempt: the /status probe already read the surface's own Session ID
	 * back, which is a STRONGER surface↔session proof than matching an abbreviated,
	 * cwd-drifting statusline — and the row deliberately carries the launch cwd (for JSONL
	 * resolution), which would fail the heuristic against the drifted on-screen cwd.
	 */
	public function passesPreExportGate(array $sess, string $screen): bool {
		if (!empty($sess['_probed'])) { return true; }
		return $this->statuslineMatchesSession($screen, (string) ($sess['cwd'] ?? ''));
	}

	/**
	 * PURE. Pick the row buryWorkspace's member loop should actually bury. A _probed
	 * member MUST use its synthesized (targetable) row: re-resolving via liveSessions()
	 * returns at best the untargetable row that triggered the probe (a fresh/drifted
	 * session IS present in liveSessions(), just as targetable=false / "no resume-script
	 * ancestor"), which buryOne's gate 0 would reject. A normal member uses the freshly
	 * re-resolved row ($resolved), which may be null if the session vanished after classify.
	 */
	public function memberRowToBury(array $member, ?array $resolved): ?array {
		return !empty($member['_probed']) ? $member : $resolved;
	}

	/**
	 * PURE. Content-probe fallback binding (dotfiles-c15). For Claude sessions the
	 * ancestry join could not bind (fresh / non-cmux-resumed), match each to a still-
	 * unbound terminal surface by reading its on-screen statusline cwd. A session binds
	 * only when EXACTLY ONE unclaimed surface matches its cwd (ties broken by OS tty);
	 * anything ambiguous stays unbound — never a guess.
	 *
	 * @param array $freshRows        rows to try to bind: [session_id, cwd, tty]
	 * @param array $unboundSurfaces  [surface_ref => ['tty'=>debug_tty,'workspace_ref'=>..]]
	 * @param array $screenByRef      [surface_ref => last-screen text]
	 * @return array [session_id => surface_ref] for unambiguous binds
	 */
	public function contentProbeBind(array $freshRows, array $unboundSurfaces, array $screenByRef): array {
		$binds = [];
		$claimed = [];
		foreach ($freshRows as $r) {
			$cands = [];
			foreach (array_keys($unboundSurfaces) as $ref) {
				if (isset($claimed[$ref])) { continue; }
				if ($this->statuslineMatchesSession($screenByRef[$ref] ?? '', (string) ($r['cwd'] ?? ''))) {
					$cands[] = $ref;
				}
			}
			if (count($cands) > 1 && !empty($r['tty'])) {
				$tied = array_values(array_filter($cands, fn($ref) => ($unboundSurfaces[$ref]['tty'] ?? '') === $r['tty']));
				if (count($tied) === 1) { $cands = $tied; }
			}
			if (count($cands) === 1) {
				$binds[$r['session_id']] = $cands[0];
				$claimed[$cands[0]] = true;
			}
		}
		return $binds;
	}

	/**
	 * PURE. GATE 2 predicate: does an exported transcript belong to the target? Assert
	 * the session's first meaningful prompt appears in the rendered transcript text
	 * (whitespace-insensitive). Empty needle → cannot assert → do not block.
	 *
	 * Needles come from genuineTurns(), which reads the raw JSONL and keeps inline
	 * markdown (**bold**, `code`, _em_). Claude Code's /export STRIPS those markers
	 * from the rendered transcript, so a literal substring test false-negatives on
	 * any turn that leads with formatting. Normalize markdown on both sides at compare
	 * time only — genuineTurns()/peek/tombstone output stay untouched.
	 */
	public function transcriptMatchesSession(string $transcriptText, string $firstPrompt): bool {
		$demark = fn(string $s): string => preg_replace('/[`*_]/', '', preg_replace('/\s+/', ' ', $s));
		$needle = trim($demark(mb_substr($firstPrompt, 0, 60)));
		if ($needle === '') { return true; }
		return mb_strpos($demark($transcriptText), $needle) !== false;
	}

	public function teardown(array $sess): bool {
		$killed = $this->killMember($sess);
		if ($killed) { $this->closeSurfaceOrWorkspace($sess); }
		return $killed;
	}

	/**
	 * GATE 3 + kill. Only kill a pid whose ~/.claude/sessions/<pid>.json sessionId
	 * equals the target. Never kill by surface/tty membership — recycled ttys mean a
	 * tty can host a different live session. Last-line defense that makes a wrong join
	 * non-destructive. Returns true iff the verified claude pid (and descendants) died.
	 */
	protected function killMember(array $sess): bool {
		$target = $sess['session_id'] ?? '';
		$pid    = (int) ($sess['pid'] ?? 0);
		if ($pid <= 0) {
			$this->cli->err('  Teardown aborted: no resolved pid for this session — leaving it ALIVE.');
			return false;
		}
		// GATE 3: the pid must still map to the target session. Claude publishes that in
		// ~/.claude/sessions/<pid>.json; a codex process instead holds its rollout open,
		// whose filename carries the session id — plus one per subagent thread it spawned,
		// so the pid's OWN thread has to be picked out (codexSessionIdForPid). Reading
		// "the first open rollout" aborted teardown of healthy sessions with
		// "pid N maps to session <a subagent>".
		$pidSid = ($sess['agent'] ?? 'claude') === 'codex'
			? $this->cmux->codexSessionIdForPid($pid)
			: $this->cmux->sessionIdForPid($pid);
		if ($pidSid !== $target) {
			$this->cli->err("  Teardown aborted (gate 3): pid {$pid} maps to session " . substr((string) $pidSid, 0, 8) . ", not target " . substr($target, 0, 8) . " — leaving it ALIVE.");
			return false;
		}
		return $this->killPidTree($pid);
	}

	/** Close a single member's surface, or the workspace if it's the last surface. */
	protected function closeSurfaceOrWorkspace(array $sess): void {
		$wsRef = $sess['workspace_ref'] ?? '';
		$count = $wsRef ? $this->cmux->workspaceSurfaceCount($wsRef) : 0;
		$bin = escapeshellcmd($this->cmux->cmuxBin());
		$cmd = ($count <= 1)
			? $bin . ' workspace close ' . escapeshellarg($wsRef)
			: $bin . ' close-surface --surface ' . escapeshellarg($sess['surface_ref']);
		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if (($res['exitCode'] ?? 1) !== 0) {
			$this->cli->msg('  (Process terminated, but the now-empty cmux tab lingered — close it manually.)', 'yellow');
		}
	}

	/** Close an entire workspace (and every remaining surface in it). */
	protected function closeWorkspace(string $wsRef): bool {
		if ($wsRef === '') { return false; }
		$res = $this->cli->getCommandOutputAndExitCode(escapeshellcmd($this->cmux->cmuxBin()) . ' workspace close ' . escapeshellarg($wsRef));
		return ($res['exitCode'] ?? 1) === 0;
	}

	/** Kill $pid and its descendants (SIGTERM, then SIGKILL survivors). True if $pid is dead after. */
	protected function killPidTree(int $pid): bool {
		if ($pid <= 0) { return false; }
		$proc = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$pids = $this->cmux->descendantPids($proc, $pid); // $pid + subagents
		$this->signalPids($pids, defined('SIGTERM') ? SIGTERM : 15);
		$deadline = time() + 3;
		while (time() < $deadline && $this->cmux->pidIsAlive($pid)) { usleep(200000); }
		if ($this->cmux->pidIsAlive($pid)) {
			$proc = $this->cmux->parseProcTable($this->cmux->psProcTable());
			$this->signalPids($this->cmux->descendantPids($proc, $pid), 9);
		}
		return !$this->cmux->pidIsAlive($pid);
	}

	protected function signalPids(array $pids, int $signal): void {
		foreach ($pids as $pid) {
			if (function_exists('posix_kill')) { posix_kill((int) $pid, $signal); }
			else { $this->cli->runCommand('kill -' . $signal . ' ' . (int) $pid); }
		}
	}

	public function buryOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool {
		$id      = substr((string) $sess['session_id'], 0, 8);
		$idFloor = self::IDLE_FLOOR_DEFAULT;

		// GATE 0a: the agent must be one we have gates for. Claude and codex each have
		// their own set — the gates below are Claude-shaped (GATE 1 matches the Claude
		// REPL statusline cwd, the busy check greps a Claude active-turn spinner, the
		// /status probe reads a Claude Session ID off the screen), and running any other
		// agent through them would sail past the very checks that exist to stop us
		// destroying the wrong session, then kill a process tree and close a surface.
		// Ahead of everything else so a refusal reads no screen and never types into a
		// surface.
		$agent = $sess['agent'] ?? 'claude';
		if ($agent !== 'claude' && $agent !== 'codex') {
			$this->cli->err("  Refusing to bury {$id}: unknown agent '{$agent}' — no gates exist for it. Close it by hand, or leave it running.");
			return false;
		}

		// Refuse sessions the join could not bind to a single surface with confidence.
		// --force does NOT override this: an unresolved surface_ref means we don't know
		// which tab we'd act on, and guessing risks clobbering a different live session.
		if (!($sess['targetable'] ?? false)) {
			$this->cli->err("  Refusing to bury {$id}: untargetable — " . ($sess['reason'] ?: 'ambiguous session↔surface mapping') . '. Resolve it first.');
			return false;
		}

		// Codex has its own gates (stronger than the statusline heuristic below) and its
		// own archiving (a rollout copy, not /export typed into a REPL). Split here so
		// the Claude path stays exactly as it was.
		if ($agent === 'codex') {
			return $this->buryCodexOne($sess, $force, $autoConfirm, $group, $deferClose);
		}

		$screen = $this->readLastScreen($sess['surface_ref'], $sess['workspace_ref']);

		// GATE 1 (pre-export): the resolved surface must actually be showing THIS
		// session's idle Claude REPL. A statusline cwd mismatch means the join pointed
		// at the wrong tab; abort before typing /export into someone else's session.
		// A /status-probed row is exempt — the probe already read this surface's Session
		// ID back, a stronger proof than the drift-prone statusline heuristic.
		if (!$this->passesPreExportGate($sess, $screen)) {
			$onscreen = $this->extractStatuslineCwd($screen);
			$this->cli->err("  Refusing to bury {$id} (gate 1): resolved surface shows "
				. ($onscreen !== null ? "cwd '{$onscreen}'" : 'no Claude REPL statusline')
				. ", not session cwd '{$sess['cwd']}' — leaving it ALIVE.");
			return false;
		}

		if ($this->isBusy((int) $sess['idle_seconds'], $idFloor, $screen)) {
			if (!$force) {
				$this->cli->msg("  Skipping {$sess['tab_title']} — session looks busy (use --force to override).", 'yellow');
				return false;
			}
			$this->cli->msg('  Session looks busy but --force given; proceeding.', 'yellow');
		}

		if ($this->transcriptUpToDate((string) $sess['session_id'], (string) ($sess['cwd'] ?? ''))) {
			$this->cli->msg("  Transcript already current for {$id} — skipping export.", 'cyan');
		} else {
			$this->cli->msg("  Exporting transcript for {$id}…", 'cyan');
			if (!$this->exportTranscript($sess)) {
				$this->cli->err("  Export failed (no transcript written) — leaving session ALIVE.");
				return false;
			}
		}

		// GATE 2 (post-export, pre-teardown): confirm the exported transcript actually
		// belongs to the target before anything destructive. Match on the session's
		// RECENT genuine turns (tag-stripped) rather than its first turn: /export renders
		// only the post-compaction / current-bridge conversation, and opening turns are
		// often machine caveats or skill preambles absent from the export — so the tail is
		// the reliable anchor. The transcript is already safe on disk, so a mismatch aborts
		// teardown (session left ALIVE).
		$exported = (string) @file_get_contents($this->transcriptPath($sess['session_id']));
		$needles  = $this->recentTurnNeedles((string) $sess['session_id'], (string) $sess['cwd']);
		if (!$this->transcriptBelongsToSession($exported, $needles)) {
			$this->cli->err("  Refusing to tear down {$id} (gate 2): exported transcript does not match this session's recent turns — leaving it ALIVE (transcript kept for inspection).");
			return false;
		}

		$summary = $this->deriveSummary($sess);
		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'), $group);
		file_put_contents($this->metaPath($sess['session_id']), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		$this->cli->successMsg($this->ellipsizeText("  Buried: " . $this->cleanSummaryText($summary, getenv('HOME') ?: ''), $this->termWidth()));

		if (!$autoConfirm && !$this->cli->confirm("  Close the cmux tab and kill this session now?")) {
			$this->cli->msg('  Left the tab open; transcript is archived.', 'yellow');
			return true;
		}

		// In a grouped workspace bury we kill each member here but defer closing to a
		// single workspace-level close (which also sweeps the non-claude surfaces).
		if ($deferClose) {
			if ($this->killMember($sess)) {
				$this->cli->msg('  Process terminated — RAM freed.', 'green');
			} else {
				$this->cli->err('  Archived, but could not terminate the live session automatically — kill it manually (transcript is safe).');
			}
			return true;
		}

		if ($this->teardown($sess)) {
			$this->cli->msg('  Process terminated — RAM freed.', 'green');
		} else {
			$this->cli->err('  Archived, but could not terminate the live session automatically — kill it manually (transcript is safe).');
		}
		return true;
	}

	// =========================================================================
	// Workspace-level (grouped) bury  (dotfiles-c8a)
	// =========================================================================

	/**
	 * PURE. Classify a workspace's surfaces into a layout + member lists.
	 *
	 * Inputs (all injectable for testing):
	 *   $wsNode        cmux tree workspace node (panes[].surfaces[])
	 *   $liveByRef     [surface_ref => liveSessions row] for TARGETABLE claude rows
	 *   $isClaudeByRef [surface_ref => bool]  content-probe result (has a Claude REPL
	 *                  statusline). A shell has no statusline. Reliable for fresh+resumed.
	 *   $isCodexByRef  [surface_ref => bool]  a live codex is bound to this surface (from
	 *                  liveCodexBySurfaceRef(), targetable or not). Codex runs in a plain
	 *                  'terminal' with no Claude statusline, so without this signal it
	 *                  looks like a shell and a workspace bury CLOSES it unarchived
	 *                  (dotfiles-5p5, data loss).
	 *   $cwdByRef      [surface_ref => cwd] cwd probes for plain terminal surfaces.
	 *   $unboundByRef  [surface_ref => liveSessions row] for non-targetable join rows.
	 *
	 * Returns:
	 *   'layout'      ordered list of surface entries with position + type + title +
	 *                 cwd/url + claude_session_id (for members) — the manifest body.
	 *   'members'     targetable agent liveSessions rows (claude OR codex) to bury, with
	 *                 group_pos set. buryOne() dispatches each to its agent's bury path.
	 *   'untargetable' agent surfaces detected but not bound to a targetable row
	 *                 (fresh/ambiguous) — presence forces abort unless --force. Each
	 *                 carries its 'agent' so the abort report can explain it correctly.
	 */
	public function classifyWorkspaceLayout(array $wsNode, array $liveByRef, array $isClaudeByRef, array $isCodexByRef = [], array $cwdByRef = [], array $unboundByRef = []): array {
		$layout = [];
		$members = [];
		$untargetable = [];
		$pos = 0;

		foreach ($wsNode['panes'] ?? [] as $paneIdx => $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$ref  = $surf['ref'] ?? '';
				$type = $surf['type'] ?? 'terminal';
				$row  = $liveByRef[$ref] ?? null;
				$unboundRow = $unboundByRef[$ref] ?? null;
				$isClaude = $isClaudeByRef[$ref] ?? false;

				$entry = [
					'group_pos'    => $pos,
					'pane_index'   => $pane['index'] ?? $paneIdx,
					'index_in_pane'=> $surf['index_in_pane'] ?? 0,
					'ref'          => $ref,
					'type'         => $type,
					'title'        => $surf['title'] ?? '',
					'url'          => $surf['url'] ?? null,
					'cwd'          => $row['cwd'] ?? $cwdByRef[$ref] ?? null,
					'kind'         => 'shell',
					'claude_session_id' => null,
					// Whether this was the tab showing in its pane, so resurrect can bring
					// the same one back to the front instead of whichever it created last.
					'selected_in_pane' => (bool) ($surf['selected_in_pane'] ?? false),
				];

				// A non-terminal, non-browser surface is a cmux-native agent session
				// (e.g. type "agentSession", the React Claude UI). It has no tty for the
				// join/statusline machinery, so it can never be a bound member — but it
				// IS Claude, so treat it as claude (untargetable) to force an abort rather
				// than silently closing it as if it were a shell.
				$claudeSurface = $isClaude || ($type !== 'terminal' && $type !== 'browser');

				// A codex session lives in a PLAIN terminal and shows no Claude statusline,
				// so it never trips $claudeSurface. Detect it from the codex surface-probe
				// (all live codex, targetable or not) or a bound codex row — otherwise a
				// workspace bury would class it 'shell' and CLOSE it without archiving
				// (dotfiles-5p5, data loss). Checked before Claude so a codex terminal is
				// never mistaken for one.
				$isCodex = $isCodexByRef[$ref] ?? false;
				$codexSurface = $type !== 'browser'
					&& ($isCodex || ($row && $this->rowAgent($row) === 'codex'));

				if ($type === 'browser') {
					$entry['kind'] = 'browser';
				} elseif ($codexSurface && $row && ($row['targetable'] ?? false)) {
					$entry['kind'] = 'codex';
					// Reuse claude_session_id as the generic agent-session id the manifest
					// and resurrect key on; a codex tombstone stores this same id as
					// session_id, so manifestPositions()/tombBySid resolve it.
					$entry['claude_session_id'] = $row['session_id'];
					$m = $row; $m['group_pos'] = $pos;
					$members[] = $m;
				} elseif ($codexSurface) {
					// A live codex the join could not bind to a unique targetable surface.
					// Like an untargetable Claude: detected, not buryable → forces abort
					// (unless --force skips it, left alive) rather than closing it as a shell.
					$entry['kind'] = 'codex-untargetable';
					$untargetable[] = [
						'ref'            => $ref,
						'title'          => $surf['title'] ?? '',
						'type'           => $type,
						'agent'          => 'codex',
						'session_reason' => $unboundRow['reason'] ?? null,
						'no_bridge'      => $unboundRow['no_bridge'] ?? null,
					];
				} elseif ($claudeSurface && $row && ($row['targetable'] ?? false)) {
					$entry['kind'] = 'claude';
					$entry['claude_session_id'] = $row['session_id'];
					$m = $row; $m['group_pos'] = $pos;
					$members[] = $m;
				} elseif ($claudeSurface) {
					// A Claude surface the join could not bind to a targetable session
					// (fresh/non-resumed, ambiguous, or a native agent session). Detected,
					// not buryable → forces abort (unless --force skips it, alive).
					$entry['kind'] = 'claude-untargetable';
					$untargetable[] = [
						'ref'            => $ref,
						'title'          => $surf['title'] ?? '',
						'type'           => $type,
						'agent'          => 'claude',
						'session_reason' => $unboundRow['reason'] ?? null,
						'no_bridge'      => $unboundRow['no_bridge'] ?? null,
					];
				}

				$layout[] = $entry;
				$pos++;
			}
		}

		return ['layout' => $layout, 'members' => $members, 'untargetable' => $untargetable];
	}

	/**
	 * PURE. Which surface to bring to the front in each pane on resurrect: the entry
	 * that was `selected_in_pane` at bury. Returns [pane_index => group_pos]; panes with
	 * no flagged tab are omitted (leave cmux's default selection). Last flagged entry per
	 * pane wins, though cmux only ever marks one.
	 */
	public function paneSelections(array $layout): array {
		$sel = [];
		foreach ($layout as $e) {
			if (!empty($e['selected_in_pane'])) { $sel[$e['pane_index']] = $e['group_pos']; }
		}
		return $sel;
	}

	/**
	 * I/O glue: re-select the tab that was showing in each pane at bury. Maps the pure
	 * paneSelections() result (pane => group_pos) onto the restored surface refs and asks
	 * cmux to bring each to the front. $refByPos: [group_pos => restored surface ref].
	 */
	private function applyPaneSelections(array $layout, array $refByPos, string $wsRef): void {
		foreach ($this->paneSelections($layout) as $pos) {
			$ref = $refByPos[$pos] ?? null;
			if ($ref) { $this->cmux->selectSurface($wsRef, (string) $ref); }
		}
	}

	/**
	 * PURE. Turn a stored workspace layout (flat, ordered surface entries, each
	 * tagged with pane_index) into ordered restore steps that rebuild the pane
	 * splits and per-pane tab stacks — instead of dumping every surface into one
	 * pane. Each step:
	 *   op 'first' — the very first entry: reuse the new workspace's initial surface.
	 *   op 'split' — the first entry of every SUBSEQUENT pane: open a new split/pane.
	 *   op 'tab'   — any later entry within a pane: a new tab inside that pane.
	 * cmux tree exposes no split direction/geometry (verified: neither the workspace
	 * nor pane node carries it), so splits default to 'right' — side-by-side columns,
	 * the common multi-pane shape. Grouping is by first-seen pane_index, so it is
	 * robust even if entries for a pane are not perfectly contiguous.
	 */
	public function planLayoutRestore(array $layout): array {
		$steps = [];
		$seenPanes = [];
		foreach ($layout as $e) {
			$pane = $e['pane_index'] ?? 0;
			$firstInPane = !in_array($pane, $seenPanes, true);
			if ($firstInPane) { $seenPanes[] = $pane; }

			if ($firstInPane && count($seenPanes) === 1) {
				$op = 'first';
			} elseif ($firstInPane) {
				$op = 'split';
			} else {
				$op = 'tab';
			}

			$step = ['op' => $op, 'pane_index' => $pane, 'entry' => $e];
			if ($op === 'split') { $step['dir'] = 'right'; }
			$steps[] = $step;
		}
		return $steps;
	}

	/**
	 * PURE. Count leaf surfaces in a cmux layout tree, through the one implementation
	 * in Cmux (cmux-bak gates its own restore on the same count). Used to gate the
	 * geometry-restore path: cmux drops unsupported surface types (agent-session,
	 * markdown, …) when capturing a layout, so a captured tree can hold fewer surfaces
	 * than the manifest's flat layout[]. When the counts disagree the positional
	 * surface↔session join is untrustworthy, so resurrect falls back to the manual
	 * pane rebuild.
	 */
	public function layoutTreeSurfaceCount(array $node): int {
		return $this->cmux->layoutTreeSurfaceCount($node);
	}

	/**
	 * PURE. Strip per-surface `command` from a captured layout tree, through the one
	 * implementation in Cmux. cmux records the command a surface was launched with;
	 * replaying it via `new-workspace --layout` would re-run it (double-launching
	 * Claude) — graveyard drives every launch itself afterward. Geometry, type, and
	 * cwd are preserved.
	 */
	public function sanitizeLayoutTree(array $node): array {
		return $this->cmux->sanitizeLayoutTree($node);
	}

	/** Launch one restored surface: resume Claude, open browser, or cd a shell. */
	private function launchLayoutEntry(array $e, string $surfRef, string $wsRef, array $tombBySid, bool $fromTranscript, int &$restored): void {
		// claude AND codex members resume through launchSessionIntoSurface(), which is
		// agent-aware (buildTombstoneLaunch replays `codex resume …` for a codex tomb).
		// Without codex here a resurrected codex member would just cd a bare shell
		// (dotfiles-5p5). tombBySid is keyed by session_id, which the entry mirrors.
		if (in_array($e['kind'] ?? '', ['claude', 'codex'], true) && !empty($e['claude_session_id']) && isset($tombBySid[$e['claude_session_id']])) {
			$mode = $this->launchSessionIntoSurface($tombBySid[$e['claude_session_id']], $surfRef, $wsRef, $fromTranscript);
			$this->cli->msg('  ↺ ' . substr($e['claude_session_id'], 0, 8) . " ({$mode})", 'green');
			$restored++;
		} elseif ($e['kind'] === 'browser') {
			// url already applied at surface creation
		} elseif (!empty($e['cwd'])) {
			$this->cmux->sendToSurface($surfRef, $wsRef, 'cd ' . escapeshellarg($e['cwd']) . "\n");
		}
	}

	/**
	 * PURE. Human reason WHY a detected Claude surface is untargetable, from injectable
	 * facts, so the abort report tells JT whether to fix, wait, or --force:
	 *   type              cmux surface type
	 *   has_script        surface launched via a cmux resume wrapper
	 *   has_shell         that wrapper's shell chain is alive
	 *   has_claude        a live claude process exists under it
	 *   has_session_file  that claude has a ~/.claude/sessions/<pid>.json
	 *   no_bridge         whether the join found neither surface bridge
	 *   session_reason    the join's concrete untargetable reason
	 *   cwd               the surface's working dir (for the fresh-session message)
	 *   cwd_session_count how many live Claude sessions share that cwd (>1 = ambiguous)
	 */
	public function untargetableReasonFor(array $f): string {
		$type = $f['type'] ?? 'terminal';
		if ($type !== 'terminal' && $type !== 'browser') {
			return 'cmux-native agent session (not a Claude CLI terminal) — unsupported by bury';
		}
		if (!empty($f['session_reason']) && ($f['no_bridge'] ?? null) === false) {
			return (string) $f['session_reason'];
		}
		if (empty($f['has_script'])) {
			$cwd   = trim((string) ($f['cwd'] ?? ''));
			$count = (int) ($f['cwd_session_count'] ?? 0);
			$where = $cwd !== '' ? " in {$cwd}" : '';
			// Fresh = not launched via graveyard/cmux, so there's no resume-script link
			// from surface→process. The only fallback is matching the tab's on-screen cwd
			// to a session's cwd — which fails when several live sessions share that cwd.
			if ($count > 1) {
				return "fresh/non-resumed Claude{$where}: {$count} live Claude sessions share this cwd, "
					. "so bury can't tell which process backs this tab — it wasn't started via graveyard, "
					. "so there's no resume-script link, and the shared cwd makes the on-screen fallback "
					. "ambiguous (bury won't guess which session to /export + kill). Fix: bury/close the "
					. "other sessions in this cwd first (then this one becomes the unique match), or re-run "
					. "with --force to skip it (left alive).";
			}
			return "fresh/non-resumed Claude{$where}: no resume-script link and its on-screen cwd "
				. "couldn't be matched to this session's cwd (statusline missing, scrolled, or mismatched). "
				. "Fix: give the tab a moment and retry, or re-run with --force to skip it (left alive).";
		}
		if (empty($f['has_shell']))   { return 'resumed surface with no live shell — stale; nothing to bury'; }
		if (empty($f['has_claude']))  { return 'resumed Claude not running (exited or never launched) — no live session to bury'; }
		if (empty($f['has_session_file'])) { return 'Claude running but no session file yet (no conversation) — wait, or start a turn'; }
		if (!empty($f['bound_elsewhere'])) {
			$sid = substr((string) ($f['session_id'] ?? ''), 0, 8);
			return "duplicate live view of session {$sid} (already bound to {$f['bound_elsewhere']}) — close this extra tab";
		}
		if (!empty($f['session_reason'])) { return (string) $f['session_reason']; }
		return 'live Claude session present but could not be bound to this surface (ambiguous)';
	}

	/** Gather untargetable-surface facts (I/O) and delegate to untargetableReasonFor(). */
	protected function diagnoseUntargetableSurface(string $ref, string $type, array $debug, array $proc, ?array $liveSessions = null): string {
		$script = $debug[$ref]['script'] ?? null;
		$roots  = [];
		if ($script !== null) {
			foreach ($proc as $pid => $info) {
				if (strpos($info['cmd'], $script) !== false) { $roots[] = $pid; }
			}
		}
		$claude = null;
		foreach ($roots as $r) {
			$c = $this->cmux->descendantClaudePid($proc, (int) $r);
			if ($c) { $claude = $c; break; }
		}
		$sid = $claude !== null ? $this->cmux->sessionIdForPid((int) $claude) : null;

		// If this surface's session is live but bound to a DIFFERENT surface, it's a
		// duplicate view (same session on two surfaces; the join deduped to the other).
		$boundElsewhere = null; $sessionReason = null;
		if ($sid !== null) {
			foreach ($liveSessions ?? $this->liveSessions() as $lr) {
				if ($lr['session_id'] !== $sid) { continue; }
				if (($lr['targetable'] ?? false) && $lr['surface_ref'] !== '' && $lr['surface_ref'] !== $ref) {
					$boundElsewhere = $lr['surface_ref'];
				} else {
					$sessionReason = $lr['reason'] ?: null;
				}
				break;
			}
		}

		// For the fresh-session message: diagnose from the ON-SCREEN statusline cwd (the
		// exact signal the cwd-match fallback uses), not the surface's shell cwd — a claude
		// launched from ~ but running in ~/.dotfiles shows the latter. Count how many live
		// sessions that statusline cwd matches; >1 is the ambiguity that blocks the bind.
		$screen    = $this->readLastScreen($ref, (string) ($debug[$ref]['workspace_ref'] ?? ''), 30);
		$cwd       = (string) ($this->extractStatuslineCwd($screen) ?? '');
		$cwdSessionCount = 0;
		if ($cwd !== '') {
			foreach ($this->cmux->loadClaudeSessionsByPid() as $s) {
				if ($this->statuslineMatchesSession($screen, (string) ($s['cwd'] ?? ''))) { $cwdSessionCount++; }
			}
		}

		return $this->untargetableReasonFor([
			'type'              => $type,
			'has_script'        => $script !== null,
			'has_shell'         => (bool) $roots,
			'has_claude'        => $claude !== null,
			'has_session_file'  => $sid !== null,
			'session_id'        => $sid,
			'bound_elsewhere'   => $boundElsewhere,
			'session_reason'    => $sessionReason,
			'cwd'               => $cwd,
			'cwd_session_count' => $cwdSessionCount,
		]);
	}

	/**
	 * PURE. Given a resolved workspace-group manifest and the current live-session
	 * rows (liveSessions() shape), return the cmux workspace_ref that hosts the
	 * group's members, or null if none of them are live. Members are matched by
	 * claude_session_id — resurrect preserves session ids, so a resurrected group's
	 * live sessions still carry the ids the manifest recorded at bury. All members
	 * normally share one workspace; if a stray duplicate view splits them, the
	 * workspace hosting the MOST members wins, ties broken by lowest ref string, so
	 * the target stays deterministic instead of depending on iteration order.
	 */
	public function liveWorkspaceForGroup(array $manifest, array $liveSessions): ?string {
		$memberSids = [];
		foreach ($manifest['layout'] ?? [] as $e) {
			$sid = $e['claude_session_id'] ?? null;
			if ($sid) { $memberSids[$sid] = true; }
		}
		if (!$memberSids) { return null; }

		$countByRef = [];
		foreach ($liveSessions as $r) {
			$sid = $r['session_id'] ?? null;
			$ref = (string) ($r['workspace_ref'] ?? '');
			if ($sid !== null && $ref !== '' && isset($memberSids[$sid])) {
				$countByRef[$ref] = ($countByRef[$ref] ?? 0) + 1;
			}
		}
		if (!$countByRef) { return null; }

		$refs = array_keys($countByRef);
		usort($refs, fn($a, $b) => [$countByRef[$b], $a] <=> [$countByRef[$a], $b]);
		return $refs[0];
	}

	/**
	 * Resolve a buried group manifest to the cmux target that bury --workspace should
	 * act on. Precise first: the live workspace whose sessions match the group's
	 * recorded session ids (holds under --resume, and even if the workspace was
	 * renamed after resurrect). Fallback: the group_title, which resurrect stamps as
	 * the new workspace's title — this is what survives a transcript-mode resurrect,
	 * where Claude is relaunched on the exported transcript and mints a NEW session id
	 * so the id match can't hit. Returns a workspace ref, a title string (both accepted
	 * by resolveWorkspaceNode), or null when neither is available.
	 */
	public function groupBuryTarget(array $manifest, array $liveSessions): ?string {
		$ref = $this->liveWorkspaceForGroup($manifest, $liveSessions);
		if ($ref !== null) { return $ref; }
		$title = trim((string) ($manifest['group_title'] ?? ''));
		return $title !== '' ? $title : null;
	}

	public function buryWorkspace(string $nameOrRef, bool $force, bool $autoConfirm): void {
		$liveSessions = null;
		// Group-id symmetry with resurrect (dotfiles-bury-group-target): resurrect
		// accepts a buried group-id prefix, so bury --workspace does too. If the arg
		// resolves to a buried group, retarget to the LIVE cmux workspace that group was
		// resurrected into — rather than letting the group id fall through to a
		// title-substring guess. resolveGroup returns null for any non-group arg (refs,
		// titles), so normal resolution below is unchanged.
		if ($grp = $this->resolveGroup($nameOrRef)) {
			$target = $this->groupBuryTarget($grp, $liveSessions ??= $this->liveSessions());
			if ($target === null) {
				$this->cli->exitErr(sprintf(
					'Group %s ("%s") has no live sessions or title to target — resurrect it first, or it may already be buried.',
					substr((string) ($grp['group_id'] ?? $nameOrRef), 0, 8), (string) ($grp['group_title'] ?? '')
				));
				return;
			}
			$nameOrRef = $target;
		}

		try {
			$wsInfo = $this->cmux->resolveWorkspaceNode($this->cmux->tree(), $nameOrRef);
		} catch (\RuntimeException $e) {
			$this->cli->exitErr($e->getMessage());
			return;
		}
		if (!$wsInfo) { $this->cli->exitErr("No workspace matches '{$nameOrRef}'."); return; }

		$wsRef   = $wsInfo['ref'];
		$wsTitle = $wsInfo['title'];
		$liveSessions ??= $this->liveSessions();

		// Index this live-session snapshot by surface_ref. Keep unbound rows too: their
		// join reason explains collisions and other surface-attributable failures more
		// accurately than the legacy resume-script diagnostic, and avoids a second
		// liveSessions() call to recover it.
		$liveByRef = [];
		$unboundByRef = [];
		foreach ($liveSessions as $r) {
			$ref = (string) ($r['surface_ref'] ?? '');
			if ($ref === '') { continue; }
			if ($r['targetable'] ?? false) {
				$liveByRef[$ref] = $r;
			} else {
				$unboundByRef[$ref] = $r;
			}
		}
		$isClaudeByRef = [];
		foreach ($wsInfo['node']['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$ref = $surf['ref'] ?? '';
				if ($ref === '' || ($surf['type'] ?? '') === 'browser') { $isClaudeByRef[$ref] = false; continue; }
				$screen = $this->readLastScreen($ref, $wsRef, 8);
				$isClaudeByRef[$ref] = $this->extractStatuslineCwd($screen) !== null;
			}
		}
		// Every live codex bound to a surface (targetable or not), so classify can tell a
		// codex terminal from a shell — codex shows no Claude statusline, so the probe above
		// can't see it. Without this a codex tab is buried as a shell: closed, not archived
		// (dotfiles-5p5, data loss). OS-level bind (CMUX_SURFACE_ID), not a screen scrape.
		$isCodexByRef = [];
		foreach ($this->liveCodexBySurfaceRef() as $ref => $sid) {
			if ($ref !== '' && $sid !== '') { $isCodexByRef[$ref] = true; }
		}
		foreach ($this->liveCodexSurfaceRefs() as $ref => $present) {
			if ($present) { $isCodexByRef[$ref] = true; }
		}

		// Plain terminal surfaces have no session join to supply a cwd. Capture their
		// foreground process cwd while the workspace still exists so resurrect can cd
		// the restored shell back to where it was buried.
		$cwdByRef = [];
		$debugByRef = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		foreach ($wsInfo['node']['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$ref = $surf['ref'] ?? '';
				if (($surf['type'] ?? '') !== 'terminal' || $ref === '' || isset($liveByRef[$ref]) || ($isClaudeByRef[$ref] ?? false) || ($isCodexByRef[$ref] ?? false)) { continue; }
				$tty = $debugByRef[$ref]['tty'] ?? '';
				if ($tty !== '') { $cwdByRef[$ref] = $this->cmux->getCwdForTty($tty); }
			}
		}

		// Last-resort bind: for a Claude surface the join left unbound (a fresh /
		// non-resumed session that cd'd — its on-screen cwd drifted from its recorded
		// launch cwd, so the statusline content-probe can't pin it, and the drifted cwd
		// collides with other sessions' launch cwds), ask it who it is via /status and
		// synthesize a targetable row. Interactive (sends keystrokes + reads a modal), so
		// it lives ONLY here in the bury path — never the liveSessions()/ls hot path. Never
		// probe the caller's own surface (that would type /status into our own REPL).
		$selfSurf = $this->selfSurfaceId();
		$treeIx   = $this->treeIndex($this->cmux->tree());
		$byPid    = $this->cmux->loadClaudeSessionsByPid();
		foreach ($isClaudeByRef as $ref => $isClaude) {
			if (!$isClaude || $ref === '' || isset($liveByRef[$ref])) { continue; }
			if ($selfSurf && ($ref === $selfSurf || ($treeIx['surface'][$ref]['id'] ?? null) === $selfSurf)) { continue; }
			$probe = $this->probeSurfaceIdentity($ref, $wsRef);
			if (!$probe) { continue; }
			$row = $this->synthesizeProbedRow($ref, $wsRef, $probe, $byPid, $treeIx);
			if ($row) {
				$this->cli->msg('  Bound ' . $ref . ' via /status → ' . substr($row['session_id'], 0, 8) . ' (' . $row['cwd'] . ').', 'cyan');
				$liveByRef[$ref] = $row;
			}
		}

		$cls = $this->classifyWorkspaceLayout($wsInfo['node'], $liveByRef, $isClaudeByRef, $isCodexByRef, $cwdByRef, $unboundByRef);

		// Self-guard: never bury a workspace containing the caller's own session.
		$selfSid = $this->selfSessionId();
		foreach ($cls['members'] as $m) {
			if ($selfSid && $m['session_id'] === $selfSid) {
				$this->cli->exitErr('Refusing to bury a workspace containing the caller\'s own session.');
				return;
			}
		}

		// Abort on any detected-but-unbindable agent surface, unless --force skips them.
		// Each line carries the SPECIFIC reason so JT knows whether to fix, wait, or --force.
		if ($cls['untargetable']) {
			$w     = $this->termWidth();
			$proc  = $this->cmux->parseProcTable($this->cmux->psProcTable());
			$debug = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
			$this->cli->msg('Workspace "' . $wsTitle . '" has ' . count($cls['untargetable']) . ' agent surface(s) not safely targetable:', 'yellow');
			foreach ($cls['untargetable'] as $u) {
				// diagnoseUntargetableSurface() is Claude-shaped (walks to a Claude pid,
				// reads a Claude statusline). Codex has no such surface, so give it a plain
				// codex reason instead of running the Claude diagnoser against it.
				if (($u['agent'] ?? 'claude') === 'codex') {
					$reason = 'live codex session the join could not bind to a unique surface — resolve it (or re-run with --force to skip it, left alive).';
				} elseif (!empty($u['session_reason']) && ($u['no_bridge'] ?? null) === false) {
					$reason = $this->untargetableReasonFor([
						'type' => $u['type'] ?? 'terminal',
						'session_reason' => $u['session_reason'],
						'no_bridge' => false,
					]);
				} else {
					$reason = $this->diagnoseUntargetableSurface($u['ref'], $u['type'] ?? 'terminal', $debug, $proc, $liveSessions);
				}
				$this->cli->msg('  ' . $this->ellipsizeText($u['ref'] . '  ' . $this->stripGlyph((string) $u['title']), $w - 2), 'yellow');
				// Wrap the reason (never ellipsize) so the full explanation + fix is always shown.
				foreach (explode("\n", wordwrap($reason, max(30, $w - 8))) as $line) {
					$this->cli->msg('      ↳ ' . $line, 'yellow');
				}
			}
			if (!$force) {
				$this->cli->exitErr('Refusing to partially destroy a workspace. Resolve these (or re-run with --force to skip them and leave them alive).');
				return;
			}
			$this->cli->msg('  --force: skipping the above (left ALIVE); the workspace will not be fully closed.', 'yellow');
		}

		if (!$cls['members']) { $this->cli->exitErr('No targetable Claude sessions in that workspace to bury.'); return; }

		$this->cli->msg(sprintf('Workspace "%s" (%s): %d agent session(s), %d other surface(s).',
			$wsTitle, $wsRef, count($cls['members']), count($cls['layout']) - count($cls['members'])), 'yellow');
		foreach ($cls['members'] as $m) {
			$this->cli->msg(sprintf('  %s  %-20.20s  %s', substr($m['session_id'], 0, 8), $m['tab_title'] ?? '', $m['cwd'] ?? ''));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury this workspace (' . count($cls['members']) . ' session(s)) as a group?')) {
			$this->cli->msg('Aborted.', 'yellow');
			return;
		}

		// Stamp a shared group + write the layout manifest BEFORE any destruction.
		// Reuse the group id these members were last buried under (resurrect keeps
		// tombstones + session ids), so the resurrect id stays stable across re-buries;
		// mint fresh only for a brand-new or merged-from-multiple-plots workspace.
		$sidToGroup = [];
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (!empty($t['group_id'])) { $sidToGroup[(string) $t['session_id']] = (string) $t['group_id']; }
		}
		$group = $this->stableGroupId(
			array_map(fn($m) => (string) $m['session_id'], $cls['members']),
			$sidToGroup,
			fn() => $this->cmux->uuidv4()
		);
		$buriedAt = gmdate('Y-m-d\TH:i:s\Z');
		$dir = $this->workspaceGroupDir($group);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }

		// Capture cmux's true split geometry (orientation, divider, nesting) while the
		// workspace is still alive — resurrect replays it exactly. Null when cmux has no
		// layout API; resurrect then rebuilds panes manually.
		$layoutTree = $this->cmux->captureLayoutTree($wsRef);

		$manifest = [
			'group_id'    => $group,
			'group_title' => $wsTitle,
			'window_ref'  => $wsInfo['window_ref'],
			'buried_at'   => $buriedAt,
			'layout'      => $cls['layout'],
		];
		if ($layoutTree !== null) { $manifest['layout_tree'] = $this->sanitizeLayoutTree($layoutTree); }
		file_put_contents($this->manifestPath($group), json_encode($manifest, JSON_PRETTY_PRINT));

		// Bury each member (per-member gates), deferring the tab close. Track members
		// left ALIVE by a failed gate (buryOne false) — distinct from ones already gone
		// — so we can print an exact "finish it" command for each once the group exists.
		$buried = 0; $failed = 0; $stillAlive = [];
		foreach ($cls['members'] as $m) {
			$sid = $m['session_id'];
			// A /status-probed member uses its synthesized row directly: re-resolving via
			// liveSessions() returns the untargetable row that made us probe (fresh sessions
			// ARE in liveSessions(), just as targetable=false), which gate 0 rejects. The
			// probe confirmed it alive, and buryOne re-reads the live screen for its gates.
			$fresh = $this->memberRowToBury($m, $this->resolveLiveBySessionId($sid));
			if (!$fresh) { $this->cli->msg("  {$sid} is gone — skipping.", 'yellow'); $failed++; continue; }
			$fresh['group_pos'] = $m['group_pos'];
			$grp = ['group_id' => $group, 'group_title' => $wsTitle, 'group_pos' => $m['group_pos']];
			if ($this->buryOne($fresh, $force, true, $grp, true)) { $buried++; }
			else { $failed++; $stillAlive[] = $sid; }
		}

		// Nothing actually buried (every member failed a gate): this is a FAILURE, not a
		// bury. Do not claim success, do not leave a group/manifest artifact behind, do
		// not close anything. Remove the pre-written (now-empty) group dir and exit non-zero.
		// No group survives, so point back at re-running the whole-workspace bury.
		if ($buried === 0) {
			$this->removeGroupArtifact($group);
			$this->cli->err("Buried 0 of " . count($cls['members']) . " session(s) in \"{$wsTitle}\" — every member was refused by a gate; workspace left intact. No group created.");
			$this->cli->msg('  Resolve the cause, then re-run:', 'yellow');
			$this->cli->msg('      graveyard bury -ws ' . escapeshellarg($wsTitle), 'cyan');
			exit(1);
		}

		// Close the workspace only if everything was clean; otherwise close just the
		// buried members' surfaces + non-agent surfaces, leaving anything still alive.
		if ($failed === 0 && !$cls['untargetable']) {
			$this->closeWorkspace($wsRef);
		} else {
			foreach ($cls['layout'] as $e) {
				// Untargetable agents were left ALIVE — never close their surface.
				if (in_array($e['kind'], ['claude-untargetable', 'codex-untargetable'], true)) { continue; }
				// Buried members (claude/codex) were already killed; their surface closes
				// when the shell exits. Only genuine shells/browsers get an explicit close.
				if (in_array($e['kind'], ['claude', 'codex'], true)) { continue; }
				$this->cli->getCommandOutputAndExitCode(escapeshellcmd($this->cmux->cmuxBin()) . ' close-surface --surface ' . escapeshellarg($e['ref']));
			}
			$this->cli->msg('  Workspace left open (some surfaces preserved).', 'yellow');
		}

		// The group + layout manifest persist (buried > 0), with a slot still reserved for
		// each member a gate left alive. Print the exact command to finish burying each into
		// that reserved slot once the cause is resolved — no fresh group, pane position kept.
		if ($stillAlive) {
			$this->cli->msg('  ' . count($stillAlive) . ' session(s) left ALIVE. Resolve the cause, then finish each into its group slot:', 'yellow');
			foreach ($stillAlive as $sid) {
				$this->cli->msg('      ' . $this->finishBuryCommand($sid, $group), 'cyan');
			}
		}

		$pruned = $this->pruneOrphanedManifests();
		if ($pruned) {
			$this->cli->msg('  Pruned ' . count($pruned) . ' stale workspace manifest(s) from earlier buries.', 'cyan');
		}

		$this->cli->successMsg("Buried workspace \"{$wsTitle}\" — group {$group} ({$buried} session(s)).");
	}

	/**
	 * Bury ONE still-alive session into an EXISTING group, at the slot that group's
	 * layout manifest already reserves for it. This is the recovery path when a
	 * `bury -ws` left a member alive (a per-member gate failed): re-running `bury -ws`
	 * would mint a fresh singleton group and split the survivor off, losing its pane
	 * position. Reusing the reserved group_pos keeps the plot whole — resurrect replays
	 * the original layout with this session back in place.
	 *
	 * Refuses if the session is not a member of that group's manifest (out of scope to
	 * graft a brand-new session into an existing plot; use `bury <id>` or `bury -ws`).
	 */
	public function buryIntoGroup(string $sessionRef, string $groupRef, bool $force, bool $autoConfirm): void {
		$gids = $this->matchGroupIds($groupRef);
		if (!$gids) { $this->cli->exitErr("No buried group matches '{$groupRef}'."); return; }
		if (count($gids) > 1) {
			$this->cli->msg("'{$groupRef}' is ambiguous — matches " . count($gids) . ' group(s):', 'yellow');
			foreach ($gids as $g) { $this->cli->msg('  ' . $g); }
			$this->cli->exitErr("'{$groupRef}' is ambiguous — pass a longer prefix.");
			return;
		}
		$gid = $gids[0];
		$manifest = json_decode((string) @file_get_contents($this->manifestPath($gid)), true);
		if (!is_array($manifest)) { $this->cli->exitErr("Group {$gid} has no readable manifest."); return; }

		$matches = $this->resolveLiveByIdentifier($sessionRef);
		if (!$matches) { $this->cli->exitErr("No live session matches '{$sessionRef}'."); return; }
		if (count($matches) > 1) {
			$this->cli->msg("'{$sessionRef}' is ambiguous — matches " . count($matches) . ' live session(s):', 'yellow');
			foreach ($matches as $m) {
				$this->cli->msg(sprintf('  %s  %-20.20s  %.40s', substr($m['session_id'], 0, 8), $m['workspace_title'] ?? '', $m['tab_title'] ?? ''));
			}
			$this->cli->exitErr("'{$sessionRef}' is ambiguous — narrow it or pass a full session-id.");
			return;
		}
		$fresh = $matches[0];
		$sid   = (string) $fresh['session_id'];
		$id    = substr($sid, 0, 8);

		if ($this->selfSessionId() && $sid === $this->selfSessionId()) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
			return;
		}

		$pos = $this->reservedGroupPos($manifest, $sid);
		if ($pos === null) {
			$this->cli->exitErr("Session {$id} is not a member of group " . substr($gid, 0, 8)
				. "'s layout — refusing. Use `graveyard bury {$id}` to bury it standalone, or `graveyard bury -ws` for its workspace.");
			return;
		}

		$grp = ['group_id' => $gid, 'group_title' => $manifest['group_title'] ?? '', 'group_pos' => $pos];
		$this->cli->msg("Burying {$id} into group " . substr($gid, 0, 8) . " at reserved pos {$pos}…", 'cyan');
		if ($this->buryOne($fresh, $force, $autoConfirm, $grp, false)) {
			$this->cli->successMsg("  Finished — {$id} rejoined group " . substr($gid, 0, 8) . " (pos {$pos}).");
		}
	}

	/**
	 * Remove workspace group manifests that no tombstone points to any more, returning
	 * the removed group ids. Re-burying a workspace mints a fresh group + manifest and
	 * re-points the members' tombstones (deduped by session_id) to it, orphaning the
	 * prior group's manifest — which then lingers in ls/page and resurrects to
	 * "0 Claude session(s) restored". Run after a bury (once tombstones are upserted, so
	 * the just-created group is never mistaken for an orphan) to keep exactly the live
	 * groups on disk.
	 */
	public function pruneOrphanedManifests(): array {
		$removed = [];
		foreach ($this->orphanedManifestGroups() as $gid) {
			$this->removeGroupArtifact($gid);
			$removed[] = $gid;
		}
		return $removed;
	}

	/**
	 * PURE-ish. The group-dir ids whose manifest no live tombstone references (see
	 * pruneOrphanedManifests). Detection only — no deletion — so callers can report a
	 * dry run before pruning.
	 */
	public function orphanedManifestGroups(): array {
		$root = $this->storeRoot() . '/workspaces';
		if (!is_dir($root)) { return []; }
		$live = [];
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (!empty($t['group_id'])) { $live[$t['group_id']] = true; }
		}
		$orphans = [];
		foreach (glob($root . '/*/manifest.json') ?: [] as $mf) {
			$gid  = basename(dirname($mf));
			$m    = json_decode((string) @file_get_contents($mf), true);
			$mgid = $m['group_id'] ?? $gid;
			if (!isset($live[$mgid])) { $orphans[] = $gid; }
		}
		return $orphans;
	}

	/** Remove a workspace group directory + manifest (used to clean up a failed bury). */
	protected function removeGroupArtifact(string $group): void {
		$dir = $this->workspaceGroupDir($group);
		@unlink($this->manifestPath($group));
		if (is_dir($dir)) { @rmdir($dir); }
	}

	# =========================================================================
	# Width-aware output formatting (dotfiles-rgk). Pure formatters take an
	# explicit $width so they're testable at 60/80/120 cols without a real tty.
	# =========================================================================

	/** Terminal width from COLUMNS or `tput cols`; clamped, fallback 80. */
	protected function termWidth(): int {
		$c = getenv('COLUMNS');
		if ($c !== false && ctype_digit(trim($c))) { return max(40, (int) trim($c)); }
		$t = trim((string) shell_exec('tput cols 2>/dev/null'));
		if ($t !== '' && ctype_digit($t)) { return max(40, (int) $t); }
		return 80;
	}

	/** PURE. Truncate to at most $max display chars, appending … when cut. */
	public function ellipsizeText(string $s, int $max): string {
		$s = trim($s);
		if ($max <= 0) { return ''; }
		if (mb_strlen($s) <= $max) { return $s; }
		if ($max === 1) { return '…'; }
		return rtrim(mb_substr($s, 0, $max - 1)) . '…';
	}

	/** PURE. Truncate from the LEFT with a leading … (keeps the tail). */
	public function ellipsizeLeft(string $s, int $max): string {
		if ($max <= 0) { return ''; }
		if (mb_strlen($s) <= $max) { return $s; }
		if ($max === 1) { return '…'; }
		return '…' . mb_substr($s, -($max - 1));
	}

	/** PURE. Strip machine noise from a raw summary: <tags>, [MACHINE_KEY: ...], home→~. */
	public function cleanSummaryText(string $s, string $home = ''): string {
		$s = preg_replace('/<[^>]*>/', ' ', $s);               // <command-message> …
		$s = preg_replace('/\[[A-Z0-9_]+:[^\]]*\]/', ' ', $s); // [CALL_ID: …] [MODE: …]
		if ($home !== '') { $s = str_replace($home, '~', $s); }
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	/**
	 * PURE. A title-like label for a tombstone: prefer a cleaned first-prompt summary,
	 * but fall back to the session's own title when the summary is empty or just a bare
	 * slash-command (e.g. "/hotline:ringing"), then the workspace title. A slash command
	 * does win when that title is only the basename of the recorded working directory.
	 */
	public function titleizeSummary(array $t, string $home = ''): string {
		// A custom name (via `graveyard rename`) always wins as the display title.
		$name = trim((string) ($t['name'] ?? ''));
		if ($name !== '') { return $name; }
		$sum = $this->cleanSummaryText((string) ($t['summary'] ?? ''), $home);
		// A summary that's actually machine noise (a leaked caveat / skill preamble from
		// an older tombstone) is not a title — treat it as empty so we fall back.
		if (stripos($sum, 'Caveat: The messages below') === 0 || stripos($sum, 'Base directory for this skill:') === 0) { $sum = ''; }
		$tab = $this->stripGlyph((string) ($t['tab_title'] ?? ''));
		$goodTab = ($tab !== '' && $tab !== 'Terminal');
		$isSlashSummary = $sum !== '' && $sum[0] === '/';
		if (($sum === '' || ($isSlashSummary && (!$this->isSlashCommand($sum) || !$this->isBareDirectoryTab($t, $tab)))) && $goodTab) { return $tab; }
		if ($sum !== '') { return $sum; }
		if ($goodTab) { return $tab; }
		$ws = trim((string) ($t['workspace_title'] ?? ''));
		return $ws !== '' ? $ws : '(untitled)';
	}

	/** A slash command's first token has one leading slash, unlike an absolute path. */
	private function isSlashCommand(string $summary): bool {
		$first = preg_split('/\\s+/', trim($summary), 2)[0] ?? '';
		return (bool) preg_match('#^/[^/\\s]+$#', $first);
	}

	/** A generic tab title is useful only as a fallback when it names the cwd itself. */
	private function isBareDirectoryTab(array $t, string $tab): bool {
		$cwd = rtrim((string) ($t['cwd'] ?? ''), '/');
		return $cwd !== '' && basename($cwd) === $tab;
	}

	/** PURE. Home→~, then elide middle components with … so the result fits $max. */
	public function shortenCwd(string $cwd, string $home, int $max): string {
		$cwd = rtrim($cwd, '/');
		if ($cwd === '' || $max <= 0) { return ''; }
		if ($home !== '' && ($cwd === $home || strncmp($cwd, $home . '/', strlen($home) + 1) === 0)) {
			$cwd = '~' . substr($cwd, strlen($home));
		}
		if (mb_strlen($cwd) <= $max) { return $cwd; }

		$abs    = ($cwd[0] === '/');
		$comps  = array_values(array_filter(explode('/', $cwd), fn($p) => $p !== ''));
		$prefix = $abs ? '' : (string) array_shift($comps); // '' (leading /) or '~'/first comp
		$tail   = [];
		for ($i = count($comps) - 1; $i >= 0; $i--) {
			$try = $prefix . '/…/' . implode('/', array_merge([$comps[$i]], $tail));
			if (mb_strlen($try) <= $max) { array_unshift($tail, $comps[$i]); }
			else { break; }
		}
		if ($tail) { return $prefix . '/…/' . implode('/', $tail); }
		return $this->ellipsizeLeft($cwd, $max); // nothing fits with prefix → keep the tail
	}

	/**
	 * PURE. One tombstone entry as display lines that never exceed $width and never
	 * wrap. Returns ['primary'=>string, 'secondary'=>?string]. Wide terminals get a
	 * single line (id · title · cwd · date); below the threshold it stacks the title on
	 * line 1 and dim cwd·date on line 2 — one consistent shape for grouped & loose.
	 *
	 * $marker is an optional glyph column between the id and the title, used by search
	 * to flag which members of a plot actually matched. It's budgeted into the width, so
	 * passing a single space for the unmatched siblings keeps a plot's titles aligned.
	 * Empty (the default, i.e. ls) emits no column at all.
	 */
	public function lsEntryLines(array $t, int $width, string $home, int $indent = 0, string $marker = ''): array {
		$id    = substr((string) $t['session_id'], 0, 8);
		$date  = substr((string) ($t['buried_at'] ?? ''), 0, 10);
		$title = $this->titleizeSummary($t, $home);
		$cwd   = (string) ($t['cwd'] ?? '');
		$pad   = str_repeat(' ', $indent);
		// `candidates` already tags live codex rows "[codex]"; a BURIED one was
		// indistinguishable from a Claude headstone. Folded into $marker rather than the
		// title so the existing width arithmetic covers it and lines still fit.
		$tag   = $this->tombstoneAgent($t) === 'codex' ? '[codex] ' : '';
		$mark  = ($marker === '' ? '' : $marker . ' ') . $tag;
		$mw    = mb_strlen($mark);
		$STACK_BELOW = 100;

		if ($width >= $STACK_BELOW) {
			$avail = $width - $indent - 8 - 6 - $mw - strlen($date); // id + three 2-space gaps + marker + date
			if ($avail >= 24) {
				$cwdMax   = min(40, intdiv($avail, 2));
				$shortCwd = $this->shortenCwd($cwd, $home, $cwdMax);
				$titleTxt = $this->ellipsizeText($title, $avail - mb_strlen($shortCwd));
				return ['primary' => $pad . $id . '  ' . $mark . $titleTxt . '  ' . $shortCwd . '  ' . $date, 'secondary' => null];
			}
		}

		// Stacked: bright title line + dim, indented cwd·date line. (Do NOT run the
		// secondary through ellipsizeText — it trims the leading indent; the fields are
		// already sized to fit within $width here.)
		$titleTxt  = $this->ellipsizeText($title, $width - $indent - 8 - 2 - $mw);
		$primary   = $pad . $id . '  ' . $mark . $titleTxt;
		$dPad      = str_repeat(' ', $indent + 2);
		$cwdMax    = $width - mb_strlen($dPad) - 3 - strlen($date);
		$shortCwd  = $this->shortenCwd($cwd, $home, max(0, $cwdMax));
		$secondary = $dPad . ($shortCwd !== '' ? $shortCwd . ' · ' : '') . $date;
		return ['primary' => $primary, 'secondary' => $secondary];
	}

	/** PURE. Group header line, truncated to $width. */
	public function groupHeaderLine(string $title, int $count, string $date, int $width): string {
		$meta = sprintf('  (%d session%s)  %s', $count, $count === 1 ? '' : 's', $date);
		$titleMax = $width - 2 - mb_strlen($meta); // "▸ "
		return '▸ ' . $this->ellipsizeText($title, max(4, $titleMax)) . $meta;
	}

	public function listTombstones(): void {
		$this->printTombstones(false);
	}

	public function listTombstonesJson(): void {
		$this->printTombstones(true);
	}

	protected function printTombstones(bool $json): void {
		$tombs = $this->tombstones();
		if (!$tombs) {
			if ($json) { echo json_encode(['workspaces' => [], 'sessions' => []], JSON_PRETTY_PRINT) . "\n"; return; }
			$this->cli->msg('Graveyard is empty.', 'yellow'); return;
		}
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		if ($json) { echo json_encode($this->lsJson($tombs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; return; }

		$w    = $this->termWidth();
		$home = getenv('HOME') ?: '';
		[$groups, $loose] = $this->groupTombstones($tombs);

		foreach ($groups as $gid => $members) {
			$title = $members[0]['group_title'] ?? '(workspace)';
			$date  = substr($members[0]['buried_at'] ?? '', 0, 10);
			$this->cli->msg($this->groupHeaderLine($title, count($members), $date, $w), 'green');
			$this->cli->msg('  ↻ graveyard resurrect --workspace ' . substr($gid, 0, 8), 'blue');
			foreach ($members as $t) { $this->printLsEntry($t, $w, $home, 4); }
		}
		if ($groups && $loose) { $this->cli->msg(''); }
		foreach ($loose as $t) { $this->printLsEntry($t, $w, $home, 0); }
	}

	protected function printLsEntry(array $t, int $width, string $home, int $indent, string $marker = ''): void {
		// A resurrected session keeps its tombstone, so distinguish "buried" from
		// "archived but running again" rather than implying it is still down.
		if (!empty($t['live'])) {
			$marker = trim($marker . ' ↑') ;
		}
		$lines = $this->lsEntryLines($t, $width, $home, $indent, $marker);
		$this->cli->msg($lines['primary']);
		if ($lines['secondary'] !== null) { $this->cli->msg($lines['secondary'], 'cyan'); }
	}

	/** PURE: split tombstones into [group_id => members[], loose[]], preserving order. */
	public function groupTombstones(array $tombs): array {
		$groups = [];
		$loose  = [];
		foreach ($tombs as $t) {
			$gid = $t['group_id'] ?? null;
			if ($gid) { $groups[$gid][] = $t; }
			else { $loose[] = $t; }
		}
		foreach ($groups as &$members) {
			usort($members, fn($a, $b) => ($a['group_pos'] ?? 0) <=> ($b['group_pos'] ?? 0));
		}
		unset($members);
		return [$groups, $loose];
	}


	/**
	 * Search buried tombstones by a case-insensitive term across the human-meaningful
	 * metadata (workspace_title/tab_title/cwd/summary) plus the title of the workspace a
	 * session was buried with. group_title is in there because a plot's own name is
	 * stamped at the bury and can differ from every member's per-session titles — without
	 * it, a workspace is unfindable by its name. With $fullText, also grep the rendered
	 * transcript body for tombstones whose metadata didn't already match.
	 *
	 * Each hit is stamped with `match_scope`: 'session' when the term hit that session's
	 * own fields (tab_title/cwd/summary, or the transcript) and 'group' when only the
	 * plot-level ones did (group_title/workspace_title — for a grouped session those name
	 * the workspace, not the session, and every member carries them). So a plot named for
	 * the term surfaces whole, and display can still tell which member you actually meant.
	 * A loose session has no plot, so its workspace_title counts as its own. Sorted
	 * newest-first (buried_at desc).
	 */
	public function searchTombstones(string $term, bool $fullText = false): array {
		$needle = mb_strtolower(trim($term));
		$tombs  = $this->tombstones();
		$hits   = [];
		foreach ($tombs as $t) {
			if ($needle === '') { continue; }
			$grouped = !empty($t['group_id']);
			$own = mb_strtolower(implode(' ', array_merge(
				$grouped ? [] : [(string) ($t['workspace_title'] ?? '')],
				[
					(string) ($t['tab_title'] ?? ''),
					(string) ($t['cwd'] ?? ''),
					(string) ($t['summary'] ?? ''),
				]
			)));
			if (str_contains($own, $needle)) { $hits[] = $t + ['match_scope' => 'session']; continue; }

			$plot = $grouped
				? mb_strtolower(((string) ($t['group_title'] ?? '')) . ' ' . ((string) ($t['workspace_title'] ?? '')))
				: '';
			if (trim($plot) !== '' && str_contains($plot, $needle)) { $hits[] = $t + ['match_scope' => 'group']; continue; }

			if ($fullText) {
				// ensureTranscript, not transcriptPath: a codex archive is a raw rollout until
				// something renders it, and a body nobody rendered is a body nobody can grep.
				$tp = $this->ensureTranscript($t);
				if (is_file($tp) && $this->fileContains($tp, $needle)) { $hits[] = $t + ['match_scope' => 'session']; }
			}
		}
		usort($hits, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		return $hits;
	}

	/** Streamed case-insensitive substring scan, preserving chunk overlap for line breaks. */
	protected function fileContains(string $path, string $needleLower): bool {
		$h = @fopen($path, 'r');
		if (!$h) { return false; }
		$found = false;
		$tail = '';
		$overlap = max(0, strlen($needleLower) - 1);
		while (($chunk = fread($h, 8192)) !== false && $chunk !== '') {
			$scan = $tail . $chunk;
			if (str_contains(mb_strtolower($scan), $needleLower)) { $found = true; break; }
			$tail = $overlap ? substr($scan, -$overlap) : '';
		}
		fclose($h);
		return $found;
	}

	/**
	 * PURE: a search hit reduced to the stable JSON-friendly field set. Pass $matched to
	 * append the flag search uses when a row is a plot sibling that rode along unmatched;
	 * omit it (ls, and the legacy flat search rows) to keep the original key set.
	 */
	public function searchRowJson(array $t, ?bool $matched = null): array {
		$row = [
			'session_id'      => $t['session_id'] ?? '',
			'workspace_title' => $t['workspace_title'] ?? '',
			'tab_title'       => $t['tab_title'] ?? '',
			'cwd'             => $t['cwd'] ?? '',
			'summary'         => $t['summary'] ?? '',
			'buried_at'       => $t['buried_at'] ?? '',
			'last_active'     => $t['last_active'] ?? null,
			// Which agent this session belongs to. Structured output is a VIEW (AGENTS.md):
			// a codex row was indistinguishable from a Claude one here — only `live_agent`
			// said so, and only while the session happened to be running.
			'agent'           => $this->tombstoneAgent($t),
			// resurrect keeps the tombstone so it can be resurrected again, so a row can
			// describe a session that is running right now. Say which.
			'live'            => (bool) ($t['live'] ?? false),
		];
		if (!empty($t['live_agent'])) { $row['live_agent'] = $t['live_agent']; }
		// What this archive could NOT preserve. Structured output is a VIEW: a fact the
		// text path warns about and the JSON omits is the same bug in machine-readable
		// form. Only emitted when true, so every other row keeps its exact shape.
		if ($this->agentOptsUnknownWarning($t) !== null) { $row['agent_opts_unknown'] = true; }
		if ($matched !== null) { $row['matched'] = $matched; }
		return $row;
	}

	/**
	 * PURE. Promote search hits from a flat session list to the same grouped view ls
	 * shows: any hit that belongs to a buried workspace pulls in its WHOLE plot, because
	 * the plot is the resurrect unit — you want the siblings and the group id, not one
	 * orphaned row. Returns
	 *   ['workspaces' => [['group_id','title','buried_at','sessions'=>members (group_pos
	 *                      order), 'matched'=>[session_id => true]]],
	 *    'sessions'   => loose hits (newest-first, as given)]
	 * Plots sort newest-first by their newest member; $hits order is otherwise preserved.
	 */
	public function expandSearchHits(array $hits, array $allTombs): array {
		$matchedIds = [];
		$groupIds   = [];
		$loose      = [];
		foreach ($hits as $t) {
			// A member that only matched via its plot's shared title isn't what you were
			// looking for — it rides along like any other sibling, unflagged.
			if (($t['match_scope'] ?? 'session') !== 'group') {
				$matchedIds[(string) ($t['session_id'] ?? '')] = true;
			}
			$gid = $t['group_id'] ?? null;
			if ($gid) { $groupIds[$gid] = true; }
			else { $loose[] = $t; }
		}

		$workspaces = [];
		foreach (array_keys($groupIds) as $gid) {
			$members = array_values(array_filter($allTombs, fn($t) => ($t['group_id'] ?? null) === $gid));
			usort($members, fn($a, $b) => ($a['group_pos'] ?? 0) <=> ($b['group_pos'] ?? 0));
			if (!$members) { continue; }
			$matched = [];
			$newest  = '';
			foreach ($members as $m) {
				$sid = (string) ($m['session_id'] ?? '');
				if (isset($matchedIds[$sid])) { $matched[$sid] = true; }
				$at = (string) ($m['buried_at'] ?? '');
				if (strcmp($at, $newest) > 0) { $newest = $at; }
			}
			$workspaces[] = [
				'group_id'  => $gid,
				'title'     => $members[0]['group_title'] ?? '',
				'buried_at' => substr((string) ($members[0]['buried_at'] ?? ''), 0, 10),
				'newest'    => $newest,
				'sessions'  => $members,
				'matched'   => $matched,
			];
		}
		usort($workspaces, fn($a, $b) => strcmp($b['newest'], $a['newest']));

		return ['workspaces' => $workspaces, 'sessions' => $loose];
	}

	/**
	 * PURE. search --json: the same {workspaces,sessions} shape as ls --json, so one
	 * consumer handles both, plus a `matched` flag on every session row.
	 */
	public function searchJson(array $hits, array $allTombs): array {
		$grouped = $this->expandSearchHits($hits, $allTombs);
		$out     = ['workspaces' => [], 'sessions' => []];
		foreach ($grouped['workspaces'] as $ws) {
			$out['workspaces'][] = [
				'group_id' => $ws['group_id'],
				'title'    => $ws['title'],
				'sessions' => array_map(
					fn($t) => $this->searchRowJson($t, isset($ws['matched'][(string) ($t['session_id'] ?? '')])),
					$ws['sessions']
				),
			];
		}
		$out['sessions'] = array_map(fn($t) => $this->searchRowJson($t, true), $grouped['sessions']);
		return $out;
	}

	public function resolveTombstone(string $prefix): ?array {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		$matches = array_values(array_filter($tombs, fn($t) => str_starts_with($t['session_id'], $prefix)));
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$this->cli->err("Ambiguous id '{$prefix}' — matches " . count($matches) . " tombstones:");
			foreach ($matches as $m) { $this->cli->msg('  ' . substr($m['session_id'], 0, 12) . '  ' . $m['summary']); }
		}
		return null;
	}

	/**
	 * Resolve a buried tombstone by fuzzy reference: exact session-id, unique session-id
	 * prefix, OR a workspace/tab title substring (case-insensitive) — the same resolution
	 * order as the live-session matchIdentifier(). Returns a result shape (no side-channel
	 * state): ['match' => ?tombstone, 'candidates' => tombstones[] (the ambiguous set),
	 * 'ambiguous' => bool]. Ambiguity is reported and yields match=null, never auto-picked.
	 */
	public function resolveTombstoneFuzzy(string $ref): array {
		$none = ['match' => null, 'candidates' => [], 'ambiguous' => false];
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs || $ref === '') { return $none; }

		// 1) exact session-id
		$exact = array_values(array_filter($tombs, fn($t) => ($t['session_id'] ?? null) === $ref));
		if (count($exact) === 1) { return ['match' => $exact[0], 'candidates' => [], 'ambiguous' => false]; }

		// 2) unique session-id prefix (explicit so prefix wins over title, stays backward-compatible)
		$prefix = array_values(array_filter($tombs, fn($t) => str_starts_with((string) ($t['session_id'] ?? ''), $ref)));
		if (count($prefix) === 1) { return ['match' => $prefix[0], 'candidates' => [], 'ambiguous' => false]; }
		if (count($prefix) > 1) {
			return ['match' => null, 'candidates' => $prefix, 'ambiguous' => true];
		}

		// 3) title substring via the shared matcher (workspace_title/tab_title)
		$byTitle = $this->matchIdentifier($tombs, $ref);
		if (count($byTitle) === 1) { return ['match' => $byTitle[0], 'candidates' => [], 'ambiguous' => false]; }
		if (count($byTitle) > 1) {
			return ['match' => null, 'candidates' => $byTitle, 'ambiguous' => true];
		}

		return $none;
	}

	/** Convenience: the matched tombstone, or null when none/ambiguous. */
	public function findTombstone(string $ref): ?array {
		return $this->resolveTombstoneFuzzy($ref)['match'];
	}

	protected function reportAmbiguousTombstones(string $ref, array $matches): void {
		$this->cli->msg("'{$ref}' is ambiguous — matches " . count($matches) . ' buried session(s):', 'yellow');
		foreach ($matches as $m) {
			$this->cli->msg(sprintf(
				'  %s  %-24.24s  %.40s  %s',
				substr((string) $m['session_id'], 0, 8),
				$m['workspace_title'] ?? '',
				$m['tab_title'] ?? '',
				substr((string) ($m['buried_at'] ?? ''), 0, 10)
			));
		}
	}

	public function printSearch(string $term, bool $json, bool $fullText): void {
		$hits = $this->searchTombstones($term, $fullText);
		$all  = $this->readIndex()['tombstones'] ?? [];
		if ($json) {
			echo json_encode($this->searchJson($hits, $all), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
			return;
		}
		if (!$hits) { $this->cli->msg("No buried sessions match '{$term}'.", 'yellow'); return; }

		$w       = $this->termWidth();
		$home    = getenv('HOME') ?: '';
		$grouped = $this->expandSearchHits($hits, $all);

		foreach ($grouped['workspaces'] as $ws) {
			$title = $ws['title'] !== '' ? $ws['title'] : '(workspace)';
			$this->cli->msg($this->groupHeaderLine($title, count($ws['sessions']), $ws['buried_at'], $w), 'green');
			$this->cli->msg('  ↻ graveyard resurrect --workspace ' . substr((string) $ws['group_id'], 0, 8), 'blue');
			foreach ($ws['sessions'] as $t) {
				$hit = isset($ws['matched'][(string) ($t['session_id'] ?? '')]);
				$this->printLsEntry($t, $w, $home, 4, $hit ? self::MATCH_MARK : ' ');
			}
		}
		if ($grouped['workspaces'] && $grouped['sessions']) { $this->cli->msg(''); }
		foreach ($grouped['sessions'] as $t) { $this->printLsEntry($t, $w, $home, 0); }
	}

	public function showTombstone(string $prefix): void {
		$res = $this->resolveTombstoneFuzzy($prefix);
		$t = $res['match'];
		if (!$t) {
			if ($res['ambiguous']) { $this->cli->exitErr("'{$prefix}' is ambiguous — narrow it or pass a full session-id."); }
			$this->cli->exitErr("No buried session matches '{$prefix}'.");
		}
		$path = $this->ensureTranscript($t);
		if (!is_file($path)) { $this->cli->exitErr("Transcript missing: {$path}"); }
		$editor = trim((string) shell_exec('command -v code 2>/dev/null'));
		if ($editor) { shell_exec('code -r ' . escapeshellarg($path)); }
		else { $ed = getenv('EDITOR') ?: 'vi'; $this->cli->runCommand($ed . ' ' . escapeshellarg($path)); }
		$this->cli->successMsg("Opened {$path}");
	}

	# =========================================================================
	# graveyard serve (dotfiles-vn5.1): PHP built-in server + tiny JSON API,
	# progressively enhancing index.html's copy-command UI into live mutation.
	# =========================================================================

	/**
	 * PURE-ish (fs mutations only, no HTTP/socket I/O). Handle one JSON API
	 * request for the router `php -S` runs. Never shells out with user input —
	 * ids are resolved fuzzily against the current store, same as the CLI
	 * verbs, before any mutation touches disk. Returns ['status'=>int,'body'=>array].
	 */
	public function handleApi(string $method, string $path, array $body): array {
		if ($method !== 'POST') {
			return ['status' => 405, 'body' => ['ok' => false, 'error' => 'method not allowed']];
		}
		if ($path !== '/api/rename' && $path !== '/api/delete') {
			return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not found']];
		}

		$scope = (string) ($body['scope'] ?? '');
		$id    = (string) ($body['id'] ?? '');
		if ($scope !== 'session' && $scope !== 'group') {
			return ['status' => 400, 'body' => ['ok' => false, 'error' => "invalid scope '{$scope}'"]];
		}

		if ($path === '/api/rename') {
			$name = trim((string) ($body['name'] ?? ''));
			if ($name === '') {
				return ['status' => 400, 'body' => ['ok' => false, 'error' => 'name required']];
			}
			if ($scope === 'session') {
				$t = $this->resolveTombstoneFuzzy($id)['match'];
				if (!$t) { return ['status' => 404, 'body' => ['ok' => false, 'error' => 'session not found']]; }
				$this->setSessionName((string) $t['session_id'], $name);
			} else {
				$m = $this->resolveGroup($id);
				if (!$m) { return ['status' => 404, 'body' => ['ok' => false, 'error' => 'group not found']]; }
				$this->setGroupName((string) $m['group_id'], $name);
			}
			return ['status' => 200, 'body' => ['ok' => true, 'name' => $name]];
		}

		if ($path === '/api/delete') {
			if ($scope === 'session') {
				$t = $this->resolveTombstoneFuzzy($id)['match'];
				if (!$t) { return ['status' => 404, 'body' => ['ok' => false, 'error' => 'session not found']]; }
				$this->purgeSession((string) $t['session_id']);
			} else {
				$m = $this->resolveGroup($id);
				if (!$m) { return ['status' => 404, 'body' => ['ok' => false, 'error' => 'group not found']]; }
				$this->purgeGroup((string) $m['group_id']);
			}
			return ['status' => 200, 'body' => ['ok' => true]];
		}

		return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not found']]; // unreachable: guarded above
	}

	/**
	 * Verb. Boot PHP's built-in server rooted at the store, serving index.html +
	 * page-data/ as static files plus the JSON API (via bin/graveyard_router.php)
	 * for live rename/delete. BIND is always 127.0.0.1 (the Host header used for
	 * display/open doesn't affect what the server accepts connections on). The
	 * browser is opened at a pretty `http://<host>:<port>/` — default host
	 * `graveyard.localhost`, which every modern browser resolves to 127.0.0.1
	 * with zero config (the *.localhost TLD is reserved for loopback, RFC 6761)
	 * — no /etc/hosts edit, no sudo. CLI tools (curl etc.) may not resolve
	 * *.localhost via the OS resolver, so the plain IP URL is also printed as a
	 * fallback. Regenerates the page first so it's fresh, then blocks until the
	 * server exits (Ctrl+C).
	 */
	public function serve(int $port = 8787, string $host = 'graveyard.localhost'): string {
		return $this->page(false, $port, $host);
	}

	# =========================================================================
	# graveyard page (dotfiles-06t): serve-only. `page` ensures the loopback
	# server is up (spawn detached / reuse via a persisted port) and opens the
	# URL; `page --no-open` prints it; `serve` is a quiet alias. The page is
	# rendered FRESH per request by bin/graveyard_router.php — nothing is
	# written to the store, so a just-buried session shows up on refresh.
	# =========================================================================

	/** Where the running-server state (port/pid/url) is persisted. */
	public function serveStatePath(): string { return $this->storeRoot() . '/.serve.json'; }

	/** TRUE if something is accepting TCP connections on 127.0.0.1:$port. */
	protected function serverListening(int $port): bool {
		$fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
		if ($fp) { fclose($fp); return true; }
		return false;
	}

	/**
	 * I/O. One-time cleanup of the pre-serve-only artifacts: the static
	 * index.html snapshot (the stale-page bug) and the page-data/*.js dir (the
	 * router renders transcripts on demand now). Safe to call repeatedly.
	 */
	protected function cleanStaticArtifacts(): void {
		@unlink($this->pageFilePath());
		$dir = $this->pageDataDir();
		if (is_dir($dir)) {
			foreach (glob($dir . '/*.js') ?: [] as $f) { @unlink($f); }
			@rmdir($dir);
		}
	}

	/**
	 * Absolute path to the `php -S` router script. It lives in bin/ (executed by
	 * the server), while this class lives in src/ — so it is one level up from
	 * __DIR__, NOT beside it. Resolving it wrong makes `graveyard page`/`serve`
	 * fail at request time with "Failed opening required .../src/graveyard_router.php".
	 */
	public function routerPath(): string {
		return dirname(__DIR__) . '/bin/graveyard_router.php';
	}

	/**
	 * I/O. Ensure a loopback server is running and return its pretty URL. Reuses
	 * a healthy one recorded in serveStatePath() (so bookmarks survive re-runs on
	 * the same port); otherwise spawns `php -S` detached (nohup, survives this
	 * CLI exiting), waits until it accepts connections, and persists its port/pid.
	 * BIND is always 127.0.0.1; the *.localhost host is only for a pretty,
	 * zero-config URL (RFC 6761 reserves it for loopback). $portExplicit means the
	 * caller passed --port, so honor it over any persisted port.
	 */
	public function ensureServer(int $port, string $host, bool $portExplicit = false): array {
		$state = [];
		if (is_file($this->serveStatePath())) {
			$decoded = json_decode((string) @file_get_contents($this->serveStatePath()), true);
			if (is_array($decoded)) { $state = $decoded; }
		}
		$statePort = (int) ($state['port'] ?? 0);

		// Reuse a healthy server on the persisted port (unless --port overrides it).
		if (!$portExplicit && $statePort > 0 && $this->serverListening($statePort)) {
			$stateHost = (string) ($state['host'] ?? $host);
			return ['url' => "http://{$stateHost}:{$statePort}/", 'port' => $statePort, 'reused' => true];
		}

		$spawnPort = (!$portExplicit && $statePort > 0) ? $statePort : $port;
		if ($this->serverListening($spawnPort)) {
			// Already up (e.g. persisted pid died but the listener lives) — reuse it.
			$this->writeServeState($spawnPort, $host, 0);
			return ['url' => "http://{$host}:{$spawnPort}/", 'port' => $spawnPort, 'reused' => true];
		}

		$this->cleanStaticArtifacts();
		$root   = $this->storeRoot();
		$router = $this->routerPath();
		$log    = $root . '/.serve.log';
		$serve  = sprintf('php -S 127.0.0.1:%d -t %s %s', $spawnPort, escapeshellarg($root), escapeshellarg($router));
		$spawn  = sprintf('nohup %s > %s 2>&1 & echo $!', $serve, escapeshellarg($log));
		$pid    = (int) trim((string) shell_exec('sh -c ' . escapeshellarg($spawn)));

		for ($i = 0; $i < 30; $i++) {
			if ($this->serverListening($spawnPort)) { break; }
			usleep(100000); // 100ms, up to ~3s
		}
		if (!$this->serverListening($spawnPort)) {
			$this->cli->exitErr("Could not start the graveyard server on port {$spawnPort} (see {$log}).");
		}
		$this->writeServeState($spawnPort, $host, $pid);
		return ['url' => "http://{$host}:{$spawnPort}/", 'port' => $spawnPort, 'reused' => false, 'pid' => $pid];
	}

	/** I/O. Persist the running-server descriptor for reuse across re-runs. */
	protected function writeServeState(int $port, string $host, int $pid): void {
		@file_put_contents($this->serveStatePath(), json_encode([
			'port' => $port, 'host' => $host, 'pid' => $pid,
			'url' => "http://{$host}:{$port}/", 'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
		], JSON_PRETTY_PRINT));
	}

	/**
	 * Verb. `graveyard page`: ensure the loopback server is up (spawn/reuse) and
	 * open the fresh page in the browser. `--no-open` (and the `serve` alias) skip
	 * opening and just print the URL. Returns the URL. Does NOT block — the server
	 * runs detached in the background and is reused by later invocations.
	 */
	public function page(bool $openInBrowser = true, int $port = 8787, string $host = 'graveyard.localhost', bool $portExplicit = false): string {
		$info = $this->ensureServer($port, $host, $portExplicit);
		$url  = (string) $info['url'];
		$ip   = "http://127.0.0.1:{$info['port']}/";

		$verb = $info['reused'] ? 'Reusing the graveyard server' : 'Serving the graveyard';
		$this->cli->successMsg("{$verb} at {$url}");
		if ($url !== $ip) {
			$this->cli->msg("  (if that host doesn't resolve, use {$ip})", 'yellow');
		}

		if ($openInBrowser) {
			$opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
			if (trim((string) shell_exec('command -v ' . $opener . ' 2>/dev/null')) !== '') {
				shell_exec($opener . ' ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
			} else {
				$this->cli->msg("No '{$opener}' on PATH — open {$url} manually.", 'yellow');
			}
		}
		return $url;
	}

	# =========================================================================
	# graveyard serve --stop (dotfiles-xr9): shut down the detached loopback
	# server. Reads the persisted descriptor, verifies the recorded pid really
	# is our `php -S` before signalling it (recycled PIDs are never blindly
	# killed), SIGTERMs it, waits for the port to go quiet, and clears state.
	# =========================================================================

	/** I/O (read-only). Parse the persisted server descriptor, or null when missing/unparsable. */
	public function readServeState(): ?array {
		$path = $this->serveStatePath();
		if (!is_file($path)) { return null; }
		$decoded = json_decode((string) @file_get_contents($path), true);
		return is_array($decoded) ? $decoded : null;
	}

	/** TRUE if a process with this pid currently exists (signal-0 probe). */
	protected function pidRunning(int $pid): bool {
		if ($pid <= 0) { return false; }
		if (function_exists('posix_kill')) { return @posix_kill($pid, 0); }
		return trim((string) shell_exec('ps -p ' . (int) $pid . ' -o pid= 2>/dev/null')) !== '';
	}

	/**
	 * TRUE if pid's command line looks like our loopback server on $port. Guards
	 * against killing a recycled PID: a live pid whose command isn't our
	 * `php -S 127.0.0.1:<port>` is treated as "not ours".
	 */
	protected function pidIsOurServer(int $pid, int $port): bool {
		if ($pid <= 0 || !$this->pidRunning($pid)) { return false; }
		$out = (string) shell_exec('ps -p ' . (int) $pid . ' -o command= 2>/dev/null');
		return strpos($out, "php -S 127.0.0.1:{$port}") !== false;
	}

	/** I/O. Find the pid of our loopback server bound to $port, or null. Guards blind kills. */
	protected function findServerPid(int $port): ?int {
		$needle = "php -S 127.0.0.1:{$port}";
		$out    = (string) shell_exec('pgrep -f ' . escapeshellarg($needle) . ' 2>/dev/null');
		foreach (preg_split('/\s+/', trim($out)) ?: [] as $cand) {
			$p = (int) $cand;
			if ($p > 0 && $this->pidIsOurServer($p, $port)) { return $p; }
		}
		return null;
	}

	/** I/O. Send a signal to a pid. Wrapped for test seams. */
	protected function signalPid(int $pid, int $signal): void {
		if (function_exists('posix_kill')) { @posix_kill($pid, $signal); return; }
		shell_exec('kill -' . (int) $signal . ' ' . (int) $pid . ' 2>/dev/null');
	}

	/**
	 * Verb. `graveyard serve --stop` (and `page --stop`): shut down the detached
	 * loopback server. With no recorded server it's a no-op success — unless a
	 * stray listener holds the port, which is reported and left alone. A recorded
	 * pid is verified to actually be our `php -S <port>` before any signal, so a
	 * recycled PID or a different listener is reported, never killed. SIGTERMs,
	 * waits up to ~2s for the port to go quiet, and clears state on success.
	 * Returns an exit code: 0 on success/no-op, 1 only on genuine failure.
	 */
	public function stopServer(int $port = 8787, bool $portExplicit = false): int {
		$state     = $this->readServeState();
		$statePort = (int) ($state['port'] ?? 0);
		$target    = $portExplicit ? $port : ($statePort > 0 ? $statePort : $port);

		if ($state === null) {
			$this->cli->msg('No graveyard server recorded.', 'yellow');
			if (!$this->serverListening($target)) { return 0; }
			$this->cli->msg("  (something is listening on port {$target}, but graveyard didn't start it — leaving it alone)", 'yellow');
			return 0;
		}

		$pid = (int) ($state['pid'] ?? 0);

		// A recorded pid that is NOT our server → never kill it (recycled PID / other listener).
		if ($pid > 0 && !$this->pidIsOurServer($pid, $target)) {
			if ($this->serverListening($target)) {
				$this->cli->msg("Recorded pid {$pid} is no longer the graveyard server; something else holds port {$target} — leaving it alone.", 'yellow');
			} else {
				@unlink($this->serveStatePath());
				$this->cli->successMsg("No graveyard server running (cleared stale state for port {$target}).");
			}
			return 0;
		}

		// pid==0 (we reused a listener we didn't spawn): only stop it if we can identify it as ours.
		if ($pid <= 0) {
			if (!$this->serverListening($target)) {
				@unlink($this->serveStatePath());
				$this->cli->successMsg("No graveyard server running (cleared stale state for port {$target}).");
				return 0;
			}
			$found = $this->findServerPid($target);
			if ($found === null) {
				$this->cli->msg("A server holds port {$target} but graveyard can't confirm it started it — leaving it alone.", 'yellow');
				return 0;
			}
			$pid = $found;
		}

		$sig = defined('SIGTERM') ? SIGTERM : 15;
		$this->signalPid($pid, $sig);
		for ($i = 0; $i < 20; $i++) {
			if (!$this->serverListening($target)) { break; }
			usleep(100000); // 100ms, up to ~2s
		}
		if ($this->serverListening($target)) {
			$this->cli->err("Sent SIGTERM to pid {$pid} but port {$target} is still listening.");
			return 1;
		}
		@unlink($this->serveStatePath());
		$this->cli->successMsg("Stopped the graveyard server on port {$target}.");
		return 0;
	}

	/**
	 * I/O (read-only). Render the whole overview page fresh from the CURRENT
	 * store — read the index, sort newest-first, stamp plot positions, and hand
	 * off to pageHtml(). This is the single render path the loopback server
	 * calls per request, so a session buried a moment ago shows up on the next
	 * refresh (no stale index.html snapshot). Never writes anything.
	 */
	public function renderStorePageHtml(): string {
		$tombs = $this->tombstones();
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		$tombs = $this->stampPlotPositions($tombs);
		return $this->pageHtml($tombs, gmdate('Y-m-d\TH:i:s\Z'), getenv('HOME') ?: '');
	}

	/**
	 * I/O (read-only). Render one session's transcript as its page-data JS
	 * (window.GYT[id] = "…"), read fresh from the archived transcript on disk.
	 * Returns null when no transcript is archived for the id, so the router can
	 * answer 404 and the modal shows "(no transcript lies here)". Never writes.
	 *
	 * A markdown archive is presented as /export-style TUI text (dotfiles-0p4): the modal
	 * drops this into a `<pre>`, where markdown source reads as a wall of literal `**You:**`
	 * markers with no hanging indents. The FILE stays markdown — `graveyard show` opens it
	 * in an editor that previews it, and full-text search greps the source. Legacy /export
	 * archives are already in this shape and pass through untouched.
	 */
	public function renderTranscriptJs(string $id): ?string {
		if ($id === '') { return null; }
		// Render a codex rollout on demand — the modal said "(no transcript lies here)" for
		// every codex headstone otherwise.
		//
		// Resolved from the session's own meta.json, NOT through tombstones(): `page` and
		// `serve` are store-only verbs that bin/graveyard deliberately does not gate on a
		// cmux ping, and tombstones() annotates liveness by shelling out to cmux/lsof/ps.
		// Going through it made the page server require a running cmux to show a transcript.
		// No annotation is involved in fetching one record, so the store copy is the right
		// source here — the lock-step rule is about views of the COLLECTION not diverging.
		$tp = $this->transcriptPath($id);
		if (!is_file($tp)) {
			$t = $this->sessionMeta($id);
			if ($t !== null) { $tp = $this->ensureTranscript($t); }
		}
		if (!is_file($tp)) { return null; }
		$text = (new Helpers\TuiTranscript())->fromMarkdown((string) file_get_contents($tp));
		return $this->pageTranscriptJs($id, $text);
	}

	/**
	 * PURE. A transcript as an injectable page-data JS file: assigns window.GYT[id].
	 * json_encode does the string escaping; JSON_HEX_TAG prevents a transcript
	 * containing "</script>" from breaking out of the script block, and
	 * INVALID_UTF8_SUBSTITUTE keeps mangled bytes from failing the encode.
	 */
	public function pageTranscriptJs(string $id, string $text): string {
		return 'window.GYT=window.GYT||{};GYT['
			. json_encode($id)
			. ']='
			. json_encode($text, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
			. ';';
	}

	/**
	 * PURE. A family plot's accent hue, derived deterministically from the group
	 * id: the same family always wears the same color across regenerations.
	 * Picks from a muted palette of well-separated hues (used via CSS
	 * --plot-hue for the fence, legend, and a faint bg tint).
	 */
	public function plotHue(string $groupId): int {
		$palette = [30, 60, 95, 160, 205, 255, 300, 345];
		return $palette[crc32($groupId) % count($palette)];
	}

	/**
	 * PURE. How many columns a family plot lays its members across — its "shape".
	 * Derived from the group id + a per-page seed (the generation stamp), so the
	 * arrangement is stable within a page but shuffles across regenerations:
	 * sometimes a single row (cols = n), sometimes a stack (1), sometimes a
	 * square/rectangle in between. Always 1..n. Singletons are always 1 column.
	 */
	public function plotColumns(string $groupId, int $memberCount, string $seed): int {
		if ($memberCount <= 1) { return 1; }
		$cands = array_values(array_unique([1, 2, (int) ceil(sqrt($memberCount)), $memberCount]));
		sort($cands);
		$pick = $cands[crc32($groupId . '|' . $seed) % count($cands)];
		return max(1, min($pick, $memberCount));
	}

	/** Number of headstone "crown" shapes (top silhouettes) defined in the page CSS. */
	public const STONE_CROWNS = 6;

	/**
	 * PURE. Which crown shape a headstone wears — its top silhouette (rounded
	 * arch, gothic bevel, squircle, scoop, …). Derived from the session id so a
	 * grave keeps the same headstone across regenerations. Bottom stays square
	 * (the stone is buried). Returns 0..STONE_CROWNS-1 → CSS class crown-N.
	 */
	public function stoneCrown(string $sessionId): int {
		return crc32($sessionId) % self::STONE_CROWNS;
	}

	/**
	 * PURE. Whether a headstone should wear a "cracked" (chipped) silhouette —
	 * decided deterministically from its title. Sessions whose work was about a
	 * bug/fix/failure/error/breakage get a fractured top edge, so trouble reads
	 * at a glance and stays stable across regenerations (no per-render random).
	 */
	public function stoneCracked(string $title): bool {
		return (bool) preg_match('/bug|fix|fail|error|broken/i', $title);
	}

	/**
	 * PURE. Horizontal offset (px, 0..139) for the perimeter fence's repeating
	 * mask, seeded from a page-level string so the aged pickets (bent/broken,
	 * baked into the 140px tile) land at a stable-but-varied spot per store —
	 * same crc32 philosophy as stoneCrown/plotHue, page-level seed.
	 */
	public function fenceShift(string $seed): int {
		return crc32($seed) % 140;
	}

	/**
	 * PURE. Order tombstones into render units for the page: loose stones and
	 * "family plots" (workspace groups), newest-first. A plot's sort key is its
	 * newest member's buried_at; its members keep their original tab order
	 * (group_pos, via groupTombstones). Ties keep input order.
	 */
	public function pageUnits(array $tombs): array {
		[$groups, $loose] = $this->groupTombstones($tombs);
		$units = [];
		$ord = 0;
		foreach ($loose as $t) {
			$units[] = ['type' => 'stone', 'sort' => (string) ($t['buried_at'] ?? ''), 'tomb' => $t, 'ord' => $ord++];
		}
		foreach ($groups as $gid => $members) {
			$max = '';
			foreach ($members as $m) {
				$b = (string) ($m['buried_at'] ?? '');
				if (strcmp($b, $max) > 0) { $max = $b; }
			}
			$title = trim((string) ($members[0]['group_title'] ?? ''));
			$units[] = [
				'type'    => 'plot',
				'sort'    => $max,
				'title'   => $title !== '' ? $title : '(family plot)',
				'gid'     => (string) $gid,
				'gid8'    => substr((string) $gid, 0, 8),
				'hue'     => $this->plotHue((string) $gid),
				'members' => $members,
				'ord'     => $ord++,
			];
		}
		usort($units, fn($a, $b) => strcmp($b['sort'], $a['sort']) ?: ($a['ord'] <=> $b['ord']));
		return array_map(function ($u) { unset($u['ord']); return $u; }, $units);
	}

	/**
	 * PURE. Map a workspace manifest's agent members to their layout positions:
	 * claude_session_id => ['pane' => pane_index, 'tab' => index_in_pane] (0-based).
	 * Covers both claude and codex members (a codex member stores its codex session id
	 * in claude_session_id — dotfiles-5p5). Non-agent surfaces and unbound entries skip.
	 */
	public function manifestPositions(array $manifest): array {
		$out = [];
		foreach ($manifest['layout'] ?? [] as $e) {
			$kind = $e['kind'] ?? '';
			if ($kind !== 'claude' && $kind !== 'codex') { continue; }
			$sid = (string) ($e['claude_session_id'] ?? '');
			if ($sid === '') { continue; }
			$out[$sid] = [
				'pane' => (int) ($e['pane_index'] ?? 0),
				'tab'  => (int) ($e['index_in_pane'] ?? 0),
			];
		}
		return $out;
	}

	/**
	 * I/O. Stamp each grouped tombstone with its 'plot_pos' (pane/tab) read from the
	 * workspace group's manifest, when one is on disk. Ungrouped tombs and groups
	 * without a manifest are left untouched.
	 */
	protected function stampPlotPositions(array $tombs): array {
		$gids = [];
		foreach ($tombs as $t) {
			$g = (string) ($t['group_id'] ?? '');
			if ($g !== '') { $gids[$g] = true; }
		}
		$posByGid = [];
		foreach (array_keys($gids) as $gid) {
			$mp = $this->manifestPath($gid);
			if (!is_file($mp)) { continue; }
			$m = json_decode((string) @file_get_contents($mp), true);
			if (is_array($m)) { $posByGid[$gid] = $this->manifestPositions($m); }
		}
		foreach ($tombs as &$t) {
			$g   = (string) ($t['group_id'] ?? '');
			$sid = (string) ($t['session_id'] ?? '');
			if ($g !== '' && isset($posByGid[$g][$sid])) { $t['plot_pos'] = $posByGid[$g][$sid]; }
		}
		unset($t);
		return $tombs;
	}

	/**
	 * PURE. One headstone <button>: title + buried-date/short-id meta, with the
	 * modal's display strings riding in escaped data-* attributes (the modal fills
	 * via textContent — no HTML injection). A tombstone carrying a plot_pos (pane/
	 * tab from the workspace manifest, 0-based) shows a 1-based [P…,T…] suffix on
	 * both the stone meta and the modal dates line.
	 */
	protected function stoneHtml(array $t, int $i, string $home): string {
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$sid    = (string) ($t['session_id'] ?? '');
		$sid8   = substr($sid, 0, 8);
		$title  = $this->titleizeSummary($t, $home);
		$cwd    = $this->shortenCwd((string) ($t['cwd'] ?? ''), $home, 200);
		$ws     = trim((string) ($t['workspace_title'] ?? ''));
		$tab    = $this->stripGlyph((string) ($t['tab_title'] ?? ''));
		$buried = substr((string) ($t['buried_at'] ?? ''), 0, 10);
		$active = substr((string) ($t['last_active'] ?? ''), 0, 10);
		$model  = (string) ($t['model'] ?? '');

		$pos = '';
		if (isset($t['plot_pos']['pane'], $t['plot_pos']['tab'])) {
			$pos = ' [P' . ((int) $t['plot_pos']['pane'] + 1) . ',T' . ((int) $t['plot_pos']['tab'] + 1) . ']';
		}

		// The modal shows the transcript path (display: ~/-collapsed; copy: FULL path).
		// Rendered here too, so the copy button hands over a path that exists.
		$tpath      = $this->ensureTranscript($t);
		$tpathShort = $home !== '' ? str_replace($home, '~', $tpath) : $tpath;

		$where = implode(' · ', array_filter([$cwd, $ws . ($tab !== '' ? ' / ' . $tab : '')], fn($p) => trim($p) !== ''));
		$dates = 'buried ' . $buried
			. ($active !== '' ? ' · last active ' . $active : '')
			. ($model !== '' ? ' · ' . $model : '')
			. $pos;
		$groupTitle = trim((string) ($t['group_title'] ?? ''));

		return $this->renderPartial('stone', [
			'I'           => (string) min($i, 20),
			'CROWN'       => 'crown-' . $this->stoneCrown($sid) . ($this->stoneCracked($title) ? ' cracked' : ''),
			'SID'         => $e($sid),
			'SID8'        => $e($sid8),
			'TITLE'       => $e($title),
			'WHERE'       => $e($where),
			'DATES'       => $e($dates),
			'TPATH'       => $e($tpath),
			'TPATH_SHORT' => $e($tpathShort),
			'GROUP_TITLE' => $e($groupTitle),
			'BURIED'      => $e($buried),
			'POS'         => $e($pos),
		]);
	}

	/**
	 * I/O. The inlined Alpine.js source (vendored asset), so the generated page
	 * stays self-contained — no external <script src>, which file:// and a strict
	 * CSP both forbid. Resolves the asset relative to this lib whether it lives in
	 * bin/ (pre-migration) or src/ (post-migration). Empty string if not found —
	 * the page still renders, just non-interactive (and the Alpine test fails loud).
	 */
	protected function alpineJs(): string {
		foreach ([
			dirname(__DIR__) . '/src/assets/alpine.min.js', // lib in bin/
			__DIR__ . '/assets/alpine.min.js',              // lib moved into src/
			__DIR__ . '/../src/assets/alpine.min.js',
		] as $p) {
			if (is_file($p)) { return rtrim((string) file_get_contents($p)); }
		}
		return '';
	}

	/**
	 * I/O. The page's HTML shell template (src/templates/graveyard-page.html),
	 * with %%SUMMARY%% / %%LISTING%% / %%ALPINE%% placeholders that pageHtml
	 * interpolates. Kept in a real .html file so it isn't a giant PHP string.
	 */
	protected function pageTemplate(): string {
		foreach ([
			dirname(__DIR__) . '/src/templates/graveyard-page.html', // lib in bin/
			__DIR__ . '/templates/graveyard-page.html',              // lib moved into src/
			__DIR__ . '/../src/templates/graveyard-page.html',
		] as $p) {
			if (is_file($p)) { return (string) file_get_contents($p); }
		}
		return '';
	}

	/** @var array<string,string> partial template cache */
	protected array $partialCache = [];

	/**
	 * I/O. Render a per-item HTML partial from src/templates/partials/<name>.html,
	 * substituting %%KEY%% placeholders with $vars (values must be pre-escaped by
	 * the caller). Cached per name. Keeps stone/plot markup out of PHP strings.
	 */
	protected function renderPartial(string $name, array $vars): string {
		if (!isset($this->partialCache[$name])) {
			$tpl = '';
			foreach ([
				dirname(__DIR__) . "/src/templates/partials/{$name}.html",
				__DIR__ . "/templates/partials/{$name}.html",
				__DIR__ . "/../src/templates/partials/{$name}.html",
			] as $p) {
				if (is_file($p)) { $tpl = rtrim((string) file_get_contents($p), "\n"); break; }
			}
			$this->partialCache[$name] = $tpl;
		}
		$map = [];
		foreach ($vars as $k => $v) { $map['%%' . $k . '%%'] = $v; }
		return strtr($this->partialCache[$name], $map);
	}

	/**
	 * PURE. Self-contained HTML overview: a full-width FIELD of compact headstones
	 * (auto-fill grid), with workspace groups fenced into "family plots" (a
	 * <fieldset> per group, legend = group title, members in tab order). Clicking
	 * a stone opens a <dialog> with the full card; the transcript loads JIT from
	 * page-data/<id>.js (see page()) and scrolls to the latest end. The modal's
	 * session id is click-to-copy (full id → clipboard, "copied ✓" flash).
	 * Display strings ride in escaped data-* attributes and the modal fills via
	 * textContent — no HTML injection from titles/cwds/transcripts. Units are
	 * rendered in pageUnits() order (newest-first). No external assets; one
	 * inline <script>.
	 */
	public function pageHtml(array $tombs, string $generatedAt, string $home = ''): string {
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$rows = [];
		$i = 0;
		foreach ($this->pageUnits($tombs) as $u) {
			if ($u['type'] === 'stone') {
				$rows[] = $this->stoneHtml($u['tomb'], $i++, $home);
				continue;
			}
			$stones = [];
			foreach ($u['members'] as $t) { $stones[] = $this->stoneHtml($t, $i++, $home); }
			// NOTE: the fieldset must NOT be display:grid (Chromium/WebKit render
			// grid fieldsets wrong) — the inner div carries the stone grid instead.
			$cols = $this->plotColumns((string) ($u['gid'] ?? ''), count($u['members']), $generatedAt);
			$rows[] = $this->renderPartial('plot', [
				'HUE'    => (string) (int) $u['hue'],
				'COLS'   => (string) $cols,
				'TITLE'  => $e($u['title']),
				'GID'    => $e((string) ($u['gid'] ?? '')),
				'GID8'   => $e((string) ($u['gid8'] ?? '')),
				'STONES' => implode("\n", $stones),
			]);
		}

		$count   = count($tombs);
		$listing = $rows
			? implode("\n", $rows)
			: '    <p class="none empty">🪦<br>The graveyard is empty — no sessions lie here yet.</p>';

		$summary = $count . ' session' . ($count === 1 ? '' : 's')
			. ' lie' . ($count === 1 ? 's' : '') . ' here · generated ' . $e($generatedAt);

		return strtr($this->pageTemplate(), [
			'%%SUMMARY%%'     => $summary,
			'%%LISTING%%'     => $listing,
			'%%FENCE_SHIFT%%' => (string) $this->fenceShift((string) $count),
			'%%ALPINE%%'      => $this->alpineJs(),
		]);
	}

	/** PURE. How a resurrect restored the session, for the success line. */
	protected function resurrectNote(array $t, string $mode): string {
		$agent = $this->tombstoneAgent($t);
		if ($mode === 'resume') {
			return $agent === 'codex' ? 'resumed via `codex resume`' : 'resumed via `claude --resume`';
		}
		return $agent === 'codex' ? 'codex is reading the archived rollout' : 'Claude is reading the transcript';
	}

	/**
	 * PURE. Where to bring a tombstone back: into the workspace it was buried from
	 * when that workspace still exists, else a fresh one.
	 *
	 * Returns ['mode' => 'in_place', 'workspace_id' => ..., 'pane_id' => ?string]
	 * or ['mode' => 'new_workspace'].
	 *
	 * Only UUIDs are honoured. A positional ref (workspace:32) stored where a uuid
	 * belongs is rejected rather than trusted: refs get reassigned as workspaces open
	 * and close, so one recorded at burial can name a different workspace by now —
	 * the same hazard that keeps tty out of the session joins.
	 *
	 * A missing PANE is not a reason to build a whole new workspace; the tab still
	 * belongs in that workspace, so we return it with pane_id null and let cmux place it.
	 */
	public function resolveResurrectTarget(array $tree, array $tomb): array {
		$wantWs = (string) ($tomb['home_workspace_id'] ?? '');
		if ($wantWs === '' || !$this->looksLikeUuid($wantWs)) {
			return ['mode' => 'new_workspace'];
		}

		$wantPane = (string) ($tomb['home_pane_id'] ?? '');
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				if (($ws['id'] ?? null) !== $wantWs) { continue; }

				$pane = null;
				if ($wantPane !== '' && $this->looksLikeUuid($wantPane)) {
					foreach ($ws['panes'] ?? [] as $p) {
						if (($p['id'] ?? null) === $wantPane) { $pane = $wantPane; break; }
					}
				}
				return ['mode' => 'in_place', 'workspace_id' => $wantWs, 'pane_id' => $pane];
			}
		}
		return ['mode' => 'new_workspace'];
	}

	/** PURE. A cmux UUID, as opposed to a positional ref like "workspace:32". */
	protected function looksLikeUuid(string $v): bool {
		return (bool) preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $v);
	}

	public function resurrect(string $prefix, bool $fromTranscript = false): void {
		$res = $this->resolveTombstoneFuzzy($prefix);
		$t   = $res['match'];
		if (!$t) {
			if ($res['ambiguous']) {
				$this->cli->exitErr("'{$prefix}' is ambiguous — narrow it or pass a full session-id.");
			}
			$this->cli->exitErr("No buried session matches '{$prefix}'.");
		}

		$native        = $this->tombstoneSessionFile($t);
		$hasNative     = $native !== null && is_file($native);
		// The RENDERED archive, not the raw rollout: this path is handed to a fresh agent
		// with "read this to re-orient", and a .jsonl of session_meta/response_item envelopes
		// is a far worse briefing than the transcript ensureTranscript() renders from it.
		$transcript    = $this->ensureTranscript($t);
		$hasTranscript = is_file($transcript);

		if (!$hasNative && !$hasTranscript) {
			$this->cli->exitErr("Neither a live session file nor an archived transcript available for {$t['session_id']} — cannot resurrect.");
		}

		$title = $t['workspace_title'] ?: 'resurrected';
		$cwd   = $t['cwd'] ?? '';

		// Put it back where it came from when that workspace is still open. Burying a
		// tab out of the workspace you're sitting in and getting it back as a brand-new
		// one just leaves you dragging it home by hand.
		$target = $this->resolveResurrectTarget($this->cmux->tree(), $t);
		if ($target['mode'] === 'in_place') {
			$surfRef = $this->cmux->createSurface($target['workspace_id'], $target['pane_id'], 'terminal', null);
			if ($surfRef) {
				$mode  = $this->launchSessionIntoSurface($t, $surfRef, $target['workspace_id'], $fromTranscript);
				$where = $this->cmux->describeWorkspace($target['workspace_id'], $title);
				$note  = $this->resurrectNote($t, $mode);
				$this->cli->successMsg("Resurrected in place into {$where} — {$note}.");
				return;
			}
			$this->cli->msg('  Could not add a tab to the original workspace — falling back to a new one.', 'yellow');
		}

		$ws = $this->cmux->newWorkspace($title, $cwd ?: null, $this->resolveTargetWindow($t['window_ref'] ?? null));

		$mode = $this->launchSessionIntoSurface($t, $ws['firstSurfRef'], $ws['ref'], $fromTranscript);
		$note = $this->resurrectNote($t, $mode);
		// Name + sidebar slot, not a bare workspace ref — the ref is an internal handle
		// and tells you nothing about which workspace to go look at.
		$where = $this->cmux->describeWorkspace($ws['ref'], $title);
		$this->cli->successMsg("Resurrected into {$where} — {$note}.");
	}

	/**
	 * Launch a buried session into an existing cmux surface: --resume when the live
	 * JSONL still exists (unless $fromTranscript), else restart Claude and point it at
	 * the exported transcript. Returns 'resume' or 'transcript'. Shared by single- and
	 * workspace-resurrect so both restore members identically.
	 */
	/**
	 * PURE-ish. The relaunch command for a tombstone, dispatched on agent.
	 *
	 * Codex gets its recorded sandbox/approval/model replayed, because `codex resume`
	 * re-reads config rather than rehydrating the session's own turn_context — bare,
	 * a session that ran read-only comes back with full access (measured). Claude's
	 * command is produced exactly as before.
	 *
	 * When there is nothing to replay because the rollout never recorded it, this
	 * still emits a bare `codex resume <id>` and the session takes config.toml's
	 * defaults. That case is not silent: launchSessionIntoSurface() warns first (see
	 * agentOptsUnknownWarning()) and proceeds.
	 */
	public function buildTombstoneLaunch(array $t, bool $fresh): string {
		if ($this->tombstoneAgent($t) === 'codex') {
			$opts = is_array($t['agent_opts'] ?? null) ? $t['agent_opts'] : [];
			return $fresh
				? 'codex'
				: $this->cmux->buildAgentResumeCommand('codex', (string) $t['session_id'], false, $t['model'] ?? null, $opts);
		}

		if (!$fresh) {
			return $this->cmux->buildResumeCommand($t['session_id'], !empty($t['skip_perms']), $t['model'] ?? null);
		}
		$launch = 'claude';
		if (!empty($t['skip_perms'])) { $launch .= ' --dangerously-skip-permissions'; }
		if (!empty($t['model']))      { $launch .= ' --model=' . $t['model']; }
		return $launch;
	}

	/** The still-resumable native session file for a tombstone, or null. */
	public function tombstoneSessionFile(array $t): ?string {
		if ($this->tombstoneAgent($t) === 'codex') {
			return $this->cmux->codexRolloutPathFor((string) $t['session_id']);
		}
		return $this->cmux->jsonlPathFor($t['session_id'], $t['cwd'] ?? '');
	}

	/** [ surface_ref => agent ] for every live agent session bound to a surface. */
	public function liveAgentSurfaceRefs(): array {
		$out = [];
		foreach ($this->liveSessions() as $r) {
			if (($r['surface_ref'] ?? '') !== '' && !empty($r['session_id'])) {
				$out[$r['surface_ref']] = $r['agent'] ?? 'claude';
			}
		}
		return $out;
	}

	/**
	 * May we type a launch command into this surface? Only if nothing is running in it.
	 *
	 * Defence in depth for a real incident: resurrect resolved a workspace by title,
	 * got a DIFFERENT pre-existing workspace that shared the title, took its first
	 * surface, and typed `codex resume …` into a live Claude Code REPL. The resolution
	 * bug is fixed at its source too, but a launch target must be an idle shell, full
	 * stop — if an agent is already there, the target is wrong by definition, and
	 * keystrokes into someone's REPL cannot be taken back.
	 */
	public function launchTargetIsSafe(string $surfRef): bool {
		return !isset($this->liveAgentSurfaceRefs()[$surfRef]);
	}

	/**
	 * Every tombstone, annotated with liveness — the accessor every user-facing view
	 * (ls, search, page) reads, so they cannot drift apart.
	 *
	 * The renderer was already shared (lsEntryLines/printLsEntry); what wasn't was the
	 * DATA, so `↑` showed up in ls and not in search purely because ls happened to
	 * annotate and search didn't. Annotating in one accessor is what actually keeps
	 * them in lock-step; a shared renderer alone does not.
	 *
	 * The live map is resolved once per process — liveSessions() shells out to cmux,
	 * lsof and ps, so several views (or a search that also lists groups) must not each
	 * pay for it.
	 */
	public function tombstones(): array {
		return $this->annotateLiveness($this->readIndex()['tombstones'] ?? [], $this->liveSessionIdsByAgentCached());
	}

	/** liveSessionIdsByAgent(), resolved once per process. */
	protected function liveSessionIdsByAgentCached(): array {
		if ($this->liveIdCache === null) {
			$this->liveIdCache = $this->liveSessionIdsByAgent();
		}
		return $this->liveIdCache;
	}

	/** PURE. Flag tombstones whose session is running again (resurrect keeps the tombstone). */
	public function annotateLiveness(array $tombs, array $liveBySessionId): array {
		foreach ($tombs as &$t) {
			$sid  = (string) ($t['session_id'] ?? '');
			$live = $sid !== '' && isset($liveBySessionId[$sid]);
			$t['live'] = $live;
			if ($live) { $t['live_agent'] = $liveBySessionId[$sid]; }
		}
		unset($t);
		return $tombs;
	}

	/** [ session_id => agent ] for every live agent session, for liveness annotation. */
	public function liveSessionIdsByAgent(): array {
		$out = [];
		foreach ($this->liveSessions() as $r) {
			if (!empty($r['session_id'])) { $out[$r['session_id']] = $r['agent'] ?? 'claude'; }
		}
		return $out;
	}

	protected function launchSessionIntoSurface(array $t, string $surfRef, string $wsRef, bool $fromTranscript): string {
		if (!$this->launchTargetIsSafe($surfRef)) {
			$agent = $this->liveAgentSurfaceRefs()[$surfRef] ?? 'an agent';
			$this->cli->exitErr(
				"Refusing to launch into {$surfRef}: a live {$agent} session is already running there. "
				. 'That surface is not an idle shell, so the resurrect target resolved wrong — '
				. 'nothing was typed into it.'
			);
		}
		$native     = $this->tombstoneSessionFile($t);
		// The RENDERED archive, not the raw rollout: this path is handed to a fresh agent
		// with "read this to re-orient", and a .jsonl of session_meta/response_item envelopes
		// is a far worse briefing than the transcript ensureTranscript() renders from it.
		$transcript = $this->ensureTranscript($t);
		$useResume  = !$fromTranscript && $native !== null && is_file($native);

		// A workspace is restored under one cwd (its first member's), but members can
		// each have their own. `claude --resume <id>` resolves the session against the
		// CURRENT dir's project key, so a member whose cwd differs from the workspace
		// cwd must cd into its own dir first — otherwise resume fails with
		// "No conversation found" (graveyard dotfiles-cwd). Harmless no-op for the
		// single-member resurrect, where the workspace is already created at $t['cwd'].
		$cwd    = (string) ($t['cwd'] ?? '');
		$prefix = $cwd !== '' ? 'cd ' . escapeshellarg($cwd) . ' && ' : '';

		// WARN AND PROCEED (dotfiles-f1n). A codex session whose rollout carried no
		// turn_context — every Codex Desktop session, ~45% of the corpus — has no
		// recorded sandbox/approval to replay, so `codex resume` takes config.toml's
		// instead and a session that ran read-only can come back with full access.
		// Deliberately NOT a refusal: the restore is still the one the user asked for
		// and the widening is only a widening relative to an unknown, so the contract is
		// honesty, not friction. Emitted here rather than in buildTombstoneLaunch() so
		// single, in-place and workspace-member resurrects cannot drift apart — this is
		// the one path all three take.
		if ($warn = $this->agentOptsUnknownWarning($t)) { $this->cli->msg($warn, 'yellow'); }

		if ($useResume) {
			$launch = $this->buildTombstoneLaunch($t, false);
			$this->cmux->sendToSurface($surfRef, $wsRef, $prefix . $launch . "\n");
			$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
			return 'resume';
		}

		$launch = $this->buildTombstoneLaunch($t, true);
		$this->cmux->sendToSurface($surfRef, $wsRef, $prefix . $launch . "\n");
		$this->waitForReplReady($surfRef, $wsRef);
		$this->cmux->sendToSurface($surfRef, $wsRef,
			'Resuming a buried session. Read ' . $transcript . ' — that is a transcript of where we left off. Re-orient from it, then continue.');
		$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
		return 'transcript';
	}

	/** PURE. A visible interactive Claude prompt means it is safe to type the preamble. */
	public function replReady(string $screen): bool {
		return (bool) preg_match('/(?:^|\n)\s*(?:❯|>)\s*$/u', $screen);
	}

	/** I/O. Wait briefly for a transcript-mode Claude launch to reach its prompt. */
	protected function waitForReplReady(string $surfRef, string $wsRef): void {
		$deadline = microtime(true) + 30;
		do {
			if ($this->replReady($this->cmux->readScreen($surfRef, $wsRef))) { return; }
			usleep(200000);
		} while (microtime(true) < $deadline);
		$this->cli->msg('  REPL prompt was not observed before timeout; sending the restore preamble.', 'yellow');
	}

	/** Resolve a group id by exact or prefix match over stored workspace manifests. */
	public function resolveGroup(string $prefix): ?array {
		$root = $this->storeRoot() . '/workspaces';
		if (!is_dir($root)) { return null; }
		$matches = [];
		foreach (glob($root . '/*/manifest.json') ?: [] as $mf) {
			$gid = basename(dirname($mf));
			if ($gid === $prefix || str_starts_with($gid, $prefix)) {
				$m = json_decode((string) @file_get_contents($mf), true);
				if ($m) { $matches[] = $m; }
			}
		}
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$this->cli->err("Ambiguous group '{$prefix}' — matches " . count($matches) . ' workspaces.');
		}
		return null;
	}

	public function resurrectWorkspace(string $prefix, bool $fromTranscript = false): void {
		$m = $this->resolveGroup($prefix);
		if (!$m) { $this->cli->exitErr("No single workspace group matches '{$prefix}'."); return; }

		$layout = $m['layout'] ?? [];
		if (!$layout) { $this->cli->exitErr('Manifest has no layout to restore.'); return; }

		// Member tombstones by session id (for resume metadata).
		$tombBySid = [];
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (!empty($t['group_id']) && $t['group_id'] === $m['group_id']) { $tombBySid[$t['session_id']] = $t; }
		}

		$title    = $m['group_title'] ?: 'resurrected';
		$firstCwd = $layout[0]['cwd'] ?? null;
		$targetWin = $this->resolveTargetWindow($m['window_ref'] ?? null);

		// Preferred path: replay cmux's own captured geometry (exact orientation,
		// divider ratios, nesting, tab order). Gated on a surface-count match — cmux
		// drops unsupported surface types when capturing, so a shorter tree would
		// misalign the positional surface↔session join. On any miss, fall back to the
		// manual pane rebuild (correct panes/tabs, approximated split direction).
		$tree = $m['layout_tree'] ?? null;
		if (is_array($tree) && $this->layoutTreeSurfaceCount($tree) === count($layout)) {
			$node = $this->cmux->newWorkspaceWithLayout($title, $firstCwd, $tree, $targetWin);
			if ($node) {
				$refs = [];
				foreach ($node['panes'] ?? [] as $p) {
					foreach ($p['surfaces'] ?? [] as $s) { $refs[] = (string) ($s['ref'] ?? ''); }
				}
				if (count($refs) === count($layout)) {
					$wsRef = (string) ($node['ref'] ?? '');
					$restored = 0;
					$refByPos = [];
					// cmux serializes panes/surfaces in the same order layout[] was walked
					// at bury (verified: tree pane order == layout DFS-leaf order), so a
					// positional zip binds each restored surface to its session.
					foreach ($layout as $k => $e) {
						$this->launchLayoutEntry($e, $refs[$k], $wsRef, $tombBySid, $fromTranscript, $restored);
						$refByPos[$e['group_pos']] = (string) $refs[$k];
					}
					$this->applyPaneSelections($layout, $refByPos, $wsRef);
					$this->cli->successMsg(sprintf('Resurrected workspace %s — layout restored, %d agent session(s) restored.',
						$this->cmux->describeWorkspace($wsRef, $title), $restored));
					return;
				}
				$this->cli->msg('  Restored surface count did not match the manifest — falling back to manual rebuild.', 'yellow');
			} else {
				$this->cli->msg('  cmux layout replay failed — falling back to manual rebuild.', 'yellow');
			}
		}

		$this->resurrectWorkspaceManual($m, $layout, $tombBySid, $fromTranscript, $targetWin);
	}

	/**
	 * Resolve which window to resurrect a workspace into: the one it was buried from,
	 * if that window still exists. cmux window refs (like surface/tty refs) are only
	 * stable within a running cmux session, so after a restart the stored ref is gone —
	 * we then fall back to the current window (announced). Returns a window ref or null
	 * (null = let cmux use the current window).
	 */
	private function resolveTargetWindow(?string $stored): ?string {
		if (empty($stored)) { return null; }
		if ($this->cmux->windowRefExists($this->cmux->tree(), $stored)) { return $stored; }
		$this->cli->msg("  Original window ({$stored}) is gone — restoring into the current window.", 'yellow');
		return null;
	}

	/**
	 * Fallback restore when no cmux geometry is stored (pre-layout_tree manifests) or
	 * the layout replay could not be trusted. Rebuilds the pane splits + per-pane tab
	 * stacks from the flat layout[] via planLayoutRestore() rather than flattening every
	 * surface into one pane. cmux exposes no split direction here, so splits default to
	 * side-by-side columns.
	 */
	private function resurrectWorkspaceManual(array $m, array $layout, array $tombBySid, bool $fromTranscript, ?string $targetWin = null): void {
		$firstCwd = $layout[0]['cwd'] ?? null;
		$ws = $this->cmux->newWorkspace($m['group_title'] ?: 'resurrected', $firstCwd, $targetWin);
		$wsRef = $ws['ref'];

		$steps         = $this->planLayoutRestore($layout);
		$anchorSurf    = $ws['firstSurfRef'];   // surface in the first pane; splits fork from it
		$paneRefByIdx  = [];                     // pane_index => live cmux pane ref
		$refByPos      = [];                     // layout group_pos => restored surface ref
		$restored      = 0;

		foreach ($steps as $step) {
			$e    = $step['entry'];
			$pIdx = $step['pane_index'];

			if ($step['op'] === 'first') {
				$surfRef = $ws['firstSurfRef'];
				$paneRefByIdx[$pIdx] = $ws['firstPaneRef'] ?? $this->cmux->paneRefForSurface($wsRef, (string) $surfRef);
			} elseif ($step['op'] === 'split') {
				$surfRef = $this->cmux->newSplit($wsRef, (string) $anchorSurf, $step['dir']);
				if (!$surfRef) { $this->cli->msg("  Could not split for pane {$pIdx} — placing as a tab instead.", 'yellow'); }
				if (!$surfRef) {
					$surfRef = $this->cmux->createSurface($wsRef, $paneRefByIdx[array_key_first($paneRefByIdx)] ?? null, 'terminal', null);
				}
				if (!$surfRef) { $this->cli->msg("  Could not create surface for pane {$pIdx} — skipping.", 'yellow'); continue; }
				$paneRefByIdx[$pIdx] = $this->cmux->paneRefForSurface($wsRef, (string) $surfRef);
			} else { // 'tab'
				$paneRef = $paneRefByIdx[$pIdx] ?? null;
				$surfRef = $this->cmux->createSurface($wsRef, $paneRef, $e['type'] === 'browser' ? 'browser' : 'terminal', $e['url'] ?? null);
				if (!$surfRef) { $this->cli->msg("  Could not create tab in pane {$pIdx} — skipping.", 'yellow'); continue; }
			}

			// A browser landing on a first/split slot got a terminal surface (new-split
			// and the workspace's initial surface are terminal-only). Give it a real
			// browser surface in this pane so its URL is preserved; the starter terminal
			// stays as an extra tab.
			if ($e['kind'] === 'browser' && $step['op'] !== 'tab' && !empty($e['url'])) {
				$b = $this->cmux->createSurface($wsRef, $paneRefByIdx[$pIdx] ?? null, 'browser', $e['url']);
				if ($b) { $surfRef = $b; }
			}

			$this->launchLayoutEntry($e, (string) $surfRef, $wsRef, $tombBySid, $fromTranscript, $restored);
			$refByPos[$e['group_pos']] = (string) $surfRef;
		}
		$this->applyPaneSelections($layout, $refByPos, $wsRef);

		$this->cli->successMsg(sprintf('Resurrected workspace %s — %d agent session(s) restored.',
			$this->cmux->describeWorkspace($wsRef, (string) $m['group_title']), $restored));
	}

	/**
	 * PURE: match $id against $rows by precedence tiers; first tier with >=1
	 * match wins (no fallthrough to weaker tiers).
	 *   1. exact surface_ref
	 *   2. exact surface_id (UUID)
	 *   3. session_id exact (if any) else session_id prefix matches
	 *   4a. workspace_title/tab_title/name EXACT (normalized, case-insensitive)
	 *   4b. workspace_title/tab_title/name substring (case-insensitive)
	 * Returns [] if nothing matches in any tier.
	 *
	 * # graveyard title-resolver exact-match tiebreak (dotfiles-w7k): same flaw as
	 * resolveWorkspaceNode — cmux auto-titles a workspace/tab after its running command,
	 * so the caller's own workspace gets titled with the literal command line and matches
	 * the query as a substring. An exact normalized-title match wins before we fall back to
	 * substring matching, mirroring Cmux::resolveWorkspaceNode.
	 */
	public function matchIdentifier(array $rows, string $id): array {
		$exact = array_values(array_filter($rows, fn($r) => ($r['surface_ref'] ?? null) === $id));
		if ($exact) { return $exact; }

		$exact = array_values(array_filter($rows, fn($r) => ($r['surface_id'] ?? null) === $id));
		if ($exact) { return $exact; }

		$sessionExact = array_values(array_filter($rows, fn($r) => ($r['session_id'] ?? null) === $id));
		if ($sessionExact) { return $sessionExact; }
		$prefix = array_values(array_filter($rows, fn($r) => str_starts_with((string) ($r['session_id'] ?? ''), $id)));
		if ($prefix) { return $prefix; }

		$normNeedle = $this->cmux->normalizeTitle($id);
		$titleExact = array_values(array_filter($rows, function ($r) use ($normNeedle) {
			if ($normNeedle === '') { return false; }
			foreach (['workspace_title', 'tab_title', 'name'] as $k) {
				if ($this->cmux->normalizeTitle((string) ($r[$k] ?? '')) === $normNeedle) { return true; }
			}
			return false;
		}));
		if ($titleExact) { return $titleExact; }

		$needle = mb_strtolower($id);
		$nameMatches = array_values(array_filter($rows, function ($r) use ($needle) {
			$title  = mb_strtolower((string) ($r['workspace_title'] ?? ''));
			$tab    = mb_strtolower((string) ($r['tab_title'] ?? ''));
			$custom = mb_strtolower((string) ($r['name'] ?? '')); // `graveyard rename` display name
			return ($needle !== '' && (str_contains($title, $needle) || str_contains($tab, $needle) || str_contains($custom, $needle)));
		}));
		if ($nameMatches) { return $nameMatches; }

		return [];
	}

	/** Resolve $id against deduped liveSessions() via matchIdentifier(). */
	public function resolveLiveByIdentifier(string $id): array {
		return $this->matchIdentifier($this->liveSessions(), $id);
	}

	public function buryByRef(string $id, bool $force, bool $autoConfirm): void {
		$matches = $this->resolveLiveByIdentifier($id);

		if (!$matches) {
			$this->cli->exitErr("No live session matches '{$id}'.");
		}

		if (count($matches) > 1) {
			$this->cli->msg("'{$id}' is ambiguous — matches " . count($matches) . ' live session(s):', 'yellow');
			foreach ($matches as $m) {
				$this->cli->msg(sprintf(
					'  %s  idle=%ds  %-20.20s  %.40s  %s',
					substr($m['session_id'], 0, 8),
					$m['idle_seconds'] ?? 0,
					$m['workspace_title'] ?? '',
					$m['tab_title'] ?? '',
					$m['cwd'] ?? ''
				));
			}
			$this->cli->exitErr("'{$id}' is ambiguous — narrow it or pass a full session-id.");
		}

		$match = $matches[0];
		$selfSurfaceId = $this->selfSurfaceId();
		$selfSessionId = $this->selfSessionId();
		if ($selfSessionId && ($match['session_id'] ?? null) === $selfSessionId) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}
		if ($selfSurfaceId && (($match['surface_id'] ?? null) === $selfSurfaceId || ($match['surface_ref'] ?? null) === $selfSurfaceId)) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}

		$this->buryIds([$match['session_id']], $autoConfirm, $force);
	}

	public function candidates(): array {
		$rows = $this->filterSelf($this->liveSessions(), $this->selfSurfaceId(), $this->selfSessionId());
		$rows = array_values(array_filter($rows, fn($r) => $r['idle_seconds'] !== PHP_INT_MAX));
		usort($rows, function ($a, $b) {
			return $b['idle_seconds'] <=> $a['idle_seconds']
				?: strcmp($a['session_id'], $b['session_id']);
		});

		$out = [];
		foreach ($rows as $r) {
			$busy = $this->isBusy(
				(int) $r['idle_seconds'],
				self::IDLE_FLOOR_DEFAULT,
				$this->readLastScreen($r['surface_ref'], $r['workspace_ref'])
			);
			$out[] = $this->candidateRowFor($r, $busy);
		}
		return $out;
	}

	/**
	 * PURE. One live-session row reduced to a candidate row.
	 *
	 * `buryable` marks agents bury has gates for. Both Claude and codex now do
	 * (codex via buryCodexOne's own, stronger gates); anything else is listed but
	 * refused, so a caller offering a bury can tell which rows would bounce.
	 */
	public function candidateRowFor(array $r, bool $busy): array {
		$agent = $r['agent'] ?? 'claude';

		return [
			'session_id'      => $r['session_id'],
			'agent'           => $agent,
			'idle_seconds'    => $r['idle_seconds'],
			'cwd'             => $r['cwd'],
			'workspace_title' => $r['workspace_title'],
			'tab_title'       => $r['tab_title'],
			'busy'            => $busy,
			'buryable'        => in_array($agent, ['claude', 'codex'], true),
			'surface_ref'     => $r['surface_ref'],
			'workspace_ref'   => $r['workspace_ref'],
			'pid'             => $r['pid'],
			'model'           => $r['model'],
			'skip_perms'      => $r['skip_perms'],
			'targetable'      => $r['targetable'] ?? true,
			'reason'          => $r['reason'] ?? '',
			// Carried for the same reason liveSessions() carries it: whatever reaches
			// buryOne must still know a codex session's recorded sandbox/approval, or
			// resurrect replays nothing and widens it.
			'opts'            => $r['opts'] ?? [],
		];
	}

	/**
	 * PURE: single tab-separated porcelain line for a candidate row. No trailing newline.
	 * Columns: session_id, idle_seconds, busy|idle, targetable|UNTARGETABLE, workspace_title, cwd, reason.
	 */
	public function formatCandidatePorcelain(array $row): string {
		return implode("\t", [
			$row['session_id'],
			(string) $row['idle_seconds'],
			$row['busy'] ? 'busy' : 'idle',
			($row['targetable'] ?? true) ? 'targetable' : 'UNTARGETABLE',
			$row['workspace_title'],
			$row['cwd'],
			$row['reason'] ?? '',
		]);
	}

	public function idleHuman(int $secs): string {
		if ($secs >= 86400) { return floor($secs / 86400) . 'd'; }
		if ($secs >= 3600)  { return floor($secs / 3600) . 'h'; }
		if ($secs >= 60)    { return floor($secs / 60) . 'm'; }
		return $secs . 's';
	}

	/** PURE: a candidate row reduced to the stable JSON-friendly field set (idle_seconds stays numeric). */
	public function candidatesJson(array $rows): array {
		return array_map(fn($r) => [
			'session_id'      => $r['session_id'] ?? '',
			'agent'           => $r['agent'] ?? 'claude',
			'idle_seconds'    => (int) ($r['idle_seconds'] ?? 0),
			'busy'            => (bool) ($r['busy'] ?? false),
			'buryable'        => (bool) ($r['buryable'] ?? (($r['agent'] ?? 'claude') === 'claude')),
			'targetable'      => (bool) ($r['targetable'] ?? true),
			'reason'          => $r['reason'] ?? '',
			'workspace_title' => $r['workspace_title'] ?? '',
			'tab_title'       => $r['tab_title'] ?? '',
			'cwd'             => $r['cwd'] ?? '',
		], $rows);
	}

	/** PURE: buried tombstones as {workspaces:[{group_id,title,sessions[]}], sessions:[loose...]}. */
	public function lsJson(array $tombs): array {
		[$groups, $loose] = $this->groupTombstones($tombs);
		$workspaces = [];
		foreach ($groups as $gid => $members) {
			$workspaces[] = [
				'group_id' => $gid,
				'title'    => $members[0]['group_title'] ?? '',
				'sessions' => array_map(fn($t) => $this->searchRowJson($t), $members),
			];
		}
		return [
			'workspaces' => $workspaces,
			'sessions'   => array_map(fn($t) => $this->searchRowJson($t), $loose),
		];
	}

	public function printCandidates(bool $porcelain, bool $json = false): void {
		$rows = $this->candidates();
		if ($json) { echo json_encode($this->candidatesJson($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; return; }
		if ($porcelain) {
			foreach ($rows as $r) { echo $this->formatCandidatePorcelain($r) . "\n"; }
			return;
		}
		if (!$rows) { $this->cli->msg('No buryable sessions.', 'yellow'); return; }
		$w    = $this->termWidth();
		$home = getenv('HOME') ?: '';
		foreach ($rows as $r) {
			$targetable = $r['targetable'] ?? true;
			$this->cli->msg($this->candidateLine($r, $w, $home), $targetable ? '' : 'yellow');
			if (!$targetable) {
				$this->cli->msg($this->ellipsizeText('          ⚠ ' . $r['reason'], $w), 'yellow');
			}
			if (!($r['buryable'] ?? true)) {
				$this->cli->msg($this->ellipsizeText(
					'          ⚠ ' . ($r['agent'] ?? '?') . ' has no bury gates — it will be refused', $w
				), 'yellow');
			}
		}
	}

	/** PURE. One width-bounded candidate line: id · idle · state · title · cwd (· ⚠ if untargetable). */
	public function candidateLine(array $r, int $width, string $home): string {
		$id    = substr((string) $r['session_id'], 0, 8);
		$idle  = $this->idleHuman((int) $r['idle_seconds']);
		$state = ($r['busy'] ?? false) ? 'busy' : 'idle';
		$flag  = ($r['targetable'] ?? true) ? '' : ' ⚠';
		$title = $this->stripGlyph((string) ($r['tab_title'] ?? ''));
		if ($title === '' || $title === 'Terminal') { $title = (string) ($r['workspace_title'] ?? ''); }
		if ($title === '') { $title = '(untitled)'; }
		// Tag anything that isn't Claude: these rows are real and idle, but bury
		// refuses them (dotfiles-nvf), and an unmarked row teaches that only by
		// trial. Claude stays unmarked — it's the overwhelming majority.
		$agent = $r['agent'] ?? 'claude';
		if ($agent !== 'claude') { $title = "[{$agent}] {$title}"; }
		$left = sprintf('%s  %-4s %-4s', $id, $idle, $state);

		$avail = $width - mb_strlen($left) - 2 - mb_strlen($flag);
		if ($avail < 24) {
			return $left . '  ' . $this->ellipsizeText($title, max(0, $avail)) . $flag;
		}
		$cwdMax   = min(40, intdiv($avail, 2));
		$shortCwd = $this->shortenCwd((string) ($r['cwd'] ?? ''), $home, $cwdMax);
		$titleTxt = $this->ellipsizeText($title, $avail - 2 - mb_strlen($shortCwd));
		return $left . '  ' . $titleTxt . '  ' . $shortCwd . $flag;
	}

	public function buryIds(array $sessionIds, bool $autoConfirm, bool $force = false): void {
		$ids = array_values(array_unique(array_filter($sessionIds, fn($s) => $s !== '')));
		if (!$ids) { $this->cli->msg('No session ids given.', 'yellow'); return; }

		if ($this->selfSurfaceId() === null) {
			$this->cli->msg('Warning: CMUX_SURFACE_ID is unset — self-protection is disabled; verify your targets.', 'yellow');
		}

		$selfSessionId = $this->selfSessionId();
		$resolved = [];
		$missing  = [];
		foreach ($ids as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $missing[] = $sid; continue; }
			$resolved[] = $fresh;
		}
		if ($missing) {
			foreach ($missing as $sid) {
				$this->cli->msg("  Session {$sid} is not currently live — skipping.", 'yellow');
			}
		}
		if ($selfSessionId && in_array($selfSessionId, $ids, true)) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}
		if (!$resolved) { $this->cli->msg('No live sessions to bury.', 'yellow'); return; }

		$this->cli->msg('Sessions to bury:', 'yellow');
		foreach ($resolved as $s) {
			$this->cli->msg(sprintf('  %s  idle=%ds  %-20.20s  %.40s',
				substr($s['session_id'], 0, 8), $s['idle_seconds'], $s['workspace_title'], $s['tab_title']));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury these ' . count($resolved) . ' session(s)?')) {
			$this->cli->msg('Aborted.', 'yellow'); return;
		}

		$n = 0;
		foreach (array_map(fn($s) => $s['session_id'], $resolved) as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $this->cli->msg("  Session {$sid} is gone — skipping.", 'yellow'); continue; }
			if ($this->buryOne($fresh, $force, true)) { $n++; }
		}
		$this->cli->successMsg("Buried {$n} of " . count($resolved) . ' session(s).');
	}

	public function buryIdle(int $thresholdSecs, bool $autoConfirm): void {
		$sessions = $this->filterSelf($this->liveSessions(), $this->selfSurfaceId(), $this->selfSessionId());

		$unknown = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] === PHP_INT_MAX));
		if ($unknown) {
			$this->cli->msg('  Skipping ' . count($unknown) . ' session(s) with unmeasurable idle time (no transcript found).', 'yellow');
		}
		$sessions = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] !== PHP_INT_MAX));

		$stale = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] >= $thresholdSecs));
		if (!$stale) { $this->cli->msg('No sessions idle past the threshold.', 'yellow'); return; }

		$this->cli->msg('Sessions to bury (idle >= ' . $thresholdSecs . 's):', 'yellow');
		foreach ($stale as $s) {
			$this->cli->msg(sprintf('  %s  idle=%ds  %-20.20s  %.40s',
				substr($s['session_id'], 0, 8), $s['idle_seconds'], $s['workspace_title'], $s['tab_title']));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury these ' . count($stale) . ' session(s)?')) {
			$this->cli->msg('Aborted.', 'yellow'); return;
		}
		$ids = array_map(fn($s) => $s['session_id'], $stale);
		$n = 0;
		foreach ($ids as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $this->cli->msg("  Session {$sid} is gone — skipping.", 'yellow'); continue; }
			if ($this->buryOne($fresh, false, true)) { $n++; }
		}
		$this->cli->successMsg("Buried {$n} of " . count($ids) . ' session(s).');
	}

	public function pickAndBury(bool $autoConfirm): void {
		$cands = $this->candidates();
		if (!$cands) { $this->cli->msg('No buryable sessions.', 'yellow'); return; }

		if (trim((string) shell_exec('command -v fzf 2>/dev/null')) !== '') {
			$ids = $this->pickWithFzf($cands);
		} else {
			$ids = $this->pickWithRepl($cands);
		}

		if (!$ids) { $this->cli->msg('Nothing selected.', 'yellow'); return; }
		$this->buryIds($ids, $autoConfirm);
	}

	public function pickWithFzf(array $cands): array {
		$selfBin = $_SERVER['argv'][0];
		$resolved = realpath($selfBin);
		if ($resolved !== false) { $selfBin = $resolved; }

		$lines = [];
		foreach ($cands as $r) {
			$label = sprintf(
				'%s  %s  %s%s  %s',
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				$r['workspace_title'] ?: '',
				$r['tab_title'] ? ' / ' . $r['tab_title'] : '',
				$r['cwd']
			);
			$lines[] = $r['session_id'] . "\t" . $label;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'gy-pick-');
		file_put_contents($tmp, implode("\n", $lines));

		$preview = escapeshellarg('php ' . $selfBin . ' _preview {1}');
		$cmd = 'fzf --multi --with-nth=2.. --delimiter="\t" --preview ' . $preview . ' --preview-window=right:60%:wrap';
		$out = shell_exec('cat ' . escapeshellarg($tmp) . ' | ' . $cmd . ' 2>/dev/null');
		@unlink($tmp);

		$ids = [];
		foreach (explode("\n", (string) $out) as $line) {
			$line = rtrim($line, "\r");
			if ($line === '') { continue; }
			$parts = explode("\t", $line, 2);
			$ids[] = $parts[0];
		}
		return $ids;
	}

	public function pickWithRepl(array $cands): array {
		foreach ($cands as $i => $r) {
			$this->cli->msg(sprintf(
				'%2d) %s  idle=%-5.5s  %-5.5s  %-20.20s  %s',
				$i + 1,
				substr($r['session_id'], 0, 8),
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				($r['workspace_title'] ?: '') . ($r['tab_title'] ? ' / ' . $r['tab_title'] : ''),
				$r['cwd']
			));
		}

		while (true) {
			$line = (string) $this->cli->ask('Select (e.g. 1 3 5, 2-4, a=all, p<n>=preview, q=quit): ');
			$line = trim($line);
			if ($line === '' || strtolower($line) === 'q') { return []; }
			if (strtolower($line) === 'a') {
				return array_map(fn($r) => $r['session_id'], $cands);
			}
			if (preg_match('/^p(\d+)$/i', $line, $m)) {
				$idx = (int) $m[1] - 1;
				if (!isset($cands[$idx])) {
					$this->cli->msg('No such candidate.', 'yellow');
					continue;
				}
				$r = $cands[$idx];
				$this->cli->msg($this->readLastScreen($r['surface_ref'], $r['workspace_ref'], 40));
				continue;
			}
			$indices = $this->parseReplSelection($line, count($cands));
			if (!$indices) {
				$this->cli->msg('No valid selections.', 'yellow');
				continue;
			}
			$ids = [];
			foreach ($indices as $idx) {
				$ids[] = $cands[$idx]['session_id'];
			}
			return array_values(array_unique($ids));
		}
	}

	/** PURE: parse a REPL selection line ("1 3 5", "2-4") into 0-based indices within [0, count). Out-of-range tokens are warned and skipped. */
	public function parseReplSelection(string $line, int $count): array {
		$indices = [];
		foreach (preg_split('/\s+/', trim($line)) as $token) {
			if ($token === '') { continue; }
			if (preg_match('/^(\d+)-(\d+)$/', $token, $m)) {
				$start = (int) $m[1];
				$end   = (int) $m[2];
				if ($start > $end) { [$start, $end] = [$end, $start]; }
				for ($n = $start; $n <= $end; $n++) {
					$idx = $n - 1;
					if ($idx >= 0 && $idx < $count) { $indices[] = $idx; }
					else { $this->cli->msg("  Ignoring out-of-range selection: {$n}", 'yellow'); }
				}
			} elseif (preg_match('/^(\d+)$/', $token, $m)) {
				$n   = (int) $m[1];
				$idx = $n - 1;
				if ($idx >= 0 && $idx < $count) { $indices[] = $idx; }
				else { $this->cli->msg("  Ignoring out-of-range selection: {$n}", 'yellow'); }
			} else {
				$this->cli->msg("  Ignoring unrecognized token: {$token}", 'yellow');
			}
		}
		return array_values(array_unique($indices));
	}

	public function printPreview(string $sessionId): void {
		$s = $this->resolveLiveBySessionId($sessionId);
		if (!$s) { echo "(session no longer live)\n"; return; }
		echo sprintf(
			"cwd: %s\nmodel: %s\nidle: %s\n\n",
			$s['cwd'],
			$s['model'] ?: '(unknown)',
			$this->idleHuman((int) $s['idle_seconds'])
		);
		echo $this->readLastScreen($s['surface_ref'], $s['workspace_ref'], 40);
	}

	/**
	 * peek <id>: one-shot rendered preview of a LIVE session's recent genuine turns,
	 * read from its JSONL (not the terminal screen, not /export) so JT can inspect what
	 * a session was doing before approving a bury — without the interactive picker.
	 */
	public function peekSession(string $id, int $turns = 6): void {
		$matches = $this->resolveLiveByIdentifier($id);
		if (!$matches) {
			$this->cli->exitErr("No live session matches '{$id}'.");
		}
		if (count($matches) > 1) {
			$this->cli->msg("'{$id}' is ambiguous — matches " . count($matches) . ' live session(s):', 'yellow');
			foreach ($matches as $m) {
				$this->cli->msg(sprintf('  %s  %-20.20s  %s', substr($m['session_id'], 0, 8), $m['workspace_title'] ?? '', $m['cwd'] ?? ''));
			}
			$this->cli->exitErr("Narrow it or pass a full session-id.");
		}
		$s = $matches[0];

		$w = $this->termWidth();
		$home = getenv('HOME') ?: '';
		$this->cli->msg($this->ellipsizeText(substr($s['session_id'], 0, 8) . '  ' . $this->stripGlyph((string) ($s['tab_title'] ?: $s['workspace_title'])), $w), 'cyan');
		$this->cli->msg($this->ellipsizeText(sprintf('%s   %s   idle %s', $this->shortenCwd((string) $s['cwd'], $home, max(20, $w - 30)), $s['model'] ?: '(unknown)', $this->idleHuman((int) $s['idle_seconds'])), $w), 'cyan');
		if (!($s['targetable'] ?? true)) {
			$this->cli->msg('⚠ untargetable: ' . $s['reason'], 'yellow');
		}
		$this->cli->msg('');

		// Codex keeps its turns in a rollout, so reading a Claude jsonl found nothing and
		// `peek <codex-id>` printed a header followed by "(no genuine conversation turns
		// found)" for every codex session. Both agents normalise to the same turn shape, so
		// they share the renderer.
		if ($this->rowAgent($s) === 'codex') {
			$rollout  = $this->codexRolloutReadPath((string) $s['session_id']);
			$rendered = $rollout === ''
				? ''
				: $this->renderNormalizedTurns($this->codexRollout()->genuineTurns($rollout), $turns);
			echo $rendered !== '' ? $rendered : "(no genuine conversation turns found)\n";
			return;
		}

		$jsonl = $this->cmux->jsonlPathFor($s['session_id'], $s['cwd']);
		$entries = [];
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if ($e) { $entries[] = $e; }
			}
			fclose($fh);
		}
		$rendered = $this->renderTurns($entries, $turns);
		echo $rendered !== '' ? $rendered : "(no genuine conversation turns found)\n";
	}

	/**
	 * PURE-ish: render the last $limit genuine user/assistant turns from decoded JSONL
	 * entries as readable lines. Skips synthetic resume turns AND slash-command noise
	 * (both classified by Cmux::isSyntheticEntry) and tool-only turns with no text.
	 * Remaining text is tag-stripped, whitespace-collapsed, and truncated.
	 */
	/**
	 * PURE. Genuine conversation turns from decoded JSONL entries: user/assistant turns
	 * that are not synthetic/command noise (Cmux::isSyntheticEntry) and carry text.
	 * Returns [['role'=>'user'|'assistant','text'=>string], ...] with text tag-stripped
	 * and whitespace-collapsed. Shared by renderTurns (peek) and gate 2.
	 */
	public function genuineTurns(array $entries): array {
		$out = [];
		foreach ($entries as $e) {
			$type = $e['type'] ?? '';
			if ($type !== 'user' && $type !== 'assistant') { continue; }
			if ($this->cmux->isSyntheticEntry($e)) { continue; }

			$content = $e['message']['content'] ?? '';
			$text = '';
			if (is_string($content)) {
				$text = $content;
			} elseif (is_array($content)) {
				foreach ($content as $c) {
					if (is_array($c) && ($c['type'] ?? '') === 'text') { $text .= ($text === '' ? '' : ' ') . ($c['text'] ?? ''); }
				}
			}
			$text = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $text)));
			if ($text === '') { continue; } // tool-only / empty turn
			$out[] = ['role' => $type, 'text' => $text];
		}
		return $out;
	}

	public function renderTurns(array $entries, int $limit = 6, int $width = 160): string {
		return $this->renderNormalizedTurns($this->genuineTurns($entries), $limit, $width);
	}

	/**
	 * PURE. Render ALREADY-NORMALIZED turns ([['role'=>..,'text'=>..], ...]).
	 *
	 * Split out from renderTurns() so codex can share it: CodexRollout produces this exact
	 * shape from a rollout, and the alternative was a second copy of the glyph-and-truncate
	 * logic that would drift from this one.
	 */
	public function renderNormalizedTurns(array $normalized, int $limit = 6, int $width = 160): string {
		$turns = [];
		foreach ($normalized as $t) {
			// Collapsed here, not by the callers: one turn is one line. Claude's turns arrive
			// pre-collapsed by genuineTurns(), but a codex turn keeps its newlines because
			// the markdown archive needs them, and a multi-line peek row breaks the glyph
			// alignment that makes the output scannable.
			$flat = trim(preg_replace('/\s+/', ' ', (string) $t['text']));
			$text = mb_strlen($flat) > $width ? mb_substr($flat, 0, $width - 1) . '…' : $flat;
			$turns[] = ($t['role'] === 'user' ? '❯ ' : '⏺ ') . $text;
		}
		$turns = array_slice($turns, -$limit);
		return $turns ? implode("\n", $turns) . "\n" : '';
	}

	/**
	 * Needles for GATE 2 (dotfiles-c8a fix): the text of the LAST $count genuine turns
	 * from a session's JSONL. Matching on the tail (not the first turn) survives
	 * compaction, session bridging, and machine-caveat/skill-preamble opening turns —
	 * all of which mean the first turn may be absent from the rendered /export, while
	 * recent turns are always present.
	 */
	public function recentTurnNeedles(string $sessionId, string $cwd, int $count = 6): array {
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (!is_file($jsonl)) { return []; }
		$entries = [];
		foreach (file($jsonl) as $line) { $e = json_decode($line, true); if ($e) { $entries[] = $e; } }
		$turns = $this->genuineTurns($entries);
		$tail  = array_slice($turns, -$count);
		return array_map(fn($t) => $t['text'], $tail);
	}

	/**
	 * PURE. GATE 2: does the exported transcript belong to the target? Passes if ANY of
	 * the session's recent genuine turns appears in the rendered transcript. Returns
	 * true when there are no usable needles (cannot assert → do not block).
	 */
	public function transcriptBelongsToSession(string $transcriptText, array $needles): bool {
		$hasNeedle = false;
		foreach ($needles as $n) {
			if (trim((string) $n) === '') { continue; }
			$hasNeedle = true;
			if ($this->transcriptMatchesSession($transcriptText, (string) $n)) { return true; }
		}
		return !$hasNeedle;
	}
}
