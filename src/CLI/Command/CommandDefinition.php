<?php
namespace JT\CLI\Command;

use ReflectionMethod;

final class CommandDefinition {

	/**
	 * @param ParameterDefinition[] $parameters
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly bool $default,
		public readonly bool $hidden,
		public readonly ReflectionMethod $method,
		public readonly array $parameters,
	) {
	}

	public function usageArguments(): string {
		return implode(
			' ',
			array_map(
				static fn( ParameterDefinition $parameter ) => $parameter->usageToken(),
				$this->parameters
			)
		);
	}
}
