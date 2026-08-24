<?php

namespace JT\LocalModels;

/**
 * The engines the watcher and the CLI both drive.
 *
 * One list, so a new engine is registered once and every view — status, where,
 * the watcher loop — picks it up together.
 */
final class EngineRegistry {

	/** @var StoreEngine[]|null */
	private ?array $engines = null;

	public function __construct(
		private readonly ?string $home = null,
		private readonly string $volumesRoot = '/Volumes'
	) {
	}

	/**
	 * @return StoreEngine[]
	 */
	public function engines(): array {
		if ( null === $this->engines ) {
			$this->engines = [
				new OllamaEngine( $this->home, $this->volumesRoot ),
				new MacWhisperEngine( $this->home, $this->volumesRoot ),
			];
		}

		return $this->engines;
	}

	public function engine( string $name ): ?StoreEngine {
		foreach ( $this->engines() as $engine ) {
			if ( $engine->name() === $name ) {
				return $engine;
			}
		}

		return null;
	}
}
