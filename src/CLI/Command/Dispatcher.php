<?php
namespace JT\CLI\Command;

use JT\CLI\Helpers;
use ReflectionNamedType;

final class Dispatcher {

	private ProgramDefinition $definition;

	public function __construct(
		private readonly Helpers $cli,
		object $handler,
	) {
		$this->definition = Registry::fromHandler( $handler );
	}

	public function run(): int {
		$first = (string) $this->cli->getArg( 1, '' );

		if ( $this->cli->hasFlags( [ 'help' ], [ 'h' ] ) || 'help' === $first ) {
			$command = 'help' === $first
				? (string) $this->cli->getArg( 2, '' )
				: $first;
			$this->cli->msg( $this->cli->getHelp()->renderProgram( $this->definition, $command ) );

			return 0;
		}

		if ( 'completion' === $first ) {
			return $this->completion();
		}

		$command = $this->definition->command( $first );
		$argAt   = 2;
		if ( null === $command ) {
			$command = $this->definition->defaultCommand();
			$argAt   = 1;
		}

		if ( null === $command ) {
			$this->cli->err( "Unknown command: {$first}" );

			return 1;
		}

		try {
			$arguments = $this->bind( $command, $argAt );
		} catch ( UsageException $e ) {
			$this->cli->err( $e->getMessage() );

			return 1;
		}

		$result = $command->method->invokeArgs( $this->definition->handler, $arguments );

		return is_int( $result ) ? $result : 0;
	}

	private function completion(): int {
		$shell = (string) $this->cli->getArg( 2, '' );
		if ( 'zsh' !== $shell ) {
			$this->cli->err(
				$shell
					? "Unsupported completion shell: {$shell}"
					: 'Missing completion shell. Usage: '
						. $this->definition->name
						. ' completion zsh'
			);

			return 1;
		}

		$this->cli->output( ( new ZshCompletion() )->render( $this->definition ), false );

		return 0;
	}

	/**
	 * @return mixed[]
	 */
	private function bind( CommandDefinition $command, int $argAt ): array {
		$bound = [];

		foreach ( $command->parameters as $definition ) {
			if ( $definition->isOption() ) {
				$bound[] = $this->optionValue( $definition );
				continue;
			}

			if ( $definition->parameter->isVariadic() ) {
				while ( null !== ( $value = $this->cli->getArg( $argAt ) ) ) {
					$bound[] = $this->coerce( $definition, $value );
					$argAt++;
				}
				continue;
			}

			$value = $this->cli->getArg( $argAt );
			$argAt++;
			if ( null === $value ) {
				if ( $definition->parameter->isDefaultValueAvailable() ) {
					$bound[] = $definition->parameter->getDefaultValue();
					continue;
				}
				if ( $definition->parameter->allowsNull() ) {
					$bound[] = null;
					continue;
				}

				throw new UsageException( "Missing required argument: <{$definition->name}>" );
			}

			$bound[] = $this->coerce( $definition, $value );
		}

		return $bound;
	}

	private function optionValue( ParameterDefinition $definition ): mixed {
		$hasLong  = $this->cli->hasFlag( $definition->name );
		$hasAlias = $this->cli->hasFlags( [], $definition->aliases );
		$present  = $hasLong || $hasAlias;

		if ( $definition->isBoolean() ) {
			return $present;
		}

		if ( $hasLong ) {
			return $this->coerce( $definition, $this->cli->getFlag( $definition->name ) );
		}

		if ( $present ) {
			throw new UsageException(
				"Option --{$definition->name} requires its value in --{$definition->name}=<value> form."
			);
		}

		if ( $definition->parameter->isDefaultValueAvailable() ) {
			return $definition->parameter->getDefaultValue();
		}

		return null;
	}

	private function coerce( ParameterDefinition $definition, mixed $value ): mixed {
		$type = $definition->parameter->getType();
		if ( ! $type instanceof ReflectionNamedType ) {
			return $value;
		}

		return match ( $type->getName() ) {
			'int'   => (int) $value,
			'float' => (float) $value,
			'bool'  => (bool) $value,
			'string' => (string) $value,
			default => $value,
		};
	}
}
