<?php
namespace JT\CLI\Command;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

final class Registry {

	public static function fromHandler( object $handler ): ProgramDefinition {
		$reflection = new ReflectionClass( $handler );
		$program    = self::attribute( $reflection->getAttributes( Program::class ), Program::class );
		$commands   = [];
		$hasDefault = false;

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$attributes = $method->getAttributes( Command::class );
			if ( empty( $attributes ) ) {
				continue;
			}

			$attribute = self::attribute( $attributes, Command::class );
			$name      = $attribute->default ? '' : ( $attribute->name ?: $method->getName() );

			if ( $attribute->default ) {
				if ( $hasDefault ) {
					throw new LogicException( 'A command handler may only define one default command.' );
				}
				$hasDefault = true;
			}

			if ( isset( $commands[ $name ] ) ) {
				throw new LogicException( "Duplicate command name: {$name}" );
			}

			$commands[ $name ] = new CommandDefinition(
				$name,
				$attribute->description,
				$attribute->default,
				$attribute->hidden,
				$method,
				array_map( [ self::class, 'parameter' ], $method->getParameters() )
			);
		}

		if ( empty( $commands ) ) {
			throw new LogicException( "No command methods found on {$reflection->getName()}." );
		}

		return new ProgramDefinition(
			$program->name,
			$program->description,
			$handler,
			$commands
		);
	}

	/**
	 * @param ReflectionAttribute[] $attributes
	 */
	private static function attribute( array $attributes, string $expected ): object {
		if ( 1 !== count( $attributes ) ) {
			throw new LogicException( "Expected exactly one {$expected} attribute." );
		}

		return $attributes[0]->newInstance();
	}

	private static function parameter( ReflectionParameter $parameter ): ParameterDefinition {
		$arguments = $parameter->getAttributes( Argument::class );
		$options   = $parameter->getAttributes( Option::class );

		if ( 1 !== count( $arguments ) + count( $options ) ) {
			throw new LogicException(
				"Parameter \${$parameter->getName()} must have exactly one Argument or Option attribute."
			);
		}

		if ( ! empty( $arguments ) ) {
			$argument = self::attribute( $arguments, Argument::class );

			return new ParameterDefinition(
				$parameter,
				'argument',
				$argument->name ?: $parameter->getName(),
				$argument->description,
				completionCommand: $argument->completionCommand,
				completion: $argument->completion
			);
		}

		$option = self::attribute( $options, Option::class );

		return new ParameterDefinition(
			$parameter,
			'option',
			$option->name ?: str_replace( '_', '-', $parameter->getName() ),
			$option->description,
			$option->aliases,
			$option->valueName
		);
	}
}
