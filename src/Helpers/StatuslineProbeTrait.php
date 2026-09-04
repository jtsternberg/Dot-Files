<?php
namespace JT\Helpers;

/**
 * PURE Claude-REPL statusline reading and the content-probe bind built on it.
 *
 * Shared by JT\Graveyard (bury GATE 1, untargetable diagnosis) and
 * JT\Transport\CmuxTransport (liveSessions()'s second-pass bind). A trait rather
 * than a collaborator for the same reason TitleGlyphTrait is one: both holders
 * need the identical pure predicate, and neither owns it — Graveyard must not
 * borrow it off a transport, because the served page renders with a transport
 * that has no cmux behind it at all.
 */
trait StatuslineProbeTrait
{
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
}
