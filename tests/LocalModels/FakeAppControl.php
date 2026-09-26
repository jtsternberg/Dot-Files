<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\AppControl;

/**
 * Records what would have happened to the real app. No test may quit MacWhisper.
 */
final class FakeAppControl extends AppControl {

	/** @var string[] */
	public array $calls = [];

	/** @param string[] $mediaFiles */
	public function __construct(
		private bool $running = false,
		private ?float $cpu = 0.0,
		private array $mediaFiles = [],
		private bool $quitSucceeds = true,
		private int $reopenFailures = 0
	) {
	}

	public function isRunning( string $app ): bool {
		return $this->running;
	}

	public function cpuPercent( string $app ): ?float {
		return $this->cpu;
	}

	public function openMediaFiles( string $app ): array {
		return $this->mediaFiles;
	}

	public function quit( string $app ): bool {
		$this->calls[] = 'quit:' . $app;
		if ( ! $this->quitSucceeds ) {
			return false;
		}
		$this->running = false;

		return true;
	}

	public function waitForExit( string $app, int $tries = 10, int $sleepMicroseconds = 300000 ): bool {
		$this->calls[] = 'wait:' . $app;

		return true;
	}

	public function reopen( string $app ): bool {
		$this->calls[] = 'reopen:' . $app;
		if ( $this->reopenFailures > 0 ) {
			$this->reopenFailures--;

			return false;
		}
		$this->running = true;

		return true;
	}
}
