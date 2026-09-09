<?php
namespace JT\Helpers;

/**
 * An artifact reader that never looks at LIVE agent state, for the page server.
 *
 * The HTTP router (bin/graveyard_router.php) renders the archive and nothing else.
 * That used to be guaranteed by accident: it built its Graveyard with `NullCmux`,
 * and NullCmux stubbed the artifact reads empty along with the cmux ones — the four
 * methods below were all on the same class. The transport seam split them apart, so
 * a NullTransport says "nothing is live" while a real AgentArtifacts sitting beside
 * it happily reads ~/.claude and ~/.codex — and Graveyard::codexRolloutReadPath()
 * prefers the live rollout, so the served page rendered the LIVE transcript of any
 * buried session whose rollout still existed (dotfiles-dnc).
 *
 * Answering empty here is a stronger statement than "the file is missing": the
 * router is a request handler on a loopback socket, and reading a session that is
 * still running means serving a moving target from a process that has no business
 * knowing it exists. Everything it may show is under GRAVEYARD_ROOT.
 *
 * The overridden methods are exactly the live-state readers Graveyard reaches for on
 * the page paths, plus every path resolver they go through. Anything else
 * AgentArtifacts does — path encoding, resume command construction, title
 * normalisation — is pure or archive-only and is inherited unchanged.
 *
 * Two of them are belt-and-braces and cannot be pinned by a test: readSessionJsonl and
 * lastRealActivity both resolve their file through the resolvers below, so those
 * overrides already force them to their empty answers, and dropping them changes no
 * observable behavior TODAY. They are here because that is an implementation detail of
 * the parent, not a promise — a future resolver that no longer routes through this
 * class would silently reopen dotfiles-dnc through them.
 */
class NullAgentArtifacts extends AgentArtifacts
{
	/** No live rollout, so codexRolloutReadPath() falls through to the archived copy. */
	public function codexRolloutPathFor(string $sessionId): ?string { return null; }

	/** Empty rather than a path under $HOME: a path that exists is a path that gets read. */
	public function jsonlPathFor(string $sessionId, string $cwd): string { return ''; }

	/**
	 * Null, and NOT derived from jsonlPathFor(): the resolver finds a transcript by
	 * session id when the composed path misses, so it reaches ~/.claude/projects on its
	 * own. Leaving it inherited would let the router resolve a live transcript through
	 * transcriptPathFor() with the override above satisfied — dotfiles-dnc, reopened.
	 */
	public function resolveJsonlPath(string $sessionId, ?string $cwd): ?string { return null; }

	public function readSessionJsonl(?string $sessionId, ?string $cwd): array {
		return ['permission_mode' => null, 'model' => null];
	}

	/** Idle time is a live-session question; the page shows buried_at/last_active. */
	public function lastRealActivity(string $sessionId, string $cwd): ?int { return null; }
}
