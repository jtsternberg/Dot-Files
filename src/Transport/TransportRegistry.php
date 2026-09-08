<?php
namespace JT\Transport;

use JT\Helpers\AgentArtifacts;
use RuntimeException;

/**
 * Which transports graveyard can see, and which one owns a given session.
 *
 * Two jobs, and they answer different questions:
 *
 *  - Live-facing verbs (ls, candidates, search, page, bury) read the UNION. A
 *    session exists wherever it exists, so discovery must not be gated on a flag,
 *    and every row carries its own `transport` — which is why bury needs no flag
 *    either: forRow() sends the row back to the transport that produced it.
 *  - resurrect needs a target that does not exist yet, so primary() picks one:
 *    an explicit --transport, else the only reachable transport, else cmux — the
 *    incumbent, and the only one with full surface fidelity.
 *
 * The union is assembled HERE and nowhere else. Graveyard::liveSessions() delegates
 * to it and Graveyard::tombstones() annotates liveness off that single answer, so
 * ls and search cannot drift the way they once did (see that docblock).
 */
final class TransportRegistry
{
	/**
	 * Discovery order, and so also the resurrect default. Registration order
	 * deliberately does NOT decide it: a caller reordering its constructor array
	 * would otherwise silently move which multiplexer a bare `resurrect` lands in.
	 */
	private const PREFERENCE = ['cmux', 'herdr'];

	protected $cli;

	/** @var list<SessionTransport> */
	protected array $transports;

	protected AgentArtifacts $artifacts;

	/** @var ?list<SessionTransport> Memoised: available() shells out to each transport. */
	private ?array $availableCache = null;

	/** @param list<SessionTransport> $transports */
	public function __construct($cli, array $transports, ?AgentArtifacts $artifacts = null) {
		$this->cli        = $cli;
		$this->artifacts  = $artifacts ?: new AgentArtifacts($cli);
		$this->transports = $this->inPreferenceOrder(array_values($transports));
	}

	/** Every registered transport, reachable or not, in preference order. */
	public function all(): array { return $this->transports; }

	/**
	 * The reachable transports, cmux first.
	 *
	 * Memoised for the life of the process: each available() is a shell-out (a cmux
	 * tree, a herdr status), and one `search` render asks for liveness once but the
	 * bury path asks repeatedly. Same reasoning as
	 * Graveyard::liveSessionIdsByAgentCached().
	 *
	 * @return list<SessionTransport>
	 */
	public function available(): array {
		if ($this->availableCache === null) {
			$this->availableCache = array_values(array_filter(
				$this->transports,
				fn(SessionTransport $t) => $t->available()
			));
		}
		return $this->availableCache;
	}

	public function byName(string $name): ?SessionTransport {
		foreach ($this->transports as $t) {
			if ($t->name() === $name) { return $t; }
		}
		return null;
	}

	/**
	 * The transport that produced this live row.
	 *
	 * Returns an UNAVAILABLE transport too, when the row names one: a row only exists
	 * because some transport reported it, and if that transport has since gone away the
	 * caller must fail on the drive with its own error rather than be quietly handed a
	 * different multiplexer's surfaces — the refs would resolve to someone else's pane.
	 */
	public function forRow(array $row): SessionTransport {
		$name = (string) ($row['transport'] ?? '');
		$t    = $name !== '' ? $this->byName($name) : null;
		if ($t === null) {
			throw new RuntimeException(sprintf(
				"Unknown session transport '%s' for session %s — known: %s.",
				$name, substr((string) ($row['session_id'] ?? '?'), 0, 8), $this->knownNames()
			));
		}
		return $t;
	}

	/**
	 * The transport a create lands in: explicit request, else the only reachable one,
	 * else cmux. Null when nothing is reachable — the caller reports that.
	 */
	public function primary(?string $requested = null): ?SessionTransport {
		if ($requested !== null && $requested !== '') {
			$t = $this->byName($requested);
			if ($t === null) {
				throw new RuntimeException("Unknown transport '{$requested}' — known: {$this->knownNames()}.");
			}
			if (!$t->available()) {
				throw new RuntimeException("Transport {$requested} is not reachable. Is {$requested} running?");
			}
			return $t;
		}
		return $this->available()[0] ?? null;
	}

	/**
	 * Every live session across the reachable transports, one row per session.
	 *
	 * @return list<array>
	 */
	public function liveSessions(): array {
		$rows = [];
		foreach ($this->available() as $t) {
			foreach ($t->liveSessions() as $row) { $rows[] = $row; }
		}
		return $this->artifacts->dedupBySessionId($this->hostingFirst($rows));
	}

	/**
	 * Move the HOSTING row into each session's first slot, so the plain first-wins
	 * dedup below it keeps the right one.
	 *
	 * This collision is routine, not a corner case: Claude Code writes
	 * ~/.claude/sessions/<pid>.json whatever multiplexer it runs under, so cmux's join
	 * finds every herdr-hosted claude session — and then cannot bind it to a cmux
	 * surface, so the row arrives untargetable ("CMUX_SURFACE_ID not found among cmux
	 * surfaces"). Letting the first row win would present every herdr session as
	 * unburyable and discard the one that can actually be driven.
	 *
	 * So the winner is the transport that demonstrably HOSTS the session: an
	 * untargetable row is that transport's own admission that it cannot reach it. A
	 * genuine tie (both targetable) falls to preference order, i.e. the incumbent.
	 *
	 * Rows are rewritten in place rather than partitioned, so with only one transport
	 * up nothing here moves at all and `candidates` for a cmux-only user is
	 * byte-identical. The duplicate this leaves in the loser's old slot is what
	 * dedupBySessionId() then removes.
	 */
	protected function hostingFirst(array $rows): array {
		$winner = [];
		foreach ($rows as $i => $row) {
			$sid = (string) ($row['session_id'] ?? '');
			if ($sid === '') { continue; }
			if (!isset($winner[$sid])) { $winner[$sid] = $i; continue; }
			$incumbent = $rows[$winner[$sid]];
			if (!($incumbent['targetable'] ?? false) && ($row['targetable'] ?? false)) {
				$winner[$sid] = $i;
			}
		}

		$out = [];
		foreach ($rows as $row) {
			$sid   = (string) ($row['session_id'] ?? '');
			$out[] = $sid !== '' && isset($winner[$sid]) ? $rows[$winner[$sid]] : $row;
		}
		return $out;
	}

	/** @param list<SessionTransport> $transports */
	private function inPreferenceOrder(array $transports): array {
		usort($transports, fn(SessionTransport $a, SessionTransport $b) => $this->rank($a) <=> $this->rank($b));
		return $transports;
	}

	/** Preferred transports first, in PREFERENCE order; anything unlisted after, as registered. */
	private function rank(SessionTransport $t): int {
		$i = array_search($t->name(), self::PREFERENCE, true);
		return $i === false ? count(self::PREFERENCE) : $i;
	}

	private function knownNames(): string {
		return implode(', ', array_map(fn(SessionTransport $t) => $t->name(), $this->transports));
	}
}
