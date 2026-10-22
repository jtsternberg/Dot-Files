<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'local-review',
	description: 'Review a diff with two local Ollama models: a fast primary that re-checks itself until confident, then a reasoning advisor.',
)]
final class LocalReviewCommand {

	/** @var callable(string[]):array{0:string, 1:int} */
	private $git;

	/** @var callable():string */
	private $readStdin;

	public function __construct(
		private readonly Helpers $cli,
		private ?LocalReview $reviewer = null,
		?callable $git = null,
		?callable $readStdin = null,
	) {
		$this->git       = $git ?: [ self::class, 'runGit' ];
		$this->readStdin = $readStdin ?: static fn(): string => (string) stream_get_contents( STDIN );
	}

	#[Command(
		description: 'Review uncommitted changes against HEAD, a branch since --base, a diff file, or --stdin. Prints each model\'s findings under its own heading; merging them is left to the caller.',
		default: true,
	)]
	public function run(
		#[Argument( description: 'Diff file to review; omit it to review git changes.', completion: 'files' )]
		?string $diff = null,
		#[Option( description: 'Review this branch since it forked from <ref> (git diff <ref>...HEAD)', valueName: 'ref' )]
		?string $base = null,
		#[Option( description: 'Read the diff from stdin' )]
		bool $stdin = false,
		#[Option( description: 'Primary model (default: REVIEW_MODEL_SD / REVIEW_MODEL_LOCAL from the shared config, per store)', valueName: 'model' )]
		?string $primary = null,
		#[Option( description: 'Advisor model, or none (default: REVIEW_ADVISOR_MODEL_SD / REVIEW_ADVISOR_MODEL_LOCAL; none configured skips it)', valueName: 'model' )]
		?string $advisor = null,
		#[Option( description: 'Print the result as JSON (primary and advisor: model, review, duration_ms, error)' )]
		bool $json = false
	): int {
		[ $input, $inputError ] = $this->read( $diff, $base, $stdin );
		if ( null !== $inputError ) {
			return $this->fail( $json, $inputError );
		}

		$result = $this->reviewer()->review( (string) $input, $primary, $advisor, function ( string $role, string $model ): void {
			$this->cli->msgStderr( 'primary' === $role
				? "Reviewing with {$model}, re-checking until it is confident..."
				: "Reviewing with {$model} (a reasoning model; this takes minutes)..." );
		} );

		if ( null !== $result['error'] ) {
			return $this->fail( $json, (string) $result['error'] );
		}

		$succeeded = null !== ( $result['primary']['review'] ?? null ) || null !== ( $result['advisor']['review'] ?? null );

		if ( $json ) {
			$this->cli->output( (string) json_encode( [
				'primary' => $this->jsonSection( $result['primary'] ),
				'advisor' => null === $result['advisor'] ? null : $this->jsonSection( $result['advisor'] ),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return $succeeded ? 0 : 1;
		}

		$this->cli->output( $this->section( 'Primary', $result['primary'] ) );
		if ( null !== $result['advisor'] ) {
			$this->cli->output( "\n" . $this->section( 'Advisor', $result['advisor'] ) );
		}

		return $succeeded ? 0 : 1;
	}

	/** @return array{0:?string, 1:?string} [diff, error] */
	private function read( ?string $file, ?string $base, bool $stdin ): array {
		if ( $stdin ) {
			return [ ( $this->readStdin )(), null ];
		}

		if ( null !== $file && '' !== $file ) {
			$path = (string) $this->cli->convertPathToAbsolute( $file );

			return is_file( $path ) ? [ (string) file_get_contents( $path ), null ] : [ null, "No such diff file: {$file}" ];
		}

		$range           = null !== $base && '' !== $base ? $base . '...HEAD' : 'HEAD';
		[ $out, $code ] = ( $this->git )( [ 'diff', '--no-color', '--no-ext-diff', $range ] );

		return 0 === $code ? [ $out, null ] : [ null, 'git diff failed: ' . trim( $out ) ];
	}

	private function section( string $label, array $review ): string {
		$meta = [];
		if ( isset( $review['rounds'] ) ) {
			$meta[] = $review['rounds'] . ( 1 === $review['rounds'] ? ' round' : ' rounds' );
			$meta[] = $review['confident'] ? 'confident' : 'not confident';
		}
		$meta[] = (int) round( $review['durationMs'] / 1000 ) . 's';

		$body = $review['review'] ?? '';
		if ( null !== $review['error'] ) {
			$body = ( '' === $body ? '' : $body . "\n\n" ) . "_Failed: {$review['error']}_";
		}

		return "## {$label} review: {$review['model']} (" . implode( ', ', $meta ) . ")\n\n{$body}\n";
	}

	private function jsonSection( array $review ): array {
		$out = [
			'model'       => $review['model'],
			'review'      => $review['review'],
			'duration_ms' => $review['durationMs'],
			'error'       => $review['error'],
			'error_type'  => $review['errorType'],
		];
		if ( isset( $review['rounds'] ) ) {
			$out['rounds']    = $review['rounds'];
			$out['confident'] = $review['confident'];
		}

		return $out;
	}

	private function fail( bool $json, string $error ): int {
		if ( $json ) {
			$this->cli->output( (string) json_encode( [ 'error' => $error ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return 1;
		}

		$this->cli->errStderr( "Error: {$error}" );

		return 1;
	}

	/** @return array{0:string, 1:int} [stdout and stderr, exit code] */
	public static function runGit( array $args ): array {
		$proc = proc_open( array_merge( [ 'git' ], $args ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) ) {
			return [ 'could not run git', 1 ];
		}
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );

		return [ 0 === $code ? $out : $err . $out, $code ];
	}

	private function reviewer(): LocalReview {
		return $this->reviewer ??= new LocalReview();
	}
}
