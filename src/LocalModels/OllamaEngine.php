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
 * `reconcile` mirrors local models into the external tree (blob-dedup aware),
 * so the AI-LAB store sees every model: each manifest as a copy, each blob as a
 * symlink. Ollama follows symlinked blobs, and `ollama rm` on the external store
 * unlinks them without touching the local originals (verified on 0.35.0 against
 * a throwaway server and store; dotfiles-2zh). Manifests are copied because
 * Ollama 0.40 rejects a manifest symlink unless it points at a sha256 blob and
 * drops that model from `ollama list` (verified on 0.40.0 the same way).
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

		// Arriving on AI-LAB (the watcher's mount edge, or a manual flip) is when a
		// model pulled during an ejected spell should become visible there. Only
		// tiny manifests are copied and blobs symlinked, which is why this is automatic for Ollama and not
		// for MacWhisper, whose reconcile copies gigabytes.
		if ( self::EXTERNAL === $location && is_dir( $this->storePath( self::LOCAL ) . '/manifests/registry.ollama.ai/library' ) ) {
			$reconciled = $this->reconcile();
			if ( ApplyResult::NOOP !== $reconciled->status ) {
				$warnings[] = ( $reconciled->ok() ? 'reconciled local models into AI-LAB: ' : 'reconcile failed: ' ) . $reconciled->message;
			}
			$warnings = array_merge( $warnings, $reconciled->warnings );
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
	 * Mirror every local model the external store lacks into it: copy the
	 * manifest, then symlink each blob it names. Strictly local -> external — the
	 * reverse would dangle when the drive ejects — and strictly additive: a
	 * manifest or blob the external store holds as a real file (its own copy of
	 * the model, or a layer shared with another model) is never replaced. The
	 * one thing replaced is a manifest symlink pointing at the matching local
	 * manifest, the form earlier reconciles wrote and Ollama 0.40 refuses.
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
					$details[] = 'copy' === $link['mode']
						? "would copy {$tag}: {$link['from']} -> {$link['to']}"
						: "would link {$tag}: {$link['to']} -> {$link['from']}";
				}
			}

			return new ApplyResult( ApplyResult::WOULD_APPLY, $total . ' file(s) to copy or link', self::EXTERNAL, $external, $warnings, $details );
		}

		$details = [];
		$created = 0;
		$failed  = 0;
		foreach ( $plan as $tag => $links ) {
			$linked = 0;
			foreach ( $links as $link ) {
				$dir = dirname( $link['to'] );
				$ok  = ( is_dir( $dir ) || @mkdir( $dir, 0755, true ) || is_dir( $dir ) )
					&& ( 'copy' === $link['mode'] ? $this->copyOver( $link['from'], $link['to'] ) : @symlink( $link['from'], $link['to'] ) );
				if ( $ok ) {
					$linked++;
					continue;
				}
				$details[] = "FAILED {$tag}: could not {$link['mode']} {$link['to']}";
				$failed++;
			}
			$created   += $linked;
			$details[] = "{$tag}: {$linked} file(s) copied or linked";

			if ( $this->notes()->promoteLocalToBoth( $this->name() . ':' . $tag ) ) {
				$details[] = "{$tag}: note location promoted local -> both";
			}
		}

		return new ApplyResult(
			$failed > 0 ? ApplyResult::FAILED : ApplyResult::APPLIED,
			$created . ' file(s) copied or linked' . ( $failed > 0 ? ", {$failed} FAILED" : '' ),
			self::EXTERNAL,
			$external,
			$warnings,
			$details
		);
	}

	/**
	 * Copy via a sibling temp file and rename, so the swap from an old manifest
	 * symlink to a real file is atomic and a failed copy leaves the link intact.
	 */
	private function copyOver( string $from, string $to ): bool {
		$tmp = $to . '.aimodels-tmp';
		if ( ! @copy( $from, $tmp ) ) {
			@unlink( $tmp );
			return false;
		}
		if ( ! @rename( $tmp, $to ) ) {
			@unlink( $tmp );
			return false;
		}

		return true;
	}

	/**
	 * Per model tag, the files to copy or link. Unreadable manifests and blobs missing
	 * locally are warnings: one broken model must not block the rest.
	 *
	 * @return array{0: array<string, array<int, array{mode: string, from: string, to: string}>>, 1: string[]}
	 */
	private function reconcilePlan( string $local, string $external, string $library ): array {
		$plan     = [];
		$warnings = [];
		$files    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $library, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || '.DS_Store' === $file->getFilename() || self::isRunnerChild( $file->getFilename() ) ) {
				continue;
			}

			$manifest = $file->getPathname();
			$tag      = basename( dirname( $manifest ) ) . ':' . $file->getFilename();
			$target   = $external . '/' . substr( $manifest, strlen( $local ) + 1 );
			$links    = [];

			if ( is_link( $target ) ) {
				if ( readlink( $target ) === $manifest ) {
					$links[] = [ 'mode' => 'copy', 'from' => $manifest, 'to' => $target ];
				}
			} elseif ( file_exists( $target ) ) {
				continue; // the external store's own copy of this model
			} else {
				$links[] = [ 'mode' => 'copy', 'from' => $manifest, 'to' => $target ];
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
				$links[] = [ 'mode' => 'link', 'from' => $local . '/' . $blob, 'to' => $external . '/' . $blob ];
			}

			if ( ! empty( $links ) ) {
				$plan[ $tag ] = $links;
			}
		}

		ksort( $plan );

		return [ $plan, $warnings ];
	}

	/**
	 * Ollama 0.40 stores a manifest list's per-runner child at the legacy path
	 * `library/<runner>/<sha256 hex>`. The parent reaches it by blob digest, so
	 * it is not a model: never mirror it (it would surface in `ollama list`) and
	 * never count it in residency.
	 */
	private static function isRunnerChild( string $tag ): bool {
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $tag );
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
				if ( '.' === $tag || '..' === $tag || '.DS_Store' === $tag || self::isRunnerChild( $tag ) ) {
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
