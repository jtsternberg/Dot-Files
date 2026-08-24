<?php

namespace JT\LocalModels;

/**
 * Eject AI-LAB safely, in the order that actually works.
 *
 * Finder and `diskutil eject` report "Resource busy" because at eject time the
 * model symlinks still resolve into /Volumes/AI-LAB, and the watcher only flips
 * them back to local AFTER the eject event fires — by then the eject has already
 * failed. So this releases every engine's reference first, then ejects.
 *
 * When the volume is still busy after that, the holder is a loaded MODEL, not the
 * app. Observed live: Ollama.app -> `ollama serve` -> `llama-server`, the
 * grandchild holding a file descriptor on a model blob. So a blocked eject asks
 * each engine to release its own holds — `ollama stop` for Ollama, nothing for
 * MacWhisper, which holds nothing between transcriptions — and retries once.
 *
 * There is deliberately NO app-quit path. Quitting Ollama was tried and its
 * menubar app refuses AppleScript quit outright (-128 "User canceled"), and
 * signalling an app that is mid-write to a model file is how those get truncated.
 * Unloading the model frees the descriptor and leaves the app running, so nothing
 * needs closing or reopening in the first place.
 *
 * A holder no engine claims is reported, never acted on. It never forces an
 * unmount unless explicitly asked.
 */
final class Ejector {

	public function __construct(
		private readonly EngineRegistry $registry,
		private readonly string $volumesRoot = '/Volumes',
		private readonly string $volumeName = 'AI-LAB'
	) {
	}

	public function volumePath(): string {
		return $this->volumesRoot . '/' . $this->volumeName;
	}

	/**
	 * @param array<string, mixed> $options force|dry-run|no-release
	 *
	 * @return array<string, mixed>
	 */
	public function eject( array $options = [] ): array {
		$force     = ! empty( $options['force'] );
		$dryRun    = ! empty( $options['dry-run'] );
		$noRelease = ! empty( $options['no-release'] );

		$report = [
			'volume'      => $this->volumePath(),
			'ejected'     => false,
			'alreadyGone' => false,
			'message'     => '',
			'engines'     => [],
			'holders'     => [],
			'released'    => [],
		];

		if ( ! ( new Drive( $this->volumesRoot ) )->isMounted( $this->volumeName ) ) {
			$report['ejected']     = true;
			$report['alreadyGone'] = true;
			$report['message']     = $this->volumeName . ' is not mounted — nothing to do.';

			return $report;
		}

		// 1. Release every engine's reference into the volume.
		$unreleased = [];
		foreach ( $this->registry->engines() as $engine ) {
			$pre = $engine->preflight();
			if ( ! $pre->manageable ) {
				// An engine that was never on the drive holds nothing; one whose
				// structure is broken cannot be released, and must block the eject.
				if ( AbstractStoreEngine::EXTERNAL === $engine->currentLocation() ) {
					$unreleased[ $engine->name() ] = $pre->reason;
				}
				$report['engines'][ $engine->name() ] = new ApplyResult( ApplyResult::SKIPPED, $pre->reason );
				continue;
			}

			$result = $engine->apply( AbstractStoreEngine::LOCAL, $dryRun );
			$report['engines'][ $engine->name() ] = $result;

			if ( ! $result->ok() ) {
				$unreleased[ $engine->name() ] = $result->message;
			}
		}

		if ( ! empty( $unreleased ) ) {
			$report['message'] = 'not ejecting: '
				. implode( ', ', array_keys( $unreleased ) )
				. ' could not be released from the drive — '
				. implode( '; ', $unreleased );

			return $report;
		}

		if ( $dryRun ) {
			$report['message'] = 'would release every engine, then eject ' . $this->volumePath();

			return $report;
		}

		// 2. Try a clean eject first. Nothing is unloaded unless this fails.
		[ $out, $code ] = $this->diskutil( $force );
		if ( 0 === $code ) {
			$report['ejected'] = true;
			$report['message'] = trim( $out ) ?: ( $this->volumeName . ' ejected.' );

			return $report;
		}

		$report['holders'] = $this->holders();

		// 3. Ask each engine to let go of whatever it has open on the drive, then
		// try once more. Only a release that actually succeeded counts — claiming
		// one that failed is precisely how the previous app-quit approach reported
		// success it never achieved.
		if ( ! $noRelease ) {
			foreach ( $this->registry->engines() as $engine ) {
				$released = $engine->releaseHolds();
				if ( ! empty( $released ) ) {
					$report['released'][ $engine->name() ] = $released;
				}
			}
		}

		if ( ! empty( $report['released'] ) ) {
			[ $out, $code ]    = $this->diskutil( $force );
			$report['ejected'] = 0 === $code;
			if ( ! $report['ejected'] ) {
				$report['holders'] = $this->holders();
			}
		}

		if ( $report['ejected'] ) {
			$report['message'] = trim( $out ) ?: ( $this->volumeName . ' ejected.' );
			foreach ( $report['released'] as $engine => $items ) {
				$report['message'] .= ' (unloaded ' . implode( ', ', $items ) . ' from ' . $engine . ')';
			}

			return $report;
		}

		$report['message'] = trim( $out ) . '. '
			. ( empty( $report['holders'] )
				? 'No holder found via lsof; something outside it has the volume.'
				: 'Close the listed processes and retry' )
			. ', or re-run with --force (a forced eject can truncate a file being written).';

		return $report;
	}

	/**
	 * Processes with open files under the volume.
	 *
	 * @return array<int, array{command: string, pid: string, path: string}>
	 */
	public function holders(): array {
		$lsof = getenv( 'AIMODELS_LSOF_BIN' ) ?: 'lsof';
		exec( escapeshellarg( $lsof ) . ' +D ' . escapeshellarg( $this->volumePath() ) . ' 2>/dev/null', $lines );

		$holders = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, 'COMMAND' ) ) {
				continue;
			}

			$columns = preg_split( '/\s+/', $line ) ?: [];
			if ( count( $columns ) < 2 ) {
				continue;
			}

			$holders[] = [
				'command' => $columns[0],
				'pid'     => $columns[1],
				'path'    => (string) end( $columns ),
			];
		}

		return $holders;
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function diskutil( bool $force ): array {
		$bin  = getenv( 'AIMODELS_DISKUTIL_BIN' ) ?: 'diskutil';
		$verb = $force ? 'unmount force' : 'eject';

		exec(
			escapeshellarg( $bin ) . ' ' . $verb . ' ' . escapeshellarg( $this->volumePath() ) . ' 2>&1',
			$out,
			$code
		);

		return [ implode( "\n", $out ), (int) $code ];
	}
}
