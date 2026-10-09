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
 * drops that model from `ollama list` (verified on 0.40.0 the same way). A
 * `manifests-v2` entry is already such a blob symlink, so it is mirrored as the
 * same relative link (verified on 0.40.1 the same way).
 */
final class OllamaEngine extends AbstractStoreEngine {

	private const LEGACY_LIBRARY = 'manifests/registry.ollama.ai/library';

	/**
	 * Ollama 0.40's manifest layout: each tag is a relative symlink to the blob
	 * holding its manifest (or manifest list). Pulls write both layouts, but
	 * `ollama create` writes only this one, so reading just the legacy tree hides
	 * every locally created model.
	 */
	private const V2_LIBRARY = 'manifests-v2/ollama.com/library';

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
		if ( self::EXTERNAL === $location && $this->hasManifests( $this->storePath( self::LOCAL ) ) ) {
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

		if ( ! $this->hasManifests( $local ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'no local manifests found under ' . $local );
		}

		[ $plan, $warnings ] = $this->reconcilePlan( $local, $external );

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
	private function reconcilePlan( string $local, string $external ): array {
		$plan     = [];
		$warnings = [];
		// A pulled model is in both layouts and names the same blobs twice; a
		// second symlink() onto an already-planned path would fail the run.
		$planned  = [];
		$add      = static function ( string $tag, array $link ) use ( &$plan, &$planned ): void {
			if ( isset( $planned[ $link['to'] ] ) ) {
				return;
			}
			$planned[ $link['to'] ] = true;
			$plan[ $tag ][]         = $link;
		};

		foreach ( $this->manifestFiles( $local . '/' . self::LEGACY_LIBRARY ) as $manifest ) {
			$tag    = basename( dirname( $manifest ) ) . ':' . basename( $manifest );
			$target = $external . '/' . substr( $manifest, strlen( $local ) + 1 );

			if ( is_link( $target ) ) {
				if ( readlink( $target ) === $manifest ) {
					$add( $tag, [ 'mode' => 'copy', 'from' => $manifest, 'to' => $target ] );
				}
			} elseif ( file_exists( $target ) ) {
				continue; // the external store's own copy of this model
			} else {
				$add( $tag, [ 'mode' => 'copy', 'from' => $manifest, 'to' => $target ] );
			}

			$json = json_decode( (string) file_get_contents( $manifest ), true );
			if ( ! is_array( $json ) ) {
				$warnings[] = "skipped {$tag}: could not parse manifest JSON ({$manifest})";
				continue;
			}

			foreach ( $this->blobLinks( $local, $external, self::digestsOf( $json ), $tag, $warnings ) as $link ) {
				$add( $tag, $link );
			}
		}

		foreach ( $this->manifestFiles( $local . '/' . self::V2_LIBRARY ) as $entry ) {
			$tag    = basename( dirname( $entry ) ) . ':' . basename( $entry );
			$target = $external . '/' . substr( $entry, strlen( $local ) + 1 );
			$blob   = is_link( $entry ) ? basename( (string) readlink( $entry ) ) : '';

			if ( is_link( $target ) || file_exists( $target ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^sha256-[0-9a-f]{64}$/', $blob ) ) {
				$warnings[] = "skipped {$tag}: v2 manifest is not a symlink to a sha256 blob ({$entry})";
				continue;
			}

			// Same relative target, so it resolves into the external blobs dir.
			$add( $tag, [ 'mode' => 'link', 'from' => (string) readlink( $entry ), 'to' => $target ] );
			foreach ( $this->blobLinks( $local, $external, $this->manifestClosure( $local, $blob ), $tag, $warnings ) as $link ) {
				$add( $tag, $link );
			}
		}

		ksort( $plan );

		return [ $plan, $warnings ];
	}

	/**
	 * Every digest a v2 entry needs: its manifest blob, and when that is a
	 * manifest list, each per-runner child manifest and their config and layers.
	 *
	 * @return string[]
	 */
	private function manifestClosure( string $local, string $blob ): array {
		$digests = [];
		$queue   = [ str_replace( 'sha256-', 'sha256:', $blob ) ];

		while ( null !== ( $digest = array_shift( $queue ) ) ) {
			if ( isset( $digests[ $digest ] ) ) {
				continue;
			}
			$digests[ $digest ] = true;

			$json = json_decode( (string) @file_get_contents( $local . '/blobs/' . str_replace( ':', '-', $digest ) ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}
			if ( isset( $json['manifests'] ) ) {
				// A pull fetches only this machine's runner; the other children are
				// absent by design, so skipping them is not a missing-blob warning.
				foreach ( array_filter( array_column( $json['manifests'], 'digest' ) ) as $child ) {
					if ( file_exists( $local . '/blobs/' . str_replace( ':', '-', $child ) ) ) {
						$queue[] = $child;
					}
				}
				continue;
			}
			foreach ( self::digestsOf( $json ) as $layer ) {
				$digests[ $layer ] = true;
			}
		}

		return array_keys( $digests );
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return string[]
	 */
	private static function digestsOf( array $manifest ): array {
		return array_values( array_unique( array_filter( array_merge(
			[ $manifest['config']['digest'] ?? null ],
			array_column( $manifest['layers'] ?? [], 'digest' )
		) ) ) );
	}

	/**
	 * Links for each blob the external store lacks. A blob it holds in any form
	 * is left alone; one missing locally is a warning.
	 *
	 * @param string[] $digests
	 * @param string[] $warnings
	 * @return array<int, array{mode: string, from: string, to: string}>
	 */
	private function blobLinks( string $local, string $external, array $digests, string $tag, array &$warnings ): array {
		$links = [];
		foreach ( $digests as $digest ) {
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

		return $links;
	}

	/**
	 * Tag files under one library root, as `<root>/<model>/<tag>`, runner
	 * children and Finder litter excluded. Follows symlinks, so a dangling v2
	 * entry (blob gone) is not a model either.
	 *
	 * @return string[]
	 */
	private function manifestFiles( string $library ): array {
		if ( ! is_dir( $library ) ) {
			return [];
		}

		$files = [];
		foreach ( scandir( $library ) ?: [] as $model ) {
			if ( '.' === $model || '..' === $model || ! is_dir( $library . '/' . $model ) ) {
				continue;
			}

			foreach ( scandir( $library . '/' . $model ) ?: [] as $tag ) {
				if ( '.' === $tag || '..' === $tag || '.DS_Store' === $tag || self::isRunnerChild( $tag ) ) {
					continue;
				}

				if ( is_file( $library . '/' . $model . '/' . $tag ) ) {
					$files[] = $library . '/' . $model . '/' . $tag;
				}
			}
		}

		sort( $files );

		return $files;
	}

	private function hasManifests( string $store ): bool {
		return is_dir( $store . '/' . self::LEGACY_LIBRARY ) || is_dir( $store . '/' . self::V2_LIBRARY );
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
			foreach ( $this->tagsIn( $this->storePath( $location ) ) as $tag => $path ) {
				$rows[ $tag ] ??= [
					'id'        => $this->name() . ':' . $tag,
					'engine'    => $this->name(),
					'name'      => $tag,
					'framework' => 'ollama',
					'kind'      => 'llm',
					'path'      => $path,
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
	 * Legacy layout first, so a pulled model present in both reports its
	 * legacy path.
	 *
	 * @return array<string, string> model:tag => path relative to the store
	 */
	private function tagsIn( string $store ): array {
		$tags = [];
		foreach ( [ self::LEGACY_LIBRARY, self::V2_LIBRARY ] as $library ) {
			foreach ( $this->manifestFiles( $store . '/' . $library ) as $file ) {
				$tags[ basename( dirname( $file ) ) . ':' . basename( $file ) ] ??= substr( $file, strlen( $store ) + 1 );
			}
		}

		return $tags;
	}
}
