<?php

namespace JT\LocalModels;

/**
 * MacWhisper's model store.
 *
 * MacWhisper hard-codes its models path with no env override, which is exactly
 * why the whole-dir symlink is the mechanism: the app follows a symlinked models
 * ROOT fine, but it will NOT list a model whose individual bundle is a symlink.
 * That single fact drives two rules here:
 *
 *   1. A store is a full replica of the models tree, all real directories.
 *   2. `reconcile` must COPY, never symlink (unlike Ollama's).
 *
 * It also means the small model and the support set exist as duplicate real
 * copies in both stores. That duplication is the price of the app's behaviour,
 * not an oversight.
 *
 * MacWhisper writes into the models root at runtime (downloads land there,
 * per-bundle .cache/ dirs), so operating on the local store strands new
 * downloads there until they are reconciled back to the external superset.
 */
final class MacWhisperEngine extends AbstractStoreEngine {

	public function name(): string {
		return 'macwhisper';
	}

	public function label(): string {
		return 'MacWhisper';
	}

	public function symlinkPath(): string {
		return $this->home . '/Library/Application Support/MacWhisper/models';
	}

	public function storePath( string $location ): string {
		return self::EXTERNAL === $location
			? $this->volumesRoot . '/' . $this->volumeName() . '/macwhisper/models'
			: $this->home . '/.macwhisper-local-models';
	}

	/**
	 * @return string[]
	 */
	protected function postApply( string $location ): array {
		if ( $this->appIsRunning( 'MacWhisper' ) ) {
			return [
				'MacWhisper is running — it caches its model list at launch, so relaunch it to see'
					. ' the ' . $location . ' store.',
			];
		}

		return [];
	}

	/**
	 * @return string[]
	 */
	public function advisories( string $location ): array {
		if ( self::LOCAL !== $location ) {
			return [];
		}

		return [
			'AI-LAB not mounted — new MacWhisper model downloads will land in the local store'
				. ' and need `aimodels whisper reconcile` after remount.',
		];
	}
}
