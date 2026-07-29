<?php
namespace JT\CLI\Command;

use ReflectionNamedType;
use ReflectionParameter;

final class ParameterDefinition {

	/**
	 * @param string[] $aliases
	 */
	public function __construct(
		public readonly ReflectionParameter $parameter,
		public readonly string $kind,
		public readonly string $name,
		public readonly string $description,
		public readonly array $aliases = [],
		public readonly ?string $valueName = null,
		public readonly ?string $completionCommand = null,
	) {
	}

	public function isArgument(): bool {
		return 'argument' === $this->kind;
	}

	public function isOption(): bool {
		return 'option' === $this->kind;
	}

	public function isBoolean(): bool {
		$type = $this->parameter->getType();

		return $type instanceof ReflectionNamedType && 'bool' === $type->getName();
	}

	public function isRequired(): bool {
		return $this->isArgument()
			&& ! $this->parameter->isDefaultValueAvailable()
			&& ! $this->parameter->allowsNull()
			&& ! $this->parameter->isVariadic();
	}

	public function usageToken(): string {
		if ( $this->isArgument() ) {
			$token = '<' . $this->name . '>';
			if ( $this->parameter->isVariadic() ) {
				$token .= '...';
			}

			return $this->isRequired() ? $token : '[' . $token . ']';
		}

		$forms = [ '--' . $this->name ];
		foreach ( $this->aliases as $alias ) {
			$forms[] = '-' . $alias;
		}

		$token = implode( '|', $forms );
		if ( ! $this->isBoolean() ) {
			$token = '--' . $this->name . '=<'
				. ( $this->valueName ?: $this->name )
				. '>';
		}

		return '[' . $token . ']';
	}
}
