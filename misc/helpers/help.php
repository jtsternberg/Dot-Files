<?php
/**
 * CLI Help handler.
 *
 * @since 1.0.1
 */

namespace JT\CLI\Helpers;

/**
 * CLI Help handler.
 *
 * @since 1.0.1
 */
class Help {

	/**
	 * Nave of the script being documented with help.
	 *
	 * @var string
	 */
	public $scriptName = '';

	/**
	 * Array of commands for outputting in the help docs.
	 *
	 * @var array
	 */
	public $commands = [];

	/**
	 * Documents the help flag.
	 *
	 * @var string
	 */
	public $helpFlag = '[-h|--help] to display this message';

	/**
	 * The help message prefix.
	 *
	 * @var string
	 */
	public $output = 'Which command? ';

	/**
	 * The invalide message prefix.
	 *
	 * @var string
	 */
	public $invalid = 'Invalid command! ';

	/**
	 * Whether the current request is requesting help.
	 *
	 * @var boolean
	 */
	public $batSignal = false;
	public $options = 'usage: ';

	public function __construct( Helpers $cli, string $scriptName = '', array $commands = [] ) {
		$this->cli       = $cli;
		$this->batSignal = $cli->hasFlags( [ 'h', 'help' ], 'h' );

		if ( $scriptName && ! empty( $commands ) ) {
			$this->setup( $scriptName, $commands );
		}
	}

	public function setup( string $scriptName = '', array $commands ) {
		$this
			->setScriptName( $scriptName )
			->setCommands( $commands );

		$output = [];
		foreach ( $this->commands as $command => $args ) {
			if ( is_array( $args ) ) {
				$args = $args[0];
			}
			$output[] = "{$command} {$args}";
		}
		$this->options .= "\n   {$this->scriptName} " . implode( "\n   or: {$this->scriptName} ", $output );
		$this->options .= "\n\n   or: {$this->scriptName} {$this->helpFlag}\n";
		$this->options .= "   or: {$this->scriptName} <command> [-h|--help] to display sub-command help.\n";

		$this->output .= $this->options;
		$this->invalid .= $this->options;

		return $this;
	}

	public function setScriptName( $scriptName = '' ) {
		$this->scriptName = $scriptName;

		return $this;
	}

	public function setCommands( array $commands ) {
		$this->commands = $commands;

		return $this;
	}

	public function getHelp( $command = '' ) {
		if ( ! $command || ! isset( $this->commands[ $command ] ) ) {
			return $this->output;
		}

		$info   = (array) $this->commands[ $command ];
		$args   = array_shift( $info );
		$output = '';
		if ( ! empty( $info ) ) {
			$output .= $this->cli->getMsg( array_shift( $info ), 'yellow' );
			$output .= "\n";
		}

		$output .= $this->cli->getMsg( "usage: {$this->scriptName} {$command} {$args}" );

		if ( ! empty( $info ) ) {
			$output .= "\n";

			foreach ( $info as $msg ) {

				$lines = explode( "\n", $msg );
				if ( count( $lines ) > 1 ) {
					$msg = implode( "\n    ", explode( "\n", $msg ) );
				}
				$output .= $this->cli->getMsg( "    $msg", 'yellow' );
			}
		}

		return $output;
	}

}