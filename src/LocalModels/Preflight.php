<?php

namespace JT\LocalModels;

/**
 * Whether an engine's on-disk structure is in a shape the tooling may flip.
 *
 * `manageable` false is the self-gate: the watcher skips the engine entirely and
 * mutates nothing. That is how the MacWhisper engine can ship before its two
 * stores exist — it simply reports itself unmanageable until they do.
 */
final class Preflight {

	private function __construct(
		public readonly bool $manageable,
		public readonly string $reason = ''
	) {
	}

	public static function ready(): self {
		return new self( true );
	}

	public static function blocked( string $reason ): self {
		return new self( false, $reason );
	}
}
