<?php
namespace JT\CLI\Command;

final class ProgramDefinition {

	/**
	 * @param array<string,CommandDefinition> $commands
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly object $handler,
		public readonly array $commands,
	) {
	}

	public function command( string $name ): ?CommandDefinition {
		return $this->commands[ $name ] ?? null;
	}

	public function defaultCommand(): ?CommandDefinition {
		foreach ( $this->commands as $command ) {
			if ( $command->default ) {
				return $command;
			}
		}

		return null;
	}

	/**
	 * @return CommandDefinition[]
	 */
	public function visibleCommands(): array {
		return array_values(
			array_filter(
				$this->commands,
				static fn( CommandDefinition $command ) => ! $command->hidden && ! $command->default
			)
		);
	}
}
