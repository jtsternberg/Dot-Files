<?php

namespace JT\CLI\Commands;

use JT\CLI\Helpers;
use JT\Paths;

/**
 * Abstract base class for WordPress site REST API commands.
 *
 * Provides shared functionality for connecting to WordPress sites via REST API,
 * including environment loading, credential management, and HTTP request execution.
 */
abstract class SiteCommand {
	protected $cli;
	protected $restUrl;
	protected $username;
	protected $password;

	public function __construct( Helpers $cli ) {
		$this->cli = $cli;
		$this->restUrl = $cli->getFlag( 'restUrl' );

		// Support --url shorthand: domain name -> full REST URL
		if ( ! $this->restUrl ) {
			$url = $cli->getFlag( 'url' );
			if ( $url ) {
				$url = preg_replace( '#^https?://#', '', $url );
				$url = rtrim( $url, '/' );
				$this->restUrl = "https://{$url}/wp-json/wp/v2/";
			}
		}
	}

	/**
	 * Define required environment variables. Override in child classes.
	 *
	 * @return array
	 */
	protected function getRequiredEnvVars(): array {
		return [
			'JTS_SITE_REST_URL',
			'JTS_SITE_USERNAME',
			'JTS_SITE_PASSWORD',
		];
	}

	/**
	 * Directory holding the .env file: the repo root.
	 *
	 * Resolved through JT\Paths rather than dirname()-counting from __DIR__, so
	 * moving this class deeper into src/ can't break it again (it did once —
	 * see the note on JT\Paths). Overridable so tests can point it elsewhere.
	 *
	 * @return string
	 */
	protected function repoRoot(): string {
		return Paths::root();
	}

	/**
	 * Absolute path to the .env file this command reads.
	 *
	 * @return string
	 */
	protected function envFilePath(): string {
		return $this->repoRoot() . '/.env';
	}

	/**
	 * Load environment variables from .env file.
	 */
	protected function loadEnv(): void {
		$envFile = $this->envFilePath();
		if ( ! file_exists( $envFile ) ) {
			throw new \Exception( "Error: .env file not found at {$envFile}. Please create it from .env.example" );
		}

		$dotenv = \Dotenv\Dotenv::createImmutable( $this->repoRoot() );
		$dotenv->load();

		$required = $this->getRequiredEnvVars();
		if ( ! empty( $required ) ) {
			$dotenv->required( $required );
		}
	}

	/**
	 * Get the base REST URL: the --url/--restUrl flag value takes precedence,
	 * else the JTS_SITE_REST_URL environment variable.
	 *
	 * Subclasses override this to append a resource segment (e.g. /posts,
	 * /pages). Routing the flag-derived URL through here too ensures that
	 * resource segment is applied regardless of whether the base came from
	 * the flag or the environment.
	 *
	 * @return string|null
	 */
	protected function getRestUrl(): ?string {
		return $this->restUrl ?: ( $_ENV['JTS_SITE_REST_URL'] ?? null );
	}

	/**
	 * Set credentials from environment variables.
	 */
	protected function setCredentials(): void {
		$this->username = $_ENV['JTS_SITE_USERNAME'] ?? '';
		$this->password = $_ENV['JTS_SITE_PASSWORD'] ?? '';
	}

	/**
	 * Build Basic Auth header value.
	 *
	 * @return string
	 */
	protected function buildBasicAuthHeader(): string {
		return base64_encode( "{$this->username}:{$this->password}" );
	}

	/**
	 * Execute a REST API request.
	 *
	 * @param string $url Full URL to request
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 * @param array|null $payload Request body for POST/PUT (will be JSON encoded)
	 * @return array Decoded JSON response
	 * @throws \Exception On request failure
	 */
	protected function executeRequest( string $url, string $method = 'GET', ?array $payload = null ): array {
		if ( empty( $this->username ) || empty( $this->password ) ) {
			throw new \Exception( "Error: Username and password must be set before making requests" );
		}

		$credentials = $this->buildBasicAuthHeader();

		$ch = curl_init( $url );
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				"Authorization: Basic $credentials",
				'Content-Type: application/json',
			],
		];

		if ( $method === 'POST' ) {
			$options[CURLOPT_POST] = true;
			if ( $payload !== null ) {
				$options[CURLOPT_POSTFIELDS] = json_encode( $payload, JSON_UNESCAPED_SLASHES );
			}
		} elseif ( $method !== 'GET' ) {
			$options[CURLOPT_CUSTOMREQUEST] = $method;
			if ( $payload !== null ) {
				$options[CURLOPT_POSTFIELDS] = json_encode( $payload, JSON_UNESCAPED_SLASHES );
			}
		}

		curl_setopt_array( $ch, $options );

		$response = curl_exec( $ch );
		$httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		// Accept any 2XX status code as success
		if ( $httpCode < 200 || $httpCode >= 300 ) {
			throw new \Exception( "Error: Request failed. HTTP $httpCode\nResponse: $response" );
		}

		return json_decode( $response, true ) ?? [];
	}

	/**
	 * Get the resolved REST URL (from flag or environment).
	 *
	 * @return string
	 * @throws \Exception If no URL is available
	 */
	protected function resolveRestUrl(): string {
		// getRestUrl() already prefers the flag-derived URL over the env value,
		// and (in subclasses) appends the resource segment to whichever wins.
		$url = $this->getRestUrl();

		if ( empty( $url ) ) {
			throw new \Exception( "Error: REST URL must be provided via --restUrl flag or JTS_SITE_REST_URL environment variable" );
		}

		return $url;
	}

	/**
	 * Main execution method. Override in child classes.
	 */
	abstract public function run(): void;
}
