<?php

namespace JT\LocalModels;

/**
 * Ollama's model store.
 *
 * Ollama reads OLLAMA_MODELS, so the app-facing path is an ordinary dotfile
 * symlink and the only engine-specific step is re-asserting that env var in
 * launchd after a flip — Ollama.app is launchd-spawned and inherits it on next
 * launch, which is also why a flip under a running Ollama.app needs a warning.
 *
 * `reconcile` symlinks local models into the external tree (blob-dedup aware),
 * so the AI-LAB store sees every model. Unlike MacWhisper, Ollama follows
 * symlinked manifests and blobs, and `ollama rm` on the external store unlinks
 * the symlinks without touching the local originals (verified on 0.35.0 against
 * a throwaway server and store; dotfiles-2zh).
 */
final class OllamaEngine extends AbstractStoreEngine {

	private ?ModelNotes $notes = null;

	public function name(): string {
		return 'ollama';
	}

	public function label(): string {
		return 'Ollama';
	}

	public function symlinkPath(): string {
		return $this->home . '/.ollama-models';
	}

	public function storePath( string $location ): string {
		return self::EXTERNAL === $location
			? $this->volumesRoot . '/' . $this->volumeName() . '/ollama/models'
			: $this->home . '/.ollama-local-models';
	}

	/**
	 * @return string[]
	 */
	protected function postApply( string $location ): array {
		$warnings = [];

		if ( 'Darwin' === PHP_OS_FAMILY ) {
			$launchctl = getenv( 'AIMODELS_LAUNCHCTL_BIN' ) ?: 'launchctl';
			exec(
				escapeshellarg( $launchctl ) . ' setenv OLLAMA_MODELS '
					. escapeshellarg( $this->symlinkPath() ) . ' 2>&1',
				$out,
				$code
			);

			if ( 0 !== $code ) {
				$warnings[] = 'launchctl setenv OLLAMA_MODELS failed: ' . implode( ' ', $out );
			}
		}

		if ( $this->appIsRunning( 'Ollama' ) ) {
			$warnings[] = 'Ollama.app is running — quit and relaunch it for OLLAMA_MODELS to take effect.';
		}

		return $warnings;
	}

	/**
	 * Unload every loaded model so nothing holds a blob on the drive open.
	 *
	 * The eject blocker in practice is not Ollama.app but its grandchild: the app
	 * spawns `ollama serve`, which spawns a `llama-server` runner per loaded model,
	 * and that runner keeps a file descriptor on the model blob. `ollama stop`
	 * unloads the model and tears down the runner, freeing the FD while leaving the
	 * app running — so nothing needs quitting or reopening.
	 *
	 * (Quitting the app was tried and does not work: Ollama's menubar app refuses
	 * AppleScript quit with -128 "User canceled".)
	 *
	 * Which models are loaded comes from OllamaRunners, the one reader of
	 * `ollama ps` — the watchdog reads the same rows to size the kernel GPU zone,
	 * and a parsing fix has to serve both or the two drift apart.
	 *
	 * @return string[] models actually stopped
	 */
	public function releaseHolds(): array {
		$bin = getenv( 'AIMODELS_OLLAMA_BIN' ) ?: 'ollama';

		$released = [];
		foreach ( ( new OllamaRunners() )->names() as $model ) {
			exec( escapeshellarg( $bin ) . ' stop ' . escapeshellarg( $model ) . ' 2>&1', $out, $stopCode );
			// A refused stop is not a release. Reporting it as one is what let the
			// previous approach claim success it never achieved.
			if ( 0 === $stopCode ) {
				$released[] = $model;
			}
		}

		return $released;
	}

	/**
	 * Symlink every local model the external store lacks into it: the manifest,
	 * then each blob it names. Strictly local -> external — the reverse would
	 * dangle when the drive ejects — and strictly additive: a manifest or blob
	 * the external store holds as a real file (its own copy of the model, or a
	 * layer shared with another model) is never replaced.
	 *
	 * A linked model now lives in both stores, so a note that says "local" is
	 * promoted to "both" through ModelNotes.
	 *
	 * @param array<string, mixed> $options dry-run
	 */
	public function reconcile( array $options = [] ): ApplyResult {
		$dryRun   = ! empty( $options['dry-run'] );
		$local    = $this->storePath( self::LOCAL );
		$external = $this->storePath( self::EXTERNAL );

		if ( ! is_dir( $external ) || ! is_readable( $external ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'external store not available (mount AI-LAB and retry): ' . $external );
		}

		$library = $local . '/manifests/registry.ollama.ai/library';
		if ( ! is_dir( $library ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'no local manifests found at ' . $library );
		}

		[ $plan, $warnings ] = $this->reconcilePlan( $local, $external, $library );

		if ( empty( $plan ) ) {
			return new ApplyResult(
				ApplyResult::NOOP,
				'external store already sees every local model.',
				self::EXTERNAL,
				$external,
				$warnings
			);
		}

		$total = array_sum( array_map( static fn( array $links ): int => count( $links ), $plan ) );

		if ( $dryRun ) {
			$details = [];
			foreach ( $plan as $tag => $links ) {
				foreach ( $links as $link ) {
					$details[] = "would link {$tag}: {$link['to']} -> {$link['from']}";
				}
			}

			return new ApplyResult( ApplyResult::WOULD_APPLY, $total . ' symlink(s) to create', self::EXTERNAL, $external, $warnings, $details );
		}

		$details = [];
		$created = 0;
		$failed  = 0;
		foreach ( $plan as $tag => $links ) {
			$linked = 0;
			foreach ( $links as $link ) {
				$dir = dirname( $link['to'] );
				if ( ( is_dir( $dir ) || @mkdir( $dir, 0755, true ) || is_dir( $dir ) ) && @symlink( $link['from'], $link['to'] ) ) {
					$linked++;
					continue;
				}
				$details[] = "FAILED {$tag}: could not link {$link['to']}";
				$failed++;
			}
			$created   += $linked;
			$details[] = "{$tag}: {$linked} symlink(s) created";

			if ( $this->notes()->promoteLocalToBoth( $this->name() . ':' . $tag ) ) {
				$details[] = "{$tag}: note location promoted local -> both";
			}
		}

		return new ApplyResult(
			$failed > 0 ? ApplyResult::FAILED : ApplyResult::APPLIED,
			$created . ' symlink(s) created' . ( $failed > 0 ? ", {$failed} FAILED" : '' ),
			self::EXTERNAL,
			$external,
			$warnings,
			$details
		);
	}

	/**
	 * Per model tag, the links to create. Unreadable manifests and blobs missing
	 * locally are warnings: one broken model must not block the rest.
	 *
	 * @return array{0: array<string, array<int, array{from: string, to: string}>>, 1: string[]}
	 */
	private function reconcilePlan( string $local, string $external, string $library ): array {
		$plan     = [];
		$warnings = [];
		$files    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $library, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || '.DS_Store' === $file->getFilename() ) {
				continue;
			}

			$manifest = $file->getPathname();
			$tag      = basename( dirname( $manifest ) ) . ':' . $file->getFilename();
			$target   = $external . '/' . substr( $manifest, strlen( $local ) + 1 );
			$links    = [];

			if ( ! is_link( $target ) ) {
				if ( file_exists( $target ) ) {
					continue; // the external store's own copy of this model
				}
				$links[] = [ 'from' => $manifest, 'to' => $target ];
			}

			$json = json_decode( (string) file_get_contents( $manifest ), true );
			if ( ! is_array( $json ) ) {
				$warnings[] = "skipped {$tag}: could not parse manifest JSON ({$manifest})";
				continue;
			}

			$digests = array_filter( array_merge(
				[ $json['config']['digest'] ?? null ],
				array_column( $json['layers'] ?? [], 'digest' )
			) );
			foreach ( array_unique( $digests ) as $digest ) {
				$blob = 'blobs/' . str_replace( ':', '-', (string) $digest );
				if ( is_link( $external . '/' . $blob ) || file_exists( $external . '/' . $blob ) ) {
					continue;
				}
				if ( ! file_exists( $local . '/' . $blob ) ) {
					$warnings[] = "skipped a blob of {$tag}: missing locally (" . basename( $blob ) . ')';
					continue;
				}
				$links[] = [ 'from' => $local . '/' . $blob, 'to' => $external . '/' . $blob ];
			}

			if ( ! empty( $links ) ) {
				$plan[ $tag ] = $links;
			}
		}

		ksort( $plan );

		return [ $plan, $warnings ];
	}

	private function notes(): ModelNotes {
		return $this->notes ??= new ModelNotes( ModelNotes::defaultPath( $this->home ) );
	}

	/**
	 * Which model tags each store holds, read from the manifests on disk.
	 *
	 * Deliberately not /api/tags: the API only ever describes the store Ollama is
	 * pointed at right now, so it cannot answer "what is on the drive I ejected".
	 * Sizes are left out — they need blob arithmetic, and `aimodels why` already
	 * carries measured size and speed notes per model.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function residency(): array {
		$active = $this->currentLocation();
		$rows   = [];

		foreach ( [ self::LOCAL, self::EXTERNAL ] as $location ) {
			foreach ( $this->tagsIn( $this->storePath( $location ) ) as $tag ) {
				$rows[ $tag ] ??= [
					'id'        => $this->name() . ':' . $tag,
					'engine'    => $this->name(),
					'name'      => $tag,
					'framework' => 'ollama',
					'kind'      => 'llm',
					'path'      => 'manifests/registry.ollama.ai/library/' . str_replace( ':', '/', $tag ),
					'sizeMb'    => null,
					'local'     => false,
					'external'  => false,
					'available' => false,
				];

				$rows[ $tag ][ $location ] = true;
				if ( $location === $active ) {
					$rows[ $tag ]['available'] = true;
				}
			}
		}

		return array_values( $rows );
	}

	/**
	 * @return string[] model:tag pairs
	 */
	private function tagsIn( string $store ): array {
		$library = $store . '/manifests/registry.ollama.ai/library';
		if ( ! is_dir( $library ) ) {
			return [];
		}

		$tags = [];
		foreach ( scandir( $library ) ?: [] as $model ) {
			if ( '.' === $model || '..' === $model || ! is_dir( $library . '/' . $model ) ) {
				continue;
			}

			foreach ( scandir( $library . '/' . $model ) ?: [] as $tag ) {
				if ( '.' === $tag || '..' === $tag || '.DS_Store' === $tag ) {
					continue;
				}

				if ( is_file( $library . '/' . $model . '/' . $tag ) ) {
					$tags[] = $model . ':' . $tag;
				}
			}
		}

		return $tags;
	}
}
