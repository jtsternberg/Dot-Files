<?php
namespace JT\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Command {

	public function __construct(
		public readonly string $name = '',
		public readonly string $description = '',
		public readonly bool $default = false,
		public readonly bool $hidden = false,
	) {
	}
}
