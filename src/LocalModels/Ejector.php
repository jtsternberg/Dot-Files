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
 * Two rules it will not break: it never quits an application on its own (that is
 * opt-in, and even then it asks the app politely), and it never forces an eject
 * unless asked. A forced eject on a volume a running app is mid-write to is how
 * model files get truncated.
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
	 * @param array<string, mixed> $options force|dry-run|quit-apps
	 *
	 * @return array<string, mixed>
	 */
	public function eject( array $options = [] ): array {
		$force    = ! empty( $options['force'] );
		$dryRun   = ! empty( $options['dry-run'] );
		$quitApps = ! empty( $options['quit-apps'] );

		$report = [
			'volume'      => $this->volumePath(),
			'ejected'     => false,
			'alreadyGone' => false,
			'message'     => '',
			'engines'     => [],
			'holders'     => [],
			'quit'        => [],
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

		// 2. Optionally ask known model apps to quit — only ever on request.
		if ( $quitApps ) {
			$report['quit'] = $this->quitModelApps();
		}

		// 3. Eject.
		[ $out, $code ] = $this->diskutil( $force );
		if ( 0 === $code ) {
			$report['ejected'] = true;
			$report['message'] = trim( $out ) ?: ( $this->volumeName . ' ejected.' );

			return $report;
		}

		// 4. Still busy: name the holders rather than forcing.
		$report['holders'] = $this->holders();
		$report['message'] = trim( $out ) . '. '
			. ( empty( $report['holders'] )
				? 'No holder found via lsof; something outside it has the volume.'
				: 'Quit the listed processes and retry' )
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
	 * Ask the model apps to quit — `osascript` quit, never a kill, so anything
	 * mid-write gets to finish. Returns the apps asked.
	 *
	 * @return string[]
	 */
	private function quitModelApps(): array {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return [];
		}

		$asked = [];
		foreach ( [ 'MacWhisper', 'Ollama' ] as $app ) {
			exec( 'pgrep -x ' . escapeshellarg( $app ) . ' 2>/dev/null', $found, $code );
			if ( 0 !== $code || empty( $found ) ) {
				continue;
			}

			exec(
				'osascript -e ' . escapeshellarg( 'tell application "' . $app . '" to quit' ) . ' 2>&1',
				$out,
				$quitCode
			);
			if ( 0 === $quitCode ) {
				$asked[] = $app;
			}
		}

		return $asked;
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
