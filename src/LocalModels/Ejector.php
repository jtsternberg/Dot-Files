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
 * When the volume is still busy after that, the holder in practice is a model app
 * with a file open on the drive — observed live as `llama-server`, an Ollama.app
 * subprocess with an FD on a blob. So a blocked eject asks that app to quit and
 * retries once, then reopens it.
 *
 * Three rules it will not break:
 *
 *   - It only ever quits an app it recognises as a model app. Any other holder is
 *     reported and left alone.
 *   - It asks politely (`osascript … to quit`), never a signal — a killed app
 *     mid-write truncates model files.
 *   - Whatever it closed, it reopens, on success AND on failure. A failed eject
 *     must never be the reason Ollama is left shut. Same principle as the
 *     watcher restoring the external store when an eject fails: fail => restore.
 *
 * It never forces an unmount unless explicitly asked.
 */
final class Ejector {

	/**
	 * Which running application owns a process that lsof might name as a holder.
	 *
	 * lsof reports the *process* — Ollama's model server shows up as
	 * `llama-server`, not `Ollama` — and truncates COMMAND to 9 characters
	 * (`MacWhispe`). Both are matched by prefix, in either direction.
	 *
	 * @var array<string, string[]>
	 */
	private const MODEL_APPS = [
		'Ollama'     => [ 'Ollama', 'ollama', 'llama-server' ],
		'MacWhisper' => [ 'MacWhisper' ],
	];

	private AppControl $apps;

	public function __construct(
		private readonly EngineRegistry $registry,
		private readonly string $volumesRoot = '/Volumes',
		private readonly string $volumeName = 'AI-LAB',
		?AppControl $apps = null
	) {
		$this->apps = $apps ?: new AppControl();
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
		$force     = ! empty( $options['force'] );
		$dryRun    = ! empty( $options['dry-run'] );
		$noQuit    = ! empty( $options['no-quit'] );
		$noRestart = ! empty( $options['no-restart'] );

		$report = [
			'volume'      => $this->volumePath(),
			'ejected'     => false,
			'alreadyGone' => false,
			'message'     => '',
			'engines'     => [],
			'holders'     => [],
			'quit'        => [],
			'reopened'    => [],
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

		// 2. Try a clean eject first. Nothing is quit unless this fails.
		[ $out, $code ] = $this->diskutil( $force );
		if ( 0 === $code ) {
			$report['ejected'] = true;
			$report['message'] = trim( $out ) ?: ( $this->volumeName . ' ejected.' );

			return $report;
		}

		$report['holders'] = $this->holders();

		// 3. Blocked by an app we know? Ask it to quit and try once more. Only
		// ever a known model app — a random holder is reported, never touched.
		$blocking = $noQuit ? [] : $this->modelAppsAmong( $report['holders'] );
		foreach ( $blocking as $app ) {
			if ( ! $this->apps->isRunning( $app ) ) {
				continue;
			}
			if ( $this->apps->quit( $app ) ) {
				$report['quit'][] = $app;
				$this->apps->waitForExit( $app );
			}
		}

		if ( ! empty( $report['quit'] ) ) {
			[ $out, $code ]    = $this->diskutil( $force );
			$report['ejected'] = 0 === $code;
			if ( ! $report['ejected'] ) {
				$report['holders'] = $this->holders();
			}
		}

		// 4. Put back whatever we closed — in BOTH outcomes. A failed eject must
		// never be the reason an app is left shut.
		if ( ! $noRestart ) {
			foreach ( $report['quit'] as $app ) {
				if ( $this->apps->reopen( $app ) ) {
					$report['reopened'][] = $app;
				}
			}
		}

		if ( $report['ejected'] ) {
			$report['message'] = trim( $out ) ?: ( $this->volumeName . ' ejected.' );
			if ( ! empty( $report['quit'] ) ) {
				$report['message'] .= ' (quit ' . implode( ', ', $report['quit'] ) . ' to free it'
					. ( empty( $report['reopened'] ) ? '' : ', reopened after' ) . ')';
			}

			return $report;
		}

		$report['message'] = trim( $out ) . '. '
			. ( empty( $report['holders'] )
				? 'No holder found via lsof; something outside it has the volume.'
				: 'Quit the listed processes and retry' )
			. ', or re-run with --force (a forced eject can truncate a file being written).';

		return $report;
	}

	/**
	 * Known model apps among a set of lsof holders, in registry order.
	 *
	 * @param array<int, array{command: string, pid: string, path: string}> $holders
	 *
	 * @return string[]
	 */
	private function modelAppsAmong( array $holders ): array {
		$apps = [];

		foreach ( $holders as $holder ) {
			$command = strtolower( $holder['command'] );
			if ( strlen( $command ) < 3 ) {
				continue;
			}

			foreach ( self::MODEL_APPS as $app => $processes ) {
				if ( isset( $apps[ $app ] ) ) {
					continue;
				}

				foreach ( $processes as $process ) {
					$process = strtolower( $process );
					// Either side may be the truncated one.
					if ( str_starts_with( $process, $command ) || str_starts_with( $command, $process ) ) {
						$apps[ $app ] = true;
						break;
					}
				}
			}
		}

		return array_keys( $apps );
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
