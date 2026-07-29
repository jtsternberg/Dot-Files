<?php
namespace JT\CLI\Command;

final class ZshCompletion {

	public function render( ProgramDefinition $program ): string {
		$function  = '_' . str_replace( '-', '_', $program->name );
		$providers = $this->providers( $program );
		$commands  = $program->visibleCommands();
		$lines     = [
			'# BEGIN GENERATED COMPLETION: ' . $program->name,
			'#compdef ' . $program->name,
			'# Generated from PHP command attributes. Do not edit by hand.',
			'',
			$function . '() {',
			"\tlocal -a commands",
		];

		foreach ( $providers as $variable => $command ) {
			$lines[] = "\tlocal -a {$variable}";
		}

		$lines[] = '';
		$lines[] = "\tcommands=(";
		foreach ( $commands as $command ) {
			$lines[] = "\t\t" . $this->quote(
				$command->name . ':' . $this->commandDescription( $command->description )
			);
		}
		$lines[] = "\t\t" . $this->quote( 'help:Display command help' );
		$lines[] = "\t\t" . $this->quote( 'completion:Generate shell completion' );
		$lines[] = "\t)";

		foreach ( $providers as $variable => $command ) {
			$lines[] = "\t{$variable}=(\"\${(@f)\$(" . $command . " 2>/dev/null)}\")";
		}

		$lines[] = '';
		$lines[] = "\tif (( CURRENT == 2 )); then";
		$lines[] = "\t\t_describe -t commands '" . $program->name . " command' commands";
		$default = $program->defaultCommand();
		if ( null !== $default ) {
			$provider = $this->firstArgumentProvider( $default, $providers );
			if ( null !== $provider ) {
				$lines[] = "\t\tcompadd -- \${{$provider}}";
			}
		}
		$lines[] = "\t\treturn";
		$lines[] = "\tfi";
		$lines[] = '';
		$lines[] = "\tcase \$words[2] in";

		foreach ( $commands as $command ) {
			$lines[] = "\t\t" . $command->name . ')';
			$lines   = array_merge(
				$lines,
				$this->renderArguments( $command, 2, $providers, "\t\t\t" )
			);
			$lines[] = "\t\t\t;;";
		}

		$names   = array_map(
			static fn( CommandDefinition $command ) => $command->name,
			$commands
		);
		$names[] = 'help';
		$names[] = 'completion';
		$lines[] = "\t\thelp)";
		$lines[] = "\t\t\t_arguments "
			. $this->quote( '2:command:(' . implode( ' ', $names ) . ')' );
		$lines[] = "\t\t\t;;";
		$lines[] = "\t\tcompletion)";
		$lines[] = "\t\t\t_arguments " . $this->quote( '2:shell:(zsh)' );
		$lines[] = "\t\t\t;;";

		if ( null !== $default ) {
			$lines[] = "\t\t*)";
			$lines   = array_merge(
				$lines,
				$this->renderArguments( $default, 1, $providers, "\t\t\t" )
			);
			$lines[] = "\t\t\t;;";
		}

		$lines[] = "\tesac";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = 'compdef ' . $function . ' ' . $program->name;
		$lines[] = '# END GENERATED COMPLETION: ' . $program->name;
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * @return array<string,string> variable => shell command
	 */
	private function providers( ProgramDefinition $program ): array {
		$commands = [];
		foreach ( $program->commands as $command ) {
			foreach ( $command->parameters as $parameter ) {
				if (
					null !== $parameter->completionCommand
					&& ! in_array( $parameter->completionCommand, $commands, true )
				) {
					$commands[] = $parameter->completionCommand;
				}
			}
		}

		$providers = [];
		foreach ( $commands as $index => $command ) {
			$providers[ 'completion_values_' . ( $index + 1 ) ] = $command;
		}

		return $providers;
	}

	private function firstArgumentProvider(
		CommandDefinition $command,
		array $providers
	): ?string {
		foreach ( $command->parameters as $parameter ) {
			if ( $parameter->isArgument() && null !== $parameter->completionCommand ) {
				return array_search( $parameter->completionCommand, $providers, true ) ?: null;
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private function renderArguments(
		CommandDefinition $command,
		int $position,
		array $providers,
		string $indent
	): array {
		$specs = [];
		foreach ( $command->parameters as $parameter ) {
			if ( $parameter->isOption() ) {
				$specs[] = $this->optionSpec( $parameter );
				foreach ( $parameter->aliases as $alias ) {
					$specs[] = $this->quote(
						'-' . $alias . '[alias for --' . $parameter->name . ']'
					);
				}
				continue;
			}

			$provider = null === $parameter->completionCommand
				? null
				: ( array_search( $parameter->completionCommand, $providers, true ) ?: null );
			if ( null === $provider ) {
				$specs[] = $this->quote( "{$position}:{$parameter->name}:" );
			} else {
				$specs[] = '"'
					. $position
					. ':'
					. $parameter->name
					. ':($'
					. $provider
					. ')"';
			}
			$position++;
		}

		if ( empty( $specs ) ) {
			return [ $indent . "_message 'no more arguments'" ];
		}

		$lines = [ $indent . '_arguments \\' ];
		$last  = count( $specs ) - 1;
		foreach ( $specs as $index => $spec ) {
			$lines[] = $indent . "\t" . $spec . ( $index === $last ? '' : ' \\' );
		}

		return $lines;
	}

	private function optionSpec( ParameterDefinition $parameter ): string {
		$description = $this->argumentDescription( $parameter->description );
		if ( $parameter->isBoolean() ) {
			return $this->quote( "--{$parameter->name}[{$description}]" );
		}

		$value = $parameter->valueName ?: $parameter->name;

		return $this->quote( "--{$parameter->name}=[{$description}]:{$value}:" );
	}

	private function commandDescription( string $description ): string {
		return str_replace( ':', '\\:', $description );
	}

	private function argumentDescription( string $description ): string {
		return str_replace( [ '[', ']' ], [ '\\[', '\\]' ], $description );
	}

	private function quote( string $value ): string {
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}
}
