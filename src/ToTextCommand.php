<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'to-text',
	description: 'Print the text inside an image, read by a local Ollama vision model.',
)]
final class ToTextCommand {

	public function __construct(
		private readonly Helpers $cli,
		private ?ImageToText $reader = null,
	) {}

	#[Command(
		description: 'Transcribe the text in <image>. Exits 1 when the image has no text.',
		default: true,
	)]
	public function run(
		#[Argument( description: 'Image file to read (png, jpg, webp).', completion: 'files' )]
		string $image,
		#[Option( description: 'Ollama vision model to use (default: OCR_MODEL_SD / OCR_MODEL_LOCAL from the shared config, per store)' )]
		?string $model = null,
		#[Option( description: 'Print the result as JSON (text, model, duration_ms, error)' )]
		bool $json = false
	): int {
		$result = $this->reader()->extract( (string) $this->cli->convertPathToAbsolute( $image ), $model );

		if ( null !== $result['error'] ) {
			return $this->fail( $json, (string) $result['error'], (string) $result['errorType'], $result['model'] );
		}

		if ( $json ) {
			$this->cli->output( (string) json_encode( [
				'text'        => $result['text'],
				'model'       => $result['model'],
				'duration_ms' => $result['durationMs'],
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return 0;
		}

		$this->cli->output( (string) $result['text'] );

		return 0;
	}

	private function fail( bool $json, string $error, string $type, string $model ): int {
		if ( $json ) {
			$this->cli->output( (string) json_encode( [
				'error'      => $error,
				'error_type' => $type,
				'model'      => $model,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return 1;
		}

		$this->cli->errStderr( "Error: {$error}" );
		if ( 'transport' === $type ) {
			$this->cli->errStderr( 'Is Ollama running?' );
		} elseif ( 'api' === $type ) {
			$this->cli->errStderr( "Model: {$model}. It must have vision; check `aimodels status`, or set OCR_MODEL_SD / OCR_MODEL_LOCAL in ~/.config/ai-tooling/config." );
		}

		return 1;
	}

	private function reader(): ImageToText {
		return $this->reader ??= new ImageToText();
	}
}
