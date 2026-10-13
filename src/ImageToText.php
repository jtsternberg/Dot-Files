<?php
namespace JT;

use JT\Helpers\Ollama;

/**
 * Read the text out of an image with a local Ollama vision model.
 *
 * The model resolves through the shared ~/.config/ai-tooling/config under its
 * own OCR_MODEL* keys, because the commit tool's MODEL* keys name code models,
 * which have no image input.
 */
class ImageToText {

	const CONFIG_PREFIX = 'OCR_MODEL';

	/**
	 * Last resort when the config names nothing: a vision model present in the
	 * local store, so it survives an AI-LAB eject. 2026-10-13: transcribed a
	 * four-line test image exactly in ~10s, matching gemma4:26b-nvfp4 at ~23s.
	 */
	const DEFAULT_MODEL = 'qwen3.5:9b';

	/** A model Ollama has unloaded takes ~30s to load back, and a dense page is a long reply. */
	const TIMEOUT_SECONDS = 300;

	/** Image tokens count against the context; a full-screen screenshot runs a few thousand. */
	const NUM_CTX = 16384;

	/** Caps a model that loops on repeated text instead of stopping. */
	const NUM_PREDICT = 8192;

	/** Told "write nothing", qwen3.5:9b wrote a sentence saying so; a fixed token it repeats reliably. */
	const NO_TEXT = 'NO_TEXT';

	const PROMPT = 'Transcribe all of the text in this image exactly as written, keeping its line breaks '
		. 'and reading order. Write only the transcribed text, with no commentary, description, or '
		. 'code fences. If the image contains no text, write only ' . self::NO_TEXT . '.';

	public function __construct( private ?Ollama $ollama = null, private ?string $home = null ) {}

	/** The model a transcription would use right now: explicit, else per-store config, else the default. */
	public function resolveModel( ?string $model = null ): string {
		if ( null !== $model && '' !== trim( $model ) ) {
			return trim( $model );
		}

		return $this->ollama()->resolveModel(
			$this->ollama()->config(),
			self::DEFAULT_MODEL,
			$this->home(),
			self::CONFIG_PREFIX
		);
	}

	/**
	 * @return array{text:?string, model:string, durationMs:int, error:?string, errorType:?string}
	 */
	public function extract( string $imagePath, ?string $model = null ): array {
		$model = $this->resolveModel( $model );

		if ( ! is_file( $imagePath ) || ! is_readable( $imagePath ) ) {
			return $this->failure( $model, "No such image: {$imagePath}", 'input' );
		}

		$started = microtime( true );
		$reply   = $this->ollama()->chat(
			$model,
			'',
			self::PROMPT,
			Ollama::DEFAULT_URL,
			self::TIMEOUT_SECONDS,
			[
				// A thinking model otherwise reasons at length before transcribing.
				'think'   => false,
				'options' => [ 'temperature' => 0, 'num_ctx' => self::NUM_CTX, 'num_predict' => self::NUM_PREDICT ],
			],
			[ base64_encode( (string) file_get_contents( $imagePath ) ) ]
		);
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( null !== $reply['error'] ) {
			return $this->failure( $model, (string) $reply['error'], (string) $reply['errorType'], $ms );
		}

		$text = trim( (string) $reply['content'] );
		if ( '' === $text || self::NO_TEXT === $text ) {
			return $this->failure( $model, 'no text found in the image', 'empty', $ms );
		}

		return [
			'text'       => $text,
			'model'      => $model,
			'durationMs' => $ms,
			'error'      => null,
			'errorType'  => null,
		];
	}

	/** @return array{text:null, model:string, durationMs:int, error:string, errorType:string} */
	private function failure( string $model, string $error, string $type, int $ms = 0 ): array {
		return [
			'text'       => null,
			'model'      => $model,
			'durationMs' => $ms,
			'error'      => $error,
			'errorType'  => $type,
		];
	}

	private function home(): string {
		return $this->home ?? ( getenv( 'HOME' ) ?: '' );
	}

	private function ollama(): Ollama {
		return $this->ollama ??= new Ollama();
	}
}
