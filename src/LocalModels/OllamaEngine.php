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
}
