<?php
namespace JT\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Option {

	/**
	 * @param string[] $aliases
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly array $aliases = [],
		public readonly string $description = '',
		public readonly ?string $valueName = null,
	) {
	}
}
