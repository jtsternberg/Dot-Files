<?php
namespace JT\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Argument {

	public function __construct(
		public readonly string $name = '',
		public readonly string $description = '',
		public readonly ?string $completionCommand = null,
		/**
		 * Semantic completion kind for this argument. Currently 'files'
		 * (complete filesystem paths via Zsh's _files). Mutually exclusive
		 * with completionCommand, which supplies values from a command.
		 */
		public readonly ?string $completion = null,
	) {
	}
}
