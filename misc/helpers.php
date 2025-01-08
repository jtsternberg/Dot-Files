<?php
/**
 * My CLI helpers.
 *
 * @version 1.0.1
 */

namespace JT\CLI;

/**
 * Namespaced exception.
 *
 * @since 1.0.1
 */
class Exception extends \Exception {
	public $cli  = true;
	public $data = [];
}

/**
 * My CLI helpers.
 *
 * @since 1.0.0
 * @version 1.0.1
 */
class Helpers {

	/**
	 * Single instance of this Helpers object.
	 *
	 * @var Helpers
	 */
	protected static $singleInstance = null;

	/**
	 * JT\CLI\Helpers\Git object.
	 *
	 * @since 1.0.1
	 *
	 * @var JT\CLI\Helpers\Git
	 */
	public $git;

	/**
	 * The working directory (getcwd()).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $wd = '';

	/**
	 * The current directory.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $currDir = '';

	/**
	 * The current user (get_current_user()).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $currUser = '';

	/**
	 * CLI args
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $args = [];

	/**
	 * CLI flags (e.g. --name=<name>)
	 *
	 * @since 1.0.1
	 *
	 * @var array
	 */
	public $flags = [];

	/**
	 * CLI short flags (e.g. -h)
	 *
	 * @since 1.0.1
	 *
	 * @var array
	 */
	public $shortFlags = [];

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return Helpers A single instance of this class.
	 */
	public static function getInstance() {
		if ( null === self::$singleInstance ) {
			self::$singleInstance = new self();
		}

		return self::$singleInstance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		$this->wd       = getcwd();
		$path_parts     = explode( '/', $this->wd );
		$this->currDir  = end( $path_parts );
		$this->currUser = get_current_user();
		require_once __DIR__ . '/helpers/git.php';
		$this->git      = new Helpers\Git( $this );
	}

	/**
	 * Setup our args, flags, and short flags from the provided CLI args.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $argv CLI args
	 */
	public function setArgs( $argv ) {
		$this->args = $argv;
		foreach ( $this->args as $key => $flag ) {

			if ( 0 === strpos( $flag, '-' ) ) {

				if ( 0 === strpos( $flag, '--' ) ) {
					$parts = explode( '=', $flag );
					$this->flags[ substr( $parts[0], 2 ) ] = ! empty( $parts[1] ) ? $parts[1] : '';
				} else {
					$short = substr( $flag, 1 );
					$this->shortFlags[ $short ] = $short;
				}

				unset( $this->args[ $key ] );
			}
		}

		return $this;
	}

	/**
	 * Check if given arg was passed.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $arg Arg to check.
	 *
	 * @return boolean
	 */
	public function hasArg( $arg ) {
		return in_array( $arg, $this->args );
	}

	/**
	 * Get the value of a given arg by index key.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $index    Inded for arg to get.
	 * @param  mixed  $fallback Fallback value if arg not found.
	 *
	 * @return mixed
	 */
	public function getArg( $index = 1, $fallback = null ) {
		return array_key_exists( $index, $this->args ) ? $this->args[ $index ] : $fallback;
	}

	/**
	 * Check if given short-arg (e.g. -h) was passed.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $arg Arg to check.
	 *
	 * @return boolean
	 */
	public function hasShortFlag( $key ) {
		return array_key_exists( $key, $this->shortFlags );
	}

	/**
	 * Check if given flags and optionally short-args exist.
	 *
	 * @since  1.0.1
	 *
	 * @param  array|string $flags     Single flag or array of flags to check.
	 * @param  array|string $shortFlag Single short-flag or array of flags to check.
	 *
	 * @return boolean
	 */
	public function hasFlags( $flags, $shortFlag = [] ) {
		$flags = array_filter( (array) $flags, function( $flag ) {
			return $this->hasFlag( $flag );
		} );

		if ( ! empty( $flags ) ) {
			return true;
		}

		$shortFlag = array_filter( (array) $shortFlag, function( $flag ) {
			return $this->hasShortFlag( $flag );
		} );

		return ! empty( $shortFlag );
	}

	/**
	 * Check if given flag exists (e.g. --silent)
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $flag Flag to check
	 *
	 * @return boolean
	 */
	public function hasFlag( $flag ) {
		return array_key_exists( $flag, $this->flags );
	}

	/**
	 * Get the value of a given flag (e.g. --name=<name>).
	 *
	 * @since  1.0.1
	 *
	 * @param  string $flag     Flag to get value for.
	 * @param  mixed  $fallback Fallback value if value not set.
	 *
	 * @return mixed
	 */
	public function getFlag( $flag, $fallback = null ) {
		if ( 0 === strpos( $flag, '--' ) ) {
			$flag = substr( $flag, 2 );
		}
		return $this->hasFlag( $flag, $this->flags ) ? $this->flags[ $flag ] : $fallback;
	}

	/**
	 * Whether the various "silent" flags were passed.
	 *
	 * @since  1.0.1
	 *
	 * @return boolean
	 */
	public function isSilent() {
		return $this->hasFlags( [ 'silent', 'porcelain' ], 'shh' );
	}

	/**
	 * Whether the various "verbose" flags were passed.
	 *
	 * @since  1.0.1
	 *
	 * @return boolean
	 */
	public function isVerbose() {
		return $this->hasFlags( 'verbose', 'v' );
	}

	/**
	 * If "--yes" is set, auto-confirms all prompts.
	 *
	 * @since  1.0.1
	 *
	 * @return boolean
	 */
	public function isAutoconfirm() {
		return $this->hasFlags( 'yes', 'y' );
	}

	/**
	 * CLI prompt to confirm. (e.g. "Are you sure you want to...")
	 * If the "yes" flag is set, will auto-confirm.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $question   Optional question to ask.
	 * @param  string $emptyError What to prompt if answer is not provided.
	 *
	 * @return string             The given answer.
	 */
	function confirm( $question ) {
		$this->msg( $question, 'yellow' );

		if ( $this->isAutoconfirm() ) {
			$this->msg( 'Y', 'green' );
			return true;
		}

		return $this->requestYesAnswer();
	}

	/**
	 * CLI prompt which optionally requires a response.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $question   Optional question to ask.
	 * @param  string $emptyError What to prompt if answer is not provided.
	 *
	 * @return string             The given answer.
	 */
	public function ask( $question = '', $emptyError = '' ) {
		$answer = $this->requestAnswer( $question );

		if ( $emptyError ) {
			while ( empty( $answer ) ) {
				$this->err( $emptyError, 'red' );
				$answer = $this->requestAnswer();
			}
		}

		return $answer;
	}

	/**
	 * Check if given CLI response was 'y' or 'yes', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $question Optional question to ask.
	 * @param  boolean|string $fallback Optional fallback value.
	 *
	 * @return boolean
	 */
	public function requestYesAnswer( $question = '', $fallback = false ) {
		return $this->isYes( $this->requestAnswer( $fallback, $question ) );
	}

	/**
	 * Check if given CLI response was 'n' or 'no', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $question Optional question to ask.
	 * @param  boolean|string $fallback Optional fallback value.
	 *
	 * @return boolean
	 */
	public function requestNoAnswer( $question = '', $fallback = false ) {
		return $this->isNo( $this->requestAnswer( $fallback, $question ) );
	}

	/**
	 * Prompt for an answer.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $question Optional question to ask.
	 * @param  boolean $fallback Fallback response if none given (ENTER is pushed).
	 *
	 * @return string|boolean    Answer or fallback or false.
	 */
	public function requestAnswer( $question = '', $fallback = false ) {
		if ( $question ) {
			$this->msg( $question, 'yellow' );
		}
		$handle = fopen ( 'php://stdin', 'r' );
		$answer = trim( fgets( $handle ) );
		return ! empty( $answer ) ? $answer : $fallback;
	}

	/**
	 * Check if given answer is 'y' or 'yes', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $answer Given answer.
	 *
	 * @return boolean
	 */
	public function isYes( $answer ) {
		return in_array( strtolower( $answer ), [
			'y', 'yes',
		] );
	}

	/**
	 * Check if given answer is 'n' or 'no', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  string $answer Given answer.
	 *
	 * @return boolean
	 */
	public function isNo( $answer ) {
		return in_array( strtolower( $answer ), [
			'n', 'No',
		] );
	}

	/**
	 * Output formatted error message if the silent flag is not set.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $text      Error message to output.
	 * @param  boolean $lineBreak Whether to add a trailing line-break. Default, true.
	 *
	 * @return Helpers
	 */
	public function err( $text, $lineBreak = true ) {
		if ( ! $this->isSilent() ) {
			echo $this->getErr( $text, $lineBreak );
		}

		return $this;
	}

	/**
	 * Outputs a formatted error message and exits with a given code.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $args      Error message to output.
	 * @param  integer $code      Exit code. Default, 1.
	 * @param  boolean $lineBreak Whether to add a trailing line-break. Default, true.
	 *
	 * @return Helpers
	 */
	public function exitErr( $args, $code = 1, $lineBreak = true ) {
		$this->err( $args, $lineBreak );
		exit( $code );
	}

	/**
	 * Get a formatted error message.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $text      Error message to output.
	 * @param  boolean $lineBreak Whether to add a trailing line-break. Default, true.
	 *
	 * @return string
	 */
	public function getErr( $text, $lineBreak = true ) {
		return $this->getMsg( $text, 'red', $lineBreak );
	}

	/**
	 * Outputs a formatted message if the silent flag is not set.
	 *
	 * @since  1.0.0
	 *
	 * @param  string  $text      Message to output.
	 * @param  string  $color     Optional color for message.
	 * @param  boolean $lineBreak Whether to add a trailing line-break. Default, true.
	 *
	 * @return Helpers
	 */
	public function msg( $text, $color = '', $lineBreak = true ) {
		if ( ! $this->isSilent() ) {
			echo $this->getMsg( $text, $color, $lineBreak );
		}

		return $this;
	}

	/**
	 * Get a formatted message.
	 *
	 * @since  1.0.0
	 *
	 * @param  string  $text      Message to output.
	 * @param  string  $color     Optional color for message.
	 * @param  boolean $lineBreak Whether to add a trailing line-break. Default, true.
	 *
	 * @return string
	 */
	public function getMsg( $text, $color = '', $lineBreak = true ) {
		return $this->color( $color ) . $text . $this->color( 'none' ) . ( $lineBreak ? PHP_EOL : '' );
	}

	/**
	 * Get a cli-formatted color indicator.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $color Color to get.
	 *
	 * @return string
	 */
	public function color( $color ) {
		$colors = array(
			'red_bg'        => "\e[1;37;41m",
			'none'          => "\033[0m",
			'default'       => "\033[39m",
			'black'         => "\033[30m",
			'red'           => "\033[31m",
			'green'         => "\033[32m",
			'yellow'        => "\033[33m",
			'blue'          => "\033[34m",
			'magenta'       => "\033[35m",
			'cyan'          => "\033[36m",
			'light_gray'    => "\033[37m",
			'dark_gray'     => "\033[90m",
			'light_red'     => "\033[91m",
			'light_green'   => "\033[92m",
			'light_yellow'  => "\033[93m",
			'light_blue'    => "\033[94m",
			'light_magenta' => "\033[95m",
			'light_cyan'    => "\033[96m",
			'white'         => "\033[97m",
		);

		return $color && isset( $colors[ $color ] )
			? $colors[ $color ]
			: '';
	}

	/**
	 * Write given contents to given file.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $file     File name or path. Defaults to using relative path.
	 * @param  string $contents Content to write to file.
	 * @param  array  $args     Additional args ([relative => <bool>, failexit => <bool>])
	 *
	 * @return mixed            Results of call to file_put_contents.
	 */
	public function writeToFile( $file, $contents, $args = [] ) {
		$args = array_merge( [
			'relative' => true,
			'failExit' => true,
			'flags' => 0,
			'silent' => false,
		], $args );
		$ssshhhhh = $this->isSilent() || $args['silent'];

		if ( $args['relative'] ) {
			$file = $this->wd . '/' . $file;
		} else {
			$file = $this->convertPathToAbsolute( $file );
		}

		if ( ! $ssshhhhh ) {
			echo $file .' $contents: ';
			print_r( $contents );
			echo "\n--------------------\n\n";
		}

		$results = file_put_contents( $file, $contents, $args['flags'] );

		if ( ! $ssshhhhh ) {
			echo $file .' $results: ';
			print_r( $results );
			echo "\n--------------------\n\n";

			if ( empty( $results ) && ! empty( $contents ) ) {
				$this->msg( sprintf( 'Failed to write to file (%s). ABORTING', $file ), 'red' );
				if ( $args['failExit'] ) {
					exit( 1 );
				}
			}
		}

		return $results;
	}

	/**
	 * Convert a path to an absolute path.
	 *
	 * @param  string $filename The path to convert.
	 * @param  string $base     Optional base path. Defaults to working directory.
	 *
	 * @return string The absolute path.
	 */
	public function convertPathToAbsolute( $filename, $base = null ) {
		$base = null === $base ? $this->wd : $base;
		if ( '/' !== strrev($base) ) {
			$base .= '/';
		}

		$filename = str_replace( '~', getenv( 'HOME' ), $filename );

		// return if already absolute
		if (parse_url($filename, PHP_URL_SCHEME) != '') {
			return $filename;
		}

		// parse base:
		$bits = parse_url($base);

		// remove non-directory element from path
		$path = preg_replace('#/[^/]*$#', '', $bits['path']);

		// destroy path if relative path points to root
		if ($filename[0] == '/') {
			$path = '';
		}

		// dirty absolute path
		$abs = "$path/$filename";

		// replace '//' or '/./' or '/foo/../' with '/'
		$re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
		for(
			$n = 1; $n > 0;
			$abs = preg_replace( $re, '/', $abs, -1, $n )
		) {}

		// absolute path is ready!
		return $abs;
	}

	// Get files of type w/in a directory.
	public function getDirFiles( $dir, $type = '', $sort = 'modifiedDesc' ) {
		$files = array();
		$dir = new \DirectoryIterator( $dir );
		foreach ( $dir as $fileinfo ) {
			if ( ! $type || $type === $fileinfo->getExtension() ) {

				// Modification Time: is the time when the contents of the file was last modified. For example, you used an editor to add new content or delete some existing content.
				$files[ $fileinfo->getMTime() ] = $fileinfo->getFilename();
			}
		}

		switch ( $sort ) {
			case 'modifiedAsc':
				ksort( $files );
				break;
			case 'modifiedDesc':
			default:
				krsort( $files );
				break;
		}

		return $files;
	}

	// Fetches file contents and filters the rows by callback.
	public function filteredFileContentRows( $file, $filterCb ) {
		$handle = @fopen( $file, "r" );
		$lines = [];
		if ( ! empty( $handle ) ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$line = $filterCb( $line );
				if ( false !== $line ) {
					$lines[] = $line;
				}
			}

			fclose($handle);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get help object for a command.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $scriptName The name of the script command to provide help for.
	 * @param  array   $commands   Array of sub-commands and related docs.
	 *
	 * @return Help
	 */
	public function getHelp( string $scriptName = '', array $commands = [] ) {
		require_once __DIR__ . '/helpers/help.php';
		return new Helpers\Help( $this, $scriptName, $commands );
	}
}

return Helpers::getInstance()->setArgs( $argv );
