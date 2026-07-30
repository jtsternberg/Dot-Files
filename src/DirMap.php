<?php
namespace JT;

use JT\CLI\Helpers;

/** Stores and resolves dirmap directory aliases. */
class DirMap {

	protected $dirs = [ '' => '~/' ];
	protected $helpers;
	public $hd = '';
	public $source = '';
	public static $instance = null;

	public function __construct( Helpers $helpers ) {
		$this->helpers = $helpers;
		$this->source = getenv( 'DIRMAP_FILE' ) ?: dirname( JT_DOTFILES_DIR ) . '/.dirmap.json';
		$this->hd = dirname( $this->source );
		if ( ! file_exists( $this->source ) ) {
			file_put_contents( $this->source, "{}\n" );
		}

		$this->dirs = json_decode( file_get_contents( $this->source ), true ) ?: [];
		$this->dirs[''] = '~/';
		self::$instance = $this;
	}

	public function get() {
		$key = $this->helpers->getArg( 1, '' );
		if ( ! empty( $key ) && ! isset( $this->dirs[ $key ] ) ) {
			$this->helpers->err( sprintf( "\nThere is no directory associated with the given key: '%s'", $key ), false );
			$this->helpers->msg( "Try `dirmap list` to see available keys\n" );
			exit( 1 );
		}

		$dir = $this->dirs[ empty( $key ) ? '' : $key ];
		exit( str_replace( '~', $this->hd, $dir ) );
	}

	public function add( $args ) {
		array_shift( $args ); array_shift( $args );
		$key = array_shift( $args );
		$path = implode( ' ', $args );
		if ( empty( $path ) || '.' === $path ) { $path = $this->helpers->wd; }
		if ( empty( $key ) ) { $key = $this->helpers->currDir; }

		$dirs = $this->dirs;
		unset( $dirs[''] );
		$dirs[ $key ] = $path;
		$this->dirs[ $key ] = $path;
		$this->updateFile( $dirs );
		$this->helpers->msg( sprintf( "\nAdded: %s => %s\n", $key, $path ), 'green' );
	}

	public function remove( $args ) {
		if ( empty( $args[2] ) ) {
			$this->helpers->err( "\nThe directory map key (alias) is required." );
			$this->helpers->msg( "E.g. dirmap remove mysites\n" );
			exit( 1 );
		}
		array_shift( $args ); array_shift( $args );
		$key = array_shift( $args );
		$dirs = $this->dirs;
		unset( $dirs[''], $dirs[ $key ], $this->dirs[ $key ] );
		$this->updateFile( $dirs );
		$this->helpers->msg( sprintf( "\nRemoved: %s\n", $key ), 'green' );
	}

	public function rename( $args ) {
		array_shift( $args ); array_shift( $args );
		$first = array_shift( $args ); $second = array_shift( $args );
		if ( empty( $second ) ) {
			$newKey = $first;
			$oldKey = $this->findKeyForPath( $this->helpers->wd );
			if ( empty( $oldKey ) ) {
				$this->helpers->err( sprintf( "\nNo dirmap key found for the current directory: '%s'", $this->helpers->wd ), false );
				$this->helpers->msg( "Provide the old key explicitly: dirmap rename <oldkey> <newkey>\n" );
				exit( 1 );
			}
		} else { $oldKey = $first; $newKey = $second; }
		if ( empty( $newKey ) ) {
			$this->helpers->err( "\nThe new directory map key (alias) is required." );
			$this->helpers->msg( "E.g. dirmap rename oldkey newkey\n" );
			exit( 1 );
		}
		if ( ! isset( $this->dirs[ $oldKey ] ) ) {
			$this->helpers->err( sprintf( "\nThere is no directory associated with the given key: '%s'", $oldKey ), false );
			$this->helpers->msg( "Try `dirmap list` to see available keys\n" );
			exit( 1 );
		}
		if ( $oldKey === $newKey ) {
			$this->helpers->err( sprintf( "\nThe new key is the same as the old key: '%s'\n", $oldKey ), false );
			exit( 1 );
		}
		if ( isset( $this->dirs[ $newKey ] ) ) {
			$this->helpers->err( sprintf( "\nA mapping already exists for the key: '%s' => %s", $newKey, $this->dirs[ $newKey ] ), false );
			$this->helpers->msg( "Remove it first, or choose a different key.\n" );
			exit( 1 );
		}

		$path = $this->dirs[ $oldKey ];
		$dirs = $this->dirs;
		unset( $dirs[''], $dirs[ $oldKey ], $this->dirs[ $oldKey ] );
		$dirs[ $newKey ] = $path;
		$this->dirs[ $newKey ] = $path;
		$this->updateFile( $dirs );
		$this->helpers->msg( sprintf( "\nRenamed: %s => %s (%s)\n", $oldKey, $newKey, $path ), 'green' );
	}

	public function identify( $args ) {
		array_shift( $args ); array_shift( $args );
		$path = ! empty( $args ) ? implode( ' ', $args ) : $this->helpers->wd;
		if ( '.' === $path ) { $path = $this->helpers->wd; }
		$best = $this->findKeyForPath( $path );
		if ( empty( $best ) ) {
			$this->helpers->err( sprintf( "\nNo dirmap key found for: '%s'\n", $this->resolvePath( $path ) ), false );
			exit( 1 );
		}
		exit( $best );
	}

	protected function resolvePath( $path ) {
		$path = rtrim( str_replace( '~', $this->hd, $path ), '/' );
		$real = realpath( $path );
		return false !== $real ? $real : $path;
	}

	protected function findKeyForPath( $path ) {
		$path = $this->resolvePath( $path );
		$best = '';
		foreach ( $this->dirs as $key => $dir ) {
			if ( '' !== $key && $this->resolvePath( $dir ) === $path && ( empty( $best ) || strlen( $key ) < strlen( $best ) ) ) {
				$best = $key;
			}
		}
		return $best;
	}

	public function edit() {
		$file = $this->source;
		if ( '' !== trim( (string) shell_exec( 'command -v code 2>/dev/null' ) ) ) {
			passthru( 'code -r ' . escapeshellarg( $file ) );
		} else {
			passthru( ( getenv( 'EDITOR' ) ?: 'vi' ) . ' ' . escapeshellarg( $file ) );
		}
		$this->helpers->msg( sprintf( "\nOpened %s\n", $file ), 'green' );
	}

	public function list() {
		if ( $this->helpers->hasFlags( [ 'json' ], [ 'j' ] ) ) { exit( file_get_contents( $this->source ) ); }
		if ( $this->helpers->hasFlags( [ 'keys' ], [ 'k' ] ) ) { exit( implode( "\n", array_keys( $this->dirs ) ) ); }
		$this->outputMap( $this->dirs, 'Current Map' );
	}

	protected function updateFile( $dirs ) {
		$json = json_encode( $dirs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		file_put_contents( $this->source, $json . "\n" );
		return $this;
	}

	protected function outputMap( $dirs, $msg = 'Directory map updated' ) {
		$display = array_filter( $dirs, static fn( $key ) => '' !== $key, ARRAY_FILTER_USE_KEY );
		ksort( $display );
		$maxKey = max( array_map( 'strlen', array_keys( $display ) ) );
		$this->helpers->msg( sprintf( "\n%s (%d):\n", $msg, count( $display ) ), 'green' );
		$cyan = $this->helpers->color( 'cyan' ); $gray = $this->helpers->color( 'dark_gray' ); $reset = $this->helpers->color( 'none' );
		foreach ( $display as $key => $dir ) {
			$shortDir = 0 === strpos( $dir, $this->hd ) ? '~' . substr( $dir, strlen( $this->hd ) ) : $dir;
			echo sprintf( "  %s%s%s%s  =>  %s%s%s\n", $cyan, $key, $reset, str_repeat( ' ', $maxKey - strlen( $key ) ), $gray, $shortDir, $reset );
		}
		$this->helpers->msg( '' );
		return $this;
	}
}
