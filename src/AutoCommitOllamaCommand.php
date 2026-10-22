<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use JT\Helpers\Ollama;

#[Program(
	name: 'auto-commit-ollama',
	description: 'Generate a commit message from staged changes using a local Ollama model and create the commit.',
)]
final class AutoCommitOllamaCommand extends AutoCommitCommand {

	const DEBUG_FILE = '/tmp/auto-commit-ollama-debug.txt';

	public function __construct(
		Helpers $cli,
		private ?Ollama $ollama = null,
		?callable $shell = null,
		?callable $runWithCode = null,
		?callable $writeFile = null,
		?callable $speak = null,
	) {
		parent::__construct( $cli, $shell, $runWithCode, $writeFile, $speak );
	}

	#[Command(
		description: 'Generate a commit message from staged changes and create the commit.',
		default: true,
	)]
	public function run(
		#[Argument( description: 'Suggested commit message or context for the commit' )]
		?string $context = null,
		#[Option( description: 'Ollama model to use (default: auto-detected from storage location via config)' )]
		?string $model = null,
		#[Option(
			description: 'Regenerate commit message for HASH (default: HEAD) and amend it',
			valueName: 'hash',
			optionalValue: true,
		)]
		?string $retry = null,
		#[Option(
			description: 'Note the problem with the existing message directly, to steer the retry analysis (use with --retry)',
			valueName: 'reason',
		)]
		?string $retryReason = null,
		#[Option( description: 'Save the full prompt to ' . self::DEBUG_FILE )]
		bool $debug = false,
		#[Option( description: 'Speak the result using macOS say command' )]
		bool $say = false,
		#[Option( description: 'Suppress printing the result' )]
		bool $noprint = false
	): int {
		return $this->execute(
			$model ?: $this->resolveModel(),
			$context,
			$retry,
			$retryReason,
			$debug,
			$say,
			$noprint
		);
	}

	protected function backendName(): string {
		return 'Ollama';
	}

	protected function debugFile(): string {
		return self::DEBUG_FILE;
	}

	protected function signatureTemplate(): string {
		return (string) ( $this->ollama()->config()['AUTO_COMMIT_OLLAMA_SIGNATURE'] ?? '' );
	}

	/** @return ?string Null on any Ollama failure; the error is already reported. */
	protected function complete( string $model, string $systemPrompt, string $userPrompt ): ?string {
		$result = $this->ollama()->chat( $model, $systemPrompt, $userPrompt );

		if ( 'transport' === $result['errorType'] ) {
			$this->cli->err( "Error connecting to Ollama: {$result['error']}" );
			$this->cli->err( 'Is Ollama running?' );

			return null;
		}

		if ( 'api' === $result['errorType'] ) {
			$error = (string) $result['error'];
			$this->cli->err( "Error from Ollama: {$error}" );

			if ( $this->isModelNotFound( $error ) ) {
				$this->reportMissingModel( $model );
			}

			return null;
		}

		return $result['content'] ?? '';
	}

	/** Ollama has no error codes, so a missing model is only knowable from its wording. */
	private function isModelNotFound( string $error ): bool {
		return false !== stripos( $error, 'model' )
			&& (
				false !== stripos( $error, 'not found' )
				|| false !== stripos( $error, 'does not exist' )
				|| false !== stripos( $error, 'unknown' )
			);
	}

	/**
	 * The usual cause is a model that exists on the other storage location, so
	 * say which one is mounted alongside what is actually installed.
	 */
	private function reportMissingModel( string $model ): void {
		$this->cli->err( '' );
		$this->cli->msg( "Requested model: {$model}", 'yellow' );

		$available = ( $this->shell )( 'ollama list 2>/dev/null' );
		if ( '' !== $available ) {
			$this->cli->msg( "\nAvailable models (ollama list):", 'cyan' );
			$this->cli->output( $available );
		} else {
			$this->cli->msg( 'No models found. Pull one with: ollama pull <model>', 'yellow' );
		}

		$storage = $this->ollama()->storagePath( $this->home() );
		if ( null !== $storage ) {
			$this->cli->msg( "\nModels storage: {$storage}", 'cyan' );
			$this->cli->msg( 'Switch with: ollamodels [local|sd]', 'cyan' );
		}

		$this->cli->err( "\nTo fix:" );
		$this->cli->err( "  1. Pull the model:   ollama pull {$model}" );
		$this->cli->err( '  2. Use a different model: auto-commit-ollama --model=<name>' );
		$this->cli->err( '  3. Update config:    ~/.config/ai-tooling/config' );
		$this->cli->err( '     (MODEL, MODEL_LOCAL, MODEL_SD)' );
	}

	private function resolveModel(): string {
		return $this->ollama()->resolveModel(
			$this->ollama()->config(),
			Ollama::DEFAULT_MODEL,
			$this->home()
		);
	}

	private function ollama(): Ollama {
		if ( null === $this->ollama ) {
			$this->ollama = new Ollama();
		}

		return $this->ollama;
	}
}
