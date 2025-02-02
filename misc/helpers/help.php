<?php
/**
 * CLI Help handler.
 *
 * Setup multi-command help:
 * 	$helpyHelperton
 * 		->setDescription( 'This script provides several tools for working with and displaying git tags.' )
 * 		->setup( 'tag', [
 * 			'all' => [
 * 	'[--reverse]',
 * 	'Outputs all tags in chronological order.',
 * 	'-r, --reverse Optionally output in reverse chronological order.'
 * 			],
 * 			'some' => [
 * 	'[<number-rows>]',
 * 	'Outputs a limited number (25 by default) of tag rows, in chronological order.',
 * 	'<number-rows> Optionally define the maximum number of rows to display.'
 * 			],
 * 	'-y, --yes       Will autoconfirm all prompts, and push without delay.
 *
 * 	-shh, --silent  Used to return clean output (no prompts/messaging)
 * 	                to other scripts.',
 * 			],
 * 		] );
 *
 * Setup single command help:
 * $helpyHelperton = $cli->getHelp();
 * 	$helpyHelperton
 * 		->setScriptName( 'baconipsum' )
 * 		->setPrefix( '' )
 * 		->setDescription( 'Returns meaty lorem ipsum text. Uses the Bacon Ipsum JSON API' )
 * 		->setSampleUsage( '[<number-paragraphs-or-sentences>] [<format>|--format=<format>] [<type>|--type=<type>] [--sentences|-s] [--start-with-lorem]' )
 * 		->buildDocs( [
 * 			'[<number-paragraphs-or-sentences>]' => 'Optional number of paragraphs (or sentences when using --sentences flag), defaults to 5.',
 * 			'<format>, --format=<format>'        => '‘json’ (default), ‘text’, or ‘html’',
 * 			'<type>, --type=<type>'              => '‘all-meat’ (default) for meat only or ‘meat-and-filler’ for meat mixed with miscellaneous "lorem ipsum" filler.',
 * 			'-s, --sentences'                    => 'Use sentences instead of paragraphs.',
 * 			'--start-with-lorem'                 => 'Start the first paragraph with ‘Bacon ipsum dolor sit amet’.',
 * 		] );

 *
 * @since 1.0.1
 */

namespace JT\CLI\Helpers;

use JT\CLI\Helpers;

/**
 * CLI Help handler.
 *
 * @since 1.0.1
 */
class Help {

	/**
	 * The CLI helper.
	 *
	 * @var Helpers
	 */
	protected $cli;

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
	public $prefix = '';

	/**
	 * The help message output.
	 *
	 * @var string
	 */
	public $output = '';

	/**
	 * The invalide message prefix.
	 *
	 * @var string
	 */
	public $invalid = 'Invalid command! ';

	/**
	 * The command sample usage
	 *
	 * @var string
	 */
	public $sampleUsage = '';

	/**
	 * The description for the command..
	 *
	 * @var string
	 */
	public $description = '';

	/**
	 * Whether the current request is requesting help.
	 *
	 * @var boolean
	 */
	public $batSignal = false;
	public $options = 'usage: ';
	protected $defaultCommandOptions = '';

	public function __construct( Helpers $cli, string $scriptName = '', array $commands = [] ) {
		$this->cli       = $cli;
		$this->batSignal = $cli->hasFlags( [ 'h', 'help' ], 'h' );

		if ( $scriptName && ! empty( $commands ) ) {
			$this->setup( $scriptName, $commands );
		}
	}

	public function setup( string $scriptName = '', array $commands = [] ) {
		$this
			->setScriptName( $scriptName )
			->setCommands( $commands );

		$hasSubCommands = false;
		$output = [];
		foreach ( $this->commands as $command => $args ) {
			if ( ! is_numeric( $command ) ) {
				if ( is_array( $args ) ) {
					$args = $args[0];
				}
				$output[] = "{$command} {$args}";
				$hasSubCommands = true;
			} else {
				if ( 0 === $command ) {
					$output[] = print_r( $args, true ) . "\n";
				} else {
					$output[] = print_r( $args, true );
				}
			}
		}

		if ( $hasSubCommands ) {
			$this->options .= "\n   {$this->scriptName} " . implode( "\n   or: {$this->scriptName} ", $output );
			$this->options .= "\n\n   or: ";
		} else {
			$this->options .= "\n   {$this->scriptName} " . implode( "\n   ", $output );

		}

		$this->options .= "{$this->scriptName} {$this->helpFlag}\n";

		$this->options .= "   or: {$this->scriptName} <command> [-h|--help] to display sub-command help.\n";

		if ( $this->defaultCommandOptions ) {
			$this->setupOutput('');

			$output = $this->defaultCommandOptions;
			$output .= "\n\n";
			$output .= $this->prefix ?: 'Sub-commands ';
			$output .= $this->options;

			$this->output .= $output;
			$this->invalid .= $output;

		} else {
			$this->setupOutput( $this->prefix ?: 'Which command? ' );
			$this->output .= $this->options;
			$this->invalid .= $this->options;
		}

		return $this;
	}

	public function setupDefaultCommand( array $commandArgs ) {
		if ( ! $this->scriptName ) {
			throw new \Exception( 'Script name is required to setup default command. Call setScriptName() first.' );
		}

		$this->defaultCommandOptions = $this->buildOptions( $commandArgs );

		return $this;
	}

	// Used for single command scripts.
	public function buildDocs( array $commandArgs ) {
		$options = $this
			->setupOutput($this->prefix)
			->buildOptions( $commandArgs );

		$options .= "\n\nor: {$this->scriptName} {$this->helpFlag}\n";

		$this->output .= $options;
		$this->invalid .= $options;

		return $this;
	}

	private function setupOutput( $prefix ) {
		if ( $this->description ) {
			$this->output .= "\n" . $this->description . "\n\n";
		}

		$this->output .= $prefix;

		return $this;
	}

	public function buildOptions( array $commandArgs ) {
		$output = '';
		if ( ! empty( $commandArgs ) ) {
			$outputs = [];
			$buffer = max( array_map( 'strlen', array_keys( $commandArgs ) ) ) + 1;
			foreach ( $commandArgs as $arg => $argDesc ) {
				$str = "{$arg} ";
				$argLength = strlen( $arg );
				$padding = $buffer - $argLength;
				$argBuffer = '';
				if ( ! empty( $padding ) ) {
					$argBuffer = str_repeat( ' ', $padding );
					$str .= $argBuffer;
				}

				$lineBuffer = str_repeat( ' ', strlen( $str ) );
				$chunkedString = explode( '~~', wordwrap( $argDesc, 80, '~~' ) );
				if ( count( $chunkedString ) > 1 ) {
					foreach ( $chunkedString as $index => $string ) {
						if ( $index > 0 ) {
							$chunkedString[ $index ] = $lineBuffer . '   ' . $string;
						}
					}
					$str .= implode( "\n", $chunkedString ) . "\n";
				} else {
					$str .= $argDesc;
				}

				$outputs[] = $str;
			}

			$output = "\n   " . implode( "\n   ", $outputs );
		}

		$options = "usage: {$this->scriptName} ";

		if ( $this->sampleUsage ) {
			$options .= "{$this->sampleUsage} \n";
		}

		$options .= $output;

		return $options;
	}

	public function setPrefix( $prefix ) {
		$this->prefix = $prefix;

		return $this;
	}

	public function setDescription( $description ) {
		$this->description = $description;

		return $this;
	}

	public function setSampleUsage( $sampleUsage ) {
		$this->sampleUsage = $sampleUsage;

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