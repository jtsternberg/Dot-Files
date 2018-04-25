<?php
class JT_CLI_Helpers {

	protected static $single_instance = null;
	public $wd = '';
	public $currDir = '';
	public $currUser = '';
	public $args = array();

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return JT_CLI_Helpers A single instance of this class.
	 */
	public static function getInstance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	protected function __construct() {
		$this->wd = getcwd();
		$path_parts = explode( '/', $this->wd );
		$this->currDir = end( $path_parts );
		$this->currUser = get_current_user();
	}

	public function setArgs( $argv ) {
		$this->args = $argv;

		return $this;
	}

	public function getArg( $key = 1, $default = null ) {
		return isset( $this->args[ $key ] ) ? $this->args[ $key ] : null;
	}

	public function ask( $toAsk, $emptyError = '' ) {
		echo "{$toAsk}\n";

		$answer = $this->getAnswer();

		if ( $emptyError ) {
			while ( empty( $answer ) ) {
				echo "{$emptyError}\n";
				$answer = $this->getAnswer();
			}
		}

		return $answer;
	}

	public function getAnswer( $default = null ) {
		$handle = fopen ( 'php://stdin', 'r' );
		$answer = trim( fgets( $handle ) );
		return empty( $answer ) ? $default : $answer;
	}

	public function msg( $text, $color = '', $line_break = true ) {
		echo $this->getMsg( $text, $color, $line_break );

		return $this;
	}

	public function getMsg( $text, $color, $line_break = true ) {
		return $this->color( $color ) . $text . $this->color( 'none' ) . ( $line_break ? PHP_EOL : '' );
	}

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

	public function writeToFile( $file, $contents, $args = [] ) {
		$args = array_merge( [
			'relative' => true,
			'silent'   => false,
			'failExit' => true,
		], $args );

		if ( $args['relative'] ) {
			$file = $this->wd . '/' . $file;
		}

		if ( ! $args['silent'] ) {
			echo $file .' $contents: ';
			print_r( $contents );
			echo "\n--------------------\n\n";
		}

		$results = file_put_contents( $file, $contents );

		if ( ! $args['silent'] ) {
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

return JT_CLI_Helpers::getInstance()->setArgs( $argv );
