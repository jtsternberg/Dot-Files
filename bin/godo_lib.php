<?php
namespace JT;

use JT\CLI\Helpers;

/**
 * Godo — "go and do". The command-running layer that sits on top of dirmap.
 *
 * dirmap maps a key => a directory. `goto <key>` cd's your interactive shell
 * there. godo goes one step further: for a key it stores an array of commands
 * (in ~/.cmdmap.json, mirroring dirmap's ~/.dirmap.json), cd's into the key's
 * dirmap directory in a subshell, and runs those commands in order — then
 * returns you where you were. No interactive-shell cd to persist, so this is a
 * plain bin, not a zsh function (unlike goto). Autocomplete is added the same
 * way goto's is: a compdef completion plugin, independent of this being a bin.
 *
 * Path resolution reuses dirmap as the single source of truth, so any key that
 * works with `goto` works with `godo`.
 *
 * Store shape (~/.cmdmap.json):
 *   { "dotfiles": ["git prb"], "wpe": ["git prb", "composer install"] }
 *
 * When a key has no stored commands, godo defaults to `git prb`
 * (git pull --rebase) — the overwhelmingly common "do" for a repo.
 */
class Godo {

	const DEFAULT_COMMAND = 'git prb';

	protected Helpers $helpers;

	/** Absolute path to the JSON command-map store. */
	public string $source = '';

	/** key => array of command strings. */
	protected array $map = [];

	public static ?Godo $instance = null;

	public function __construct( Helpers $helpers ) {
		$this->helpers = $helpers;

		// Test/override hook, then default to ~/.cmdmap.json — mirrors how
		// dirmap keeps ~/.dirmap.json in $HOME (untracked, per-machine).
		$override = getenv( 'GODO_CMDMAP' );
		$home     = $override ? dirname( $override ) : ( getenv( 'HOME' ) ?: dirname( JT_DOTFILES_DIR ) );
		$this->source = $override ?: $home . '/.cmdmap.json';

		if ( ! file_exists( $this->source ) ) {
			file_put_contents( $this->source, "{}\n" );
		}

		$decoded    = json_decode( (string) file_get_contents( $this->source ), true );
		$this->map  = is_array( $decoded ) ? $decoded : [];
		self::$instance = $this;
	}

	/**
	 * Raw stored commands for a key (may be empty).
	 *
	 * @return string[]
	 */
	public function getStoredCommands( string $key ): array {
		return isset( $this->map[ $key ] ) ? array_values( (array) $this->map[ $key ] ) : [];
	}

	/**
	 * Commands to actually run for a key: stored commands, or the default
	 * (git prb) when nothing is stored.
	 *
	 * @return string[]
	 */
	public function getCommandsToRun( string $key ): array {
		$stored = $this->getStoredCommands( $key );

		return ! empty( $stored ) ? $stored : [ self::DEFAULT_COMMAND ];
	}

	/** All keys with stored command arrays. */
	public function keys(): array {
		return array_keys( $this->map );
	}

	/** Whole map, key => commands. */
	public function all(): array {
		return $this->map;
	}

	/**
	 * Append a command to a key's array (creating the key if needed).
	 * Duplicate commands are skipped so re-seeding is idempotent.
	 */
	public function appendCommand( string $key, string $command ): array {
		$command = trim( $command );
		$list    = $this->getStoredCommands( $key );

		if ( '' !== $command && ! in_array( $command, $list, true ) ) {
			$list[] = $command;
		}

		$this->map[ $key ] = $list;
		$this->save();

		return $list;
	}

	/** Replace a key's whole command array with a single command. */
	public function setCommand( string $key, string $command ): array {
		$this->map[ $key ] = [ trim( $command ) ];
		$this->save();

		return $this->map[ $key ];
	}

	/**
	 * Remove a single command from a key (by exact match), or the whole key
	 * when no command is given. Returns the remaining commands for the key.
	 */
	public function removeCommand( string $key, ?string $command = null ): array {
		if ( ! isset( $this->map[ $key ] ) ) {
			return [];
		}

		if ( null === $command || '' === trim( $command ) ) {
			unset( $this->map[ $key ] );
			$this->save();

			return [];
		}

		$command = trim( $command );
		$this->map[ $key ] = array_values( array_filter(
			$this->getStoredCommands( $key ),
			static fn( $cmd ) => $cmd !== $command
		) );

		if ( empty( $this->map[ $key ] ) ) {
			unset( $this->map[ $key ] );
		}

		$this->save();

		return $this->map[ $key ] ?? [];
	}

	/** Remove a key entirely. */
	public function remove( string $key ): void {
		unset( $this->map[ $key ] );
		$this->save();
	}

	/**
	 * Resolve a key to its directory via dirmap — the single source of truth
	 * shared with `goto`. Returns the absolute path, or '' if dirmap can't
	 * resolve the key.
	 */
	public function resolvePath( string $key ): string {
		$bin = getenv( 'GODO_DIRMAP_BIN' ) ?: JT_DOTFILES_DIR . '/bin/dirmap';
		$out = [];
		$code = 0;
		exec( escapeshellcmd( $bin ) . ' get ' . escapeshellarg( $key ) . ' 2>/dev/null', $out, $code );

		if ( 0 !== $code ) {
			return '';
		}

		return trim( implode( "\n", $out ) );
	}

	protected function save(): void {
		ksort( $this->map );
		file_put_contents(
			$this->source,
			json_encode( $this->map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
	}
}
