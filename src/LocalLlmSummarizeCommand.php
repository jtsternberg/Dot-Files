<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'local-llm-summarize',
	description: 'Summarize a file, or an archived agent session, with a local Ollama model.',
)]
final class LocalLlmSummarizeCommand {

	/** @var callable():string */
	private $readStdin;

	public function __construct(
		private readonly Helpers $cli,
		private ?LocalLlmSummarizer $summarizer = null,
		?callable $readStdin = null,
	) {
		$this->readStdin = $readStdin ?: static fn(): string => (string) stream_get_contents( STDIN );
	}

	#[Command(
		description: 'Summarize <file> (or stdin) in a few sentences; --session gives a TITLE plus two sentences for a graveyard transcript.md.',
		default: true,
	)]
	public function run(
		// The shared parser strips a bare "-" as a flag, so stdin is spelled by omission.
		#[Argument( description: 'File to summarize; omit it to read stdin.', completion: 'files' )]
		?string $file = null,
		#[Option( description: 'Treat the input as an archived session transcript and return a title plus two sentences' )]
		bool $session = false,
		#[Option( description: 'Ollama model to use (default: SUMMARY_MODEL_SD / SUMMARY_MODEL_LOCAL from the shared config, per store)' )]
		?string $model = null,
		#[Option( description: 'Print the result as JSON (title, summary, model, duration_ms, error)' )]
		bool $json = false
	): int {
		$input = $this->read( $file );
		if ( null === $input ) {
			return $this->fail( $json, "No such file: {$file}", 'input' );
		}

		$result = $session
			? $this->summarizer()->summarizeSession( $input, $model )
			: $this->summarizer()->summarizeText( $input, $model );

		if ( null !== $result['error'] ) {
			return $this->fail( $json, (string) $result['error'], (string) $result['errorType'], $result['model'] );
		}

		if ( $json ) {
			$this->cli->output( (string) json_encode( [
				'title'       => $result['title'],
				'summary'     => $result['text'],
				'model'       => $result['model'],
				'duration_ms' => $result['durationMs'],
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return 0;
		}

		if ( null !== $result['title'] ) {
			$this->cli->output( $result['title'] . "\n" );
		}
		$this->cli->output( (string) $result['text'] );

		return 0;
	}

	private function read( ?string $file ): ?string {
		if ( null === $file || '' === $file ) {
			return ( $this->readStdin )();
		}

		$path = (string) $this->cli->convertPathToAbsolute( $file );

		return is_file( $path ) ? (string) file_get_contents( $path ) : null;
	}

	private function fail( bool $json, string $error, string $type, ?string $model = null ): int {
		if ( $json ) {
			$this->cli->output( (string) json_encode( [
				'error'      => $error,
				'error_type' => $type,
				'model'      => $model,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return 1;
		}

		$this->cli->err( "Error: {$error}" );
		if ( 'transport' === $type ) {
			$this->cli->err( 'Is Ollama running?' );
		} elseif ( 'api' === $type && null !== $model ) {
			$this->cli->err( "Model: {$model}. Check `aimodels status`, or set SUMMARY_MODEL_SD / SUMMARY_MODEL_LOCAL in ~/.config/auto-commit-ollama/config." );
		}

		return 1;
	}

	private function summarizer(): LocalLlmSummarizer {
		return $this->summarizer ??= new LocalLlmSummarizer();
	}
}
