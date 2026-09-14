<?php
namespace JT\Helpers;

/**
 * Ollama — resolve which local model to use, and talk to its chat API.
 *
 * Model resolution follows bin/auto-commit-ollama's storage-aware scheme:
 * which ~/.config/auto-commit-ollama/config key applies depends on where the
 * ~/.ollama-models symlink currently points (an SD card vs. local disk). That
 * config file predates this class and isn't commit-specific despite its
 * directory name — it's "which local model to use," so every local-model
 * tool in this repo reads the same one rather than each keeping its own.
 * auto-commit-ollama still carries its own copy of this logic (dotfiles-206
 * tracks migrating it here too).
 */
class Ollama {

	const DEFAULT_URL = 'http://localhost:11434/api/chat';

	/** Storage path that means "the SD card", per auto-commit-ollama. */
	const SD_PATH = '/Volumes/AI-LAB/ollama/models';

	/** @var callable(string):(string|false) */
	private $readlink;

	/** @var callable(string, string, int):array{0:?string, 1:string} */
	private $post;

	public function __construct(
		?callable $readlink = null,
		?callable $post = null
	) {
		$this->readlink = $readlink ?: static fn( string $path ) =>
			is_link( $path ) ? readlink( $path ) : false;
		$this->post = $post ?: [ $this, 'curlPost' ];
	}

	/**
	 * @param array<string,string> $config Parsed ~/.config/auto-commit-ollama/config.
	 */
	public function resolveModel( array $config, string $defaultModel, string $home ): string {
		$default = $config['MODEL'] ?? $defaultModel;
		$target  = ( $this->readlink )( $home . '/.ollama-models' );

		if ( false === $target ) {
			return $default;
		}

		return 0 === strpos( $target, self::SD_PATH )
			? ( $config['MODEL_SD'] ?? $default )
			: ( $config['MODEL_LOCAL'] ?? $default );
	}

	/**
	 * @return array{content:?string, error:?string}
	 */
	public function chat(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		string $url = self::DEFAULT_URL,
		int $timeoutSeconds = 300
	): array {
		$payload = (string) json_encode( [
			'model'    => $model,
			'stream'   => false,
			'messages' => [
				[ 'role' => 'system', 'content' => $systemPrompt ],
				[ 'role' => 'user', 'content' => $userPrompt ],
			],
		] );

		[ $body, $error ] = ( $this->post )( $url, $payload, $timeoutSeconds );

		if ( '' !== $error ) {
			return [ 'content' => null, 'error' => $error ];
		}

		$data = json_decode( (string) $body, true );
		if ( ! empty( $data['error'] ) ) {
			return [ 'content' => null, 'error' => (string) $data['error'] ];
		}

		return [ 'content' => $data['message']['content'] ?? null, 'error' => null ];
	}

	/** @return array{0:?string, 1:string} [response body, curl error] */
	private function curlPost( string $url, string $payload, int $timeoutSeconds ): array {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
			CURLOPT_TIMEOUT        => $timeoutSeconds,
		] );

		$response = curl_exec( $ch );
		$error    = (string) curl_error( $ch );
		curl_close( $ch );

		return [ false === $response ? null : $response, $error ];
	}
}
