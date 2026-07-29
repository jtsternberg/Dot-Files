<?php
namespace JT\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Program {

	public function __construct(
		public readonly string $name,
		public readonly string $description,
	) {
	}
}
