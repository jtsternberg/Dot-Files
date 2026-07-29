<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Command\Registry;
use JT\CLI\Helpers;

#[Program(
	name: 'godo',
	description: 'Run stored commands inside a dirmap-mapped directory ("go and do"). Keys are shared with dirmap/goto.',
)]
final class GodoCommand {

	/** @var callable(string):int */
	private $runner;
	private ?Godo $godo;

	public function __construct(
		private readonly Helpers $cli,
		?Godo $godo = null,
		?callable $runner = null,
	) {
		$this->godo   = $godo;
		$this->runner = $runner ?: static function ( string $command ): int {
			passthru( $command, $code );

			return $code;
		};
	}

	#[Command(
		description: 'Run a key\'s stored commands in its mapped directory.',
		default: true,
	)]
	public function runKey(
		#[Argument(
			description: 'The dirmap key whose stored commands should run.',
			completionCommand: 'dirmap list -k',
		)]
		string $key,
		#[Option(
			description: 'One-off command to use when the key has no stored commands.',
			valueName: 'command',
		)]
		?string $default = null
	): int {
		$dir = $this->godo()->resolvePath( $key );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			$this->cli->err( sprintf( "\nNo dirmap directory found for key: '%s'", $key ) );
			$this->cli->msg( "Try `dirmap list` to see available keys.\n" );

			return 1;
		}

		$commands = $this->godo()->resolveCommands( $key, $default );
		if ( empty( $commands ) ) {
			$this->cli->err( sprintf( "\nNo commands set for key: '%s'.", $key ) );
			$this->cli->msg( sprintf(
				"Add one with:  %sgodo addcmd %s '<command>'%s",
				$this->cli->color( 'cyan' ),
				$key,
				$this->cli->color( 'none' )
			) );
			$this->cli->msg( sprintf(
				"Or run once with a fallback:  godo %s --default='git prb'\n",
				$key
			) );

			return 1;
		}

		$cyan  = $this->cli->color( 'cyan' );
		$reset = $this->cli->color( 'none' );
		foreach ( $commands as $command ) {
			$this->cli->msg( sprintf(
				"\n%s➜ %s%s  %s(in %s)%s",
				$cyan,
				$command,
				$reset,
				$this->cli->color( 'dark_gray' ),
				$dir,
				$reset
			) );
			$full = 'cd ' . escapeshellarg( $dir ) . ' && ' . $command;
			$code = ( $this->runner )( $full );
			if ( 0 !== $code ) {
				$this->cli->err( sprintf(
					"\nCommand failed (exit %d) for '%s': %s\n",
					$code,
					$key,
					$command
				), false );

				return $code;
			}
		}

		return 0;
	}

	#[Command(
		name: 'get',
		description: 'Print the stored commands for a key.',
	)]
	public function get(
		#[Argument(
			description: 'The dirmap key to inspect.',
			completionCommand: 'dirmap list -k',
		)]
		string $key
	): int {
		$this->cli->output( implode( "\n", $this->godo()->getStoredCommands( $key ) ), false );

		return 0;
	}

	#[Command(
		name: 'addcmd',
		description: 'Append a command to a key without adding duplicates.',
	)]
	public function addCommand(
		#[Argument(
			description: 'The dirmap key whose command list should change.',
			completionCommand: 'dirmap list -k',
		)]
		string $key,
		#[Argument(description: 'The command to append; quote commands containing spaces.')]
		string $command
	): int {
		if ( ! $this->requireDirmapKey( $key ) ) {
			return 1;
		}
		if ( ! $this->requireCommand( $command ) ) {
			return 1;
		}

		$this->godo()->appendCommand( $key, $command );
		$this->cli->msg( sprintf( "\nAdded to %s: %s\n", $key, $command ), 'green' );

		return 0;
	}

	#[Command(
		name: 'setcmd',
		description: 'Replace a key\'s command list with one command.',
	)]
	public function setCommand(
		#[Argument(
			description: 'The dirmap key whose command list should change.',
			completionCommand: 'dirmap list -k',
		)]
		string $key,
		#[Argument(description: 'The replacement command; quote commands containing spaces.')]
		string $command
	): int {
		if ( ! $this->requireDirmapKey( $key ) ) {
			return 1;
		}
		if ( ! $this->requireCommand( $command ) ) {
			return 1;
		}

		$this->godo()->setCommand( $key, $command );
		$this->cli->msg( sprintf( "\nSet %s: %s\n", $key, $command ), 'green' );

		return 0;
	}

	#[Command(
		name: 'rmcmd',
		description: 'Remove one command from a key, or all commands when omitted.',
	)]
	public function removeCommand(
		#[Argument(
			description: 'The key whose command list should change.',
			completionCommand: 'godo list --keys',
		)]
		string $key,
		#[Argument(description: 'The exact command to remove; omit to clear the key.')]
		?string $command = null
	): int {
		$this->godo()->removeCommand( $key, $command );
		$this->cli->msg( sprintf(
			"\nRemoved from %s: %s\n",
			$key,
			null === $command || '' === $command ? '(all)' : $command
		), 'green' );

		return 0;
	}

	#[Command(
		name: 'remove',
		description: 'Remove a key entirely from the command map.',
	)]
	public function remove(
		#[Argument(
			description: 'The stored command-map key to remove.',
			completionCommand: 'godo list --keys',
		)]
		string $key
	): int {
		$this->godo()->remove( $key );
		$this->cli->msg( sprintf( "\nRemoved: %s\n", $key ), 'green' );

		return 0;
	}

	#[Command(
		name: 'list',
		description: 'Output the stored command map.',
	)]
	public function listMap(
		#[Option(
			name: 'keys',
			aliases: [ 'k' ],
			description: 'Output only the stored keys.',
		)]
		bool $keys = false,
		#[Option(
			name: 'json',
			aliases: [ 'j' ],
			description: 'Output raw JSON without colors.',
		)]
		bool $json = false
	): int {
		if ( $json ) {
			$this->cli->output(
				json_encode(
					$this->godo()->all(),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
				false
			);

			return 0;
		}

		if ( $keys ) {
			$this->cli->output( implode( "\n", $this->godo()->keys() ), false );

			return 0;
		}

		$this->outputMap();

		return 0;
	}

	#[Command(
		name: 'edit',
		description: 'Open the command-map file in the configured editor.',
	)]
	public function edit(): int {
		$file = $this->godo()->source;
		if ( '' !== trim( (string) shell_exec( 'command -v code 2>/dev/null' ) ) ) {
			$code = ( $this->runner )( 'code -r ' . escapeshellarg( $file ) );
		} else {
			$editor = getenv( 'EDITOR' ) ?: 'vi';
			$code   = ( $this->runner )( $editor . ' ' . escapeshellarg( $file ) );
		}
		$this->cli->msg( sprintf( "\nOpened %s\n", $file ), 'green' );

		return $code;
	}

	#[Command(
		name: 'commands',
		description: 'Print command names for legacy completion consumers.',
		hidden: true,
	)]
	public function commands(): int {
		$commands = array_map(
			static fn( $command ) => $command->name,
			Registry::fromHandler( $this )->visibleCommands()
		);
		$this->cli->output( implode( "\n", $commands ), false );

		return 0;
	}

	private function requireDirmapKey( string $key ): bool {
		if ( '' !== $this->godo()->resolvePath( $key ) ) {
			return true;
		}

		$this->cli->err( sprintf( "\nNo dirmap entry for key: '%s'", $key ) );
		$this->cli->msg( "godo keys must map to a dirmap directory. If this isn't a typo, add it first:" );
		$this->cli->msg( sprintf(
			"  %sdirmap add %s <path>%s   (or: cd <path> && dirmap add %s)",
			$this->cli->color( 'cyan' ),
			$key,
			$this->cli->color( 'none' ),
			$key
		) );
		$this->cli->msg( "Then `dirmap list` to confirm, or `godo list` to review godo keys.\n" );

		return false;
	}

	private function requireCommand( string $command ): bool {
		if ( '' !== trim( $command ) ) {
			return true;
		}

		$this->cli->err( "\nA command is required (quote it, e.g. 'git prb')." );

		return false;
	}

	private function outputMap(): void {
		$map = $this->godo()->all();
		if ( empty( $map ) ) {
			$this->cli->msg(
				"\nNo commands stored yet. Add some with `godo addcmd <key> '<command>'`.\n",
				'yellow'
			);

			return;
		}

		ksort( $map );
		$maxKey = max( array_map( 'strlen', array_keys( $map ) ) );
		$home   = getenv( 'HOME' ) ?: '';
		$arrow  = '  =>  ';
		$indent = str_repeat( ' ', 2 + $maxKey + strlen( $arrow ) );
		$cyan   = $this->cli->color( 'cyan' );
		$gray   = $this->cli->color( 'dark_gray' );
		$reset  = $this->cli->color( 'none' );
		$output = sprintf( "\n%sCommand map (%d):%s\n", $this->cli->color( 'green' ), count( $map ), $reset );

		foreach ( $map as $key => $commands ) {
			$padding = str_repeat( ' ', $maxKey - strlen( $key ) );
			$dir     = $this->godo()->resolvePath( $key );
			$shortDir = '' === $dir
				? '(no dirmap entry)'
				: ( $home && 0 === strpos( $dir, $home )
					? '~' . substr( $dir, strlen( $home ) )
					: $dir );
			$output .= sprintf(
				"  %s%s%s%s%s%s%s\n",
				$cyan,
				$key,
				$reset,
				$padding,
				$arrow,
				$gray,
				$shortDir . $reset
			);
			foreach ( (array) $commands as $command ) {
				$output .= sprintf( "%s%s- %s%s\n", $indent, $gray, $command, $reset );
			}
		}

		$this->cli->output( $output, false );
	}

	private function godo(): Godo {
		if ( null === $this->godo ) {
			$this->godo = new Godo( $this->cli );
		}

		return $this->godo;
	}
}
