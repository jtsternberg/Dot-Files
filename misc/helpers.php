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

	protected static $single_instance = null;

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
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		$this->wd = getcwd();
		$path_parts = explode( '/', $this->wd );
		$this->currDir = end( $path_parts );
		$this->currUser = get_current_user();
		$this->git = require_once __DIR__ . '/helpers/git.php';
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
					$this->flags[ substr( $parts[0], 2 ) ] = $parts[1];
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
	 * Whether the various "silenct" flags were passed.
	 *
	 * @since  1.0.1
	 *
	 * @return boolean
	 */
	public function isSilent() {
		$found = ! empty( $this->flags ) ? array_intersect(
			array_keys( $this->flags ),
			[
				'silent',
				'porcelain',
				'shh',
			]
		) : false;
		return ! empty( $found );
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
	 * CLI prompt which optionally requires a response.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $toAsk      Question for prompt.
	 * @param  string $emptyError What to prompt if answer is not provided.
	 *
	 * @return string             The given answer.
	 */
	public function ask( $toAsk, $emptyError = '' ) {
		$this->msg( $toAsk, 'yellow' );

		$answer = $this->getAnswer();

		if ( $emptyError ) {
			while ( empty( $answer ) ) {
				$this->err( $emptyError, 'red' );
				$answer = $this->getAnswer();
			}
		}

		return $answer;
	}

	/**
	 * Check if given CLI response was 'y' or 'yes', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  boolean|string $fallback Optional fallback value.
	 *
	 * @return boolean
	 */
	public function isYesAnswer( $fallback = false ) {
		return $this->isYes( $this->getAnswer( $fallback ) );
	}

	/**
	 * Check if given CLI response was 'n' or 'no', case-insensitive.
	 *
	 * @since  1.0.1
	 *
	 * @param  boolean|string $fallback Optional fallback value.
	 *
	 * @return boolean
	 */
	public function isNoAnswer( $fallback = false ) {
		return $this->isNo( $this->getAnswer( $fallback ) );
	}

	/**
	 * Prompt for an answer.
	 *
	 * @since  1.0.0
	 *
	 * @param  boolean $fallback Fallback response if none given (ENTER is pushed).
	 *
	 * @return string|boolean    Answer or fallback or false.
	 */
	public function getAnswer( $fallback = false ) {
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
			echo $this->getErr( $text, $lineBreak = true );
		}

		return $this;
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
		return $this->getMsg( $text, 'red', $lineBreak = true );
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
	public function getMsg( $text, $color, $lineBreak = true ) {
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
			'red_bg' => "\e[1;37;41m",
			'black' => "\033[30m",
			'blue' => "\033[34m",
			'green' => "\033[32m",
			'cyan' => "\033[36m",
			'red' => "\033[31m",
			'purple' => "\033[35m",
			'yellow' => "\033[33m",
			'none' => "\033[0m",
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
		], $args );

		if ( $args['relative'] ) {
			$file = $this->wd . '/' . $file;
		}

		if ( ! $this->isSilent() ) {
			echo $file .' $contents: ';
			print_r( $contents );
			echo "\n--------------------\n\n";
		}

		$results = file_put_contents( $file, $contents );

		if ( ! $this->isSilent() ) {
			echo $file .' $results: ';
			print_r( $results );
			echo "\n--------------------\n\n";

			if ( empty( $results ) ) {
				$this->msg( sprintf( 'Failed to write to file (%s). ABORTING', $file ), 'red' );
				if ( $args['failExit'] ) {
					exit( 1 );
				}
			}
		}

		return $results;
	}
}

return Helpers::getInstance()->setArgs( $argv );