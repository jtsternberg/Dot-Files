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
 */
class Ollama {

	const DEFAULT_URL = 'http://localhost:11434/api/chat';

	/** Used when neither a --model flag nor the config file names one. */
	const DEFAULT_MODEL = 'qwen3-coder';

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
	 * Where ~/.ollama-models currently points, or null when it isn't a symlink.
	 */
	public function storagePath( string $home ): ?string {
		$target = ( $this->readlink )( $home . '/.ollama-models' );

		return false === $target ? null : $target;
	}

	/**
	 * Read the shared "which local model to use" config — see the class docblock.
	 *
	 * @param ?string $configDir Config root to read from; defaults to the XDG one.
	 *
	 * @return array<string,string>
	 */
	public function config( ?string $configDir = null ): array {
		$dir  = $configDir ?: ( getenv( 'XDG_CONFIG_HOME' ) ?: ( ( getenv( 'HOME' ) ?: '' ) . '/.config' ) );
		$file = $dir . '/auto-commit-ollama/config';

		if ( ! is_file( $file ) ) {
			return [];
		}

		// Shell-style KEY=value, so a dotenv parser: parse_ini_file() rejected the
		// whole file over "(" in a # comment, silently defaulting every key.
		try {
			return \Dotenv\Dotenv::parse( (string) file_get_contents( $file ) );
		} catch ( \Dotenv\Exception\ExceptionInterface $e ) {
			fwrite( STDERR, "Ignoring unparseable {$file}: {$e->getMessage()}\n" );

			return [];
		}
	}

	/**
	 * `errorType` separates a failure to reach Ollama at all ('transport' — the
	 * server is probably not running) from one Ollama itself reported ('api' —
	 * e.g. an unknown model), which callers word very differently.
	 *
	 * @return array{content:?string, error:?string, errorType:?string}
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
			return [ 'content' => null, 'error' => $error, 'errorType' => 'transport' ];
		}

		$data = json_decode( (string) $body, true );
		if ( ! empty( $data['error'] ) ) {
			return [
				'content'   => null,
				'error'     => (string) $data['error'],
				'errorType' => 'api',
			];
		}

		return [
			'content'   => $data['message']['content'] ?? null,
			'error'     => null,
			'errorType' => null,
		];
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
