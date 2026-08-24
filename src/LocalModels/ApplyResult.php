<?php

namespace JT\LocalModels;

/**
 * Outcome of one store flip.
 *
 * `noop` is the common case, not an edge case: the watcher runs on every /Volumes
 * change, so most invocations find the symlink already correct.
 */
final class ApplyResult {

	public const APPLIED     = 'applied';
	public const NOOP        = 'noop';
	public const WOULD_APPLY = 'would-apply';
	public const SKIPPED     = 'skipped';
	public const FAILED      = 'failed';

	/**
	 * @param string[] $warnings
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message = '',
		public readonly ?string $location = null,
		public readonly ?string $target = null,
		public readonly array $warnings = []
	) {
	}

	public function ok(): bool {
		return self::FAILED !== $this->status;
	}

	/**
	 * @param string[] $warnings
	 */
	public function withWarnings( array $warnings ): self {
		return new self(
			$this->status,
			$this->message,
			$this->location,
			$this->target,
			array_merge( $this->warnings, $warnings )
		);
	}
}
