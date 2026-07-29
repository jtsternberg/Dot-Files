<?php
namespace JT\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Argument {

	public function __construct(
		public readonly string $name = '',
		public readonly string $description = '',
		public readonly ?string $completionCommand = null,
	) {
	}
}
