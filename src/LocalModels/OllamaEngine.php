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
 * `reconcile` (symlinking local models into the external tree, blob-dedup aware)
 * still lives in bin/ollamodels; it is ported here in a later phase, so this
 * class does not reimplement it half-way in the meantime.
 */
final class OllamaEngine extends AbstractStoreEngine {

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
	 * @return string[] models actually stopped
	 */
	public function releaseHolds(): array {
		$bin = getenv( 'AIMODELS_OLLAMA_BIN' ) ?: 'ollama';

		exec( escapeshellarg( $bin ) . ' ps 2>/dev/null', $lines, $code );
		if ( 0 !== $code ) {
			return [];
		}

		$released = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, 'NAME' ) ) {
				continue;
			}

			$model = (string) ( preg_split( '/\s+/', $line )[0] ?? '' );
			if ( '' === $model ) {
				continue;
			}

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
	 * Which model tags each store holds, read from the manifests on disk.
	 *
	 * Deliberately not /api/tags: the API only ever describes the store Ollama is
	 * pointed at right now, so it cannot answer "what is on the drive I ejected".
	 * Sizes are left out — they need blob arithmetic, and `ollama-why` already
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
