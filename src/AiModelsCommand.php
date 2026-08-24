<?php

namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use JT\LocalModels\AbstractStoreEngine;
use JT\LocalModels\ApplyResult;
use JT\LocalModels\Ejector;
use JT\LocalModels\EngineRegistry;
use JT\LocalModels\StoreEngine;
use JT\LocalModels\Watcher;

#[Program(
	name: 'aimodels',
	description: 'Manage local model stores (Ollama LLMs, MacWhisper ASR) across local disk and the AI-LAB drive.',
)]
final class AiModelsCommand {

	private ?Watcher $watcher = null;
	private ?EngineRegistry $registry = null;

	public function __construct(
		private readonly Helpers $cli,
		private readonly ?string $home = null,
		private readonly string $volumesRoot = '/Volumes'
	) {
	}

	#[Command(
		description: 'Show every engine: which store it is on, and whether it is manageable yet.',
		default: true,
	)]
	public function status(
		#[Option( description: 'Machine-readable output.' )]
		bool $json = false,
		#[Option( description: 'Suppress chatter; results only.' )]
		bool $silent = false
	): int {
		$status = $this->watcher()->status();

		if ( $json ) {
			$this->cli->output( (string) json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return 0;
		}

		$this->cli->msg(
			'AI-LAB: ' . ( $status['mounted'] ? 'mounted' : 'not mounted' )
				. '   watcher: ' . ( $status['installed'] ? 'installed' : 'NOT installed' )
				. ( $status['legacy'] ? '   legacy ollamodels watcher: still present' : '' ),
			$status['mounted'] ? 'green' : 'yellow'
		);

		foreach ( $status['engines'] as $name => $engine ) {
			$where = $engine['location'] ?? 'unlinked';
			$this->cli->output( sprintf( '%-12s %-10s %s', $name, $where, $engine['symlink'] ) );

			if ( ! $engine['manageable'] ) {
				$this->cli->msg( '             not manageable yet: ' . $engine['reason'], 'yellow' );
			}

			foreach ( $engine['models'] as $model ) {
				$this->cli->output( sprintf(
					'  %-1s%-1s %-34s %-11s %s',
					$model['local'] ? 'L' : '·',
					$model['external'] ? 'X' : '·',
					$model['name'],
					$model['framework'],
					$this->size( $model ) . ( 'support' === $model['kind'] ? '  (support)' : '' )
				) );
			}
		}

		if ( ! $this->cli->isSilent() ) {
			$this->cli->msg( '  L = in local store, X = in AI-LAB store', 'cyan' );
			$this->ejectHint( $status );
		}

		return 0;
	}

	/**
	 * Unplugging AI-LAB while a store still points into it is what makes Finder
	 * report "busy", so say so wherever the drive shows as in use.
	 *
	 * @param array<string, mixed> $status
	 */
	private function ejectHint( array $status ): void {
		if ( ! $status['mounted'] ) {
			return;
		}

		foreach ( $status['engines'] as $engine ) {
			if ( 'external' === $engine['location'] ) {
				$this->cli->msg( '  Removing the drive? `aimodels eject` releases the stores first.', 'cyan' );

				return;
			}
		}
	}

	/**
	 * @param array<string, mixed> $model
	 */
	private function size( array $model ): string {
		return null === $model['sizeMb'] ? '' : $model['sizeMb'] . 'M';
	}

	#[Command(
		description: 'Terse: the active store per engine, and whether AI-LAB is mounted.',
	)]
	public function where(): int {
		$status = $this->watcher()->status();

		$this->cli->output( 'AI-LAB: ' . ( $status['mounted'] ? 'mounted' : 'not mounted' ) );
		foreach ( $status['engines'] as $name => $engine ) {
			$this->cli->output( $name . ': ' . ( $engine['location'] ?? 'unlinked' ) );
		}
		$this->ejectHint( $status );

		return 0;
	}

	#[Command(
		description: 'Point the Ollama store at local, the AI-LAB drive, or whichever is right now.',
	)]
	public function ollama(
		#[Argument( description: 'local | sd | external | auto (default) | reconcile' )]
		string $action = 'auto',
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Report the flip without making it.' )]
		bool $dryRun = false
	): int {
		return $this->engineAction( 'ollama', $action, $dryRun );
	}

	#[Command(
		description: 'Point the MacWhisper store at local, the AI-LAB drive, or whichever is right now.',
	)]
	public function whisper(
		#[Argument( description: 'local | external | auto (default) | reconcile' )]
		string $action = 'auto',
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Report the flip without making it.' )]
		bool $dryRun = false
	): int {
		return $this->engineAction( 'macwhisper', $action, $dryRun );
	}

	#[Command(
		description: 'Release every model store from AI-LAB, then eject it. The safe way to unplug the drive.',
	)]
	public function eject(
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Show what would be released and ejected.' )]
		bool $dryRun = false,
		#[Option( description: 'Force the unmount if it is still busy. Can truncate a file being written.' )]
		bool $force = false,
		#[Option( name: 'no-quit', description: 'Never quit a blocking app; just report the holders.' )]
		bool $noQuit = false,
		#[Option( name: 'no-restart', description: 'Leave any app it quit closed instead of reopening it.' )]
		bool $noRestart = false
	): int {
		$report = ( new Ejector( $this->registry(), $this->volumesRoot ) )->eject( [
			'dry-run'    => $dryRun,
			'force'      => $force,
			'no-quit'    => $noQuit,
			'no-restart' => $noRestart,
		] );

		foreach ( $report['engines'] as $name => $result ) {
			if ( ApplyResult::NOOP !== $result->status ) {
				$this->cli->msg( '  ' . $name . ': ' . $result->message, 'cyan' );
			}
		}

		foreach ( $report['quit'] as $app ) {
			$this->cli->msg( '  asked ' . $app . ' to quit (it held the volume)', 'cyan' );
		}

		foreach ( $report['reopened'] as $app ) {
			$this->cli->msg( '  reopened ' . $app, 'cyan' );
		}

		if ( ! empty( $report['holders'] ) ) {
			$this->cli->msg( 'Still holding the volume:', 'yellow' );
			foreach ( $report['holders'] as $holder ) {
				$this->cli->output( sprintf(
					'  %-14s pid %-7s %s',
					$holder['command'],
					$holder['pid'],
					$holder['path']
				) );
			}
		}

		if ( $report['ejected'] ) {
			$this->cli->successMsg( $report['message'] );

			return 0;
		}

		if ( $dryRun ) {
			$this->cli->output( $report['message'] );

			return 0;
		}

		$this->cli->err( $report['message'] );

		return 1;
	}

	#[Command(
		description: 'Manage the LaunchAgent that follows the AI-LAB drive for every engine.',
	)]
	public function watch(
		#[Argument( description: 'status (default) | install | remove | apply' )]
		string $action = 'status',
		// The LaunchAgent runs `watch apply --silent`; undeclared, the dispatcher
		// rejects it as an unknown option AND silent mode swallows the error, so
		// the agent would fail on every /Volumes event without a word.
		#[Option( description: 'Suppress chatter; results only. Used by the LaunchAgent.' )]
		bool $silent = false
	): int {
		$watcher = $this->watcher();

		switch ( $action ) {
			case 'status':
				$this->status();

				// The log is the whole point of `watch status`: it is the only
				// record of what a launchd-triggered flip actually decided.
				$state = $watcher->status();
				$this->cli->msg( 'log: ' . $state['log'], 'cyan' );
				foreach ( $state['recent'] as $line ) {
					$this->cli->output( '  ' . $line );
				}
				if ( empty( $state['recent'] ) ) {
					$this->cli->msg( '  (no entries yet — the watcher has not run since logging landed)', 'yellow' );
				}

				return 0;

			case 'install':
				return $this->report( $watcher->install() );

			case 'remove':
				return $this->report( $watcher->remove() );

			// Machine-facing: this is what the LaunchAgent itself runs.
			case 'apply':
				$failed = 0;
				foreach ( $watcher->applyAll() as $name => $result ) {
					if ( ApplyResult::FAILED === $result->status ) {
						$failed++;
					}
					if ( ApplyResult::NOOP !== $result->status ) {
						$this->report( $result, $name );
					}
				}

				return $failed > 0 ? 1 : 0;

			default:
				$this->cli->err( "Unknown watch action: {$action}" );

				return 1;
		}
	}

	private function engineAction( string $engineName, string $action, bool $dryRun ): int {
		$engine = $this->registry()->engine( $engineName );
		if ( ! $engine instanceof StoreEngine ) {
			$this->cli->err( "Unknown engine: {$engineName}" );

			return 1;
		}

		if ( 'status' === $action ) {
			return $this->status();
		}

		if ( 'reconcile' === $action ) {
			return $this->report( $engine->reconcile( [ 'dry-run' => $dryRun ] ), $engineName );
		}

		$location = match ( $action ) {
			'auto'              => $engine instanceof AbstractStoreEngine
				? $engine->locationForDrive()
				: AbstractStoreEngine::LOCAL,
			'sd', 'external'    => AbstractStoreEngine::EXTERNAL,
			'local'             => AbstractStoreEngine::LOCAL,
			default             => null,
		};

		if ( null === $location ) {
			$this->cli->err( "Unknown action: {$action}" );

			return 1;
		}

		return $this->report( $engine->apply( $location, $dryRun ), $engineName );
	}

	private function report( ApplyResult $result, string $prefix = '' ): int {
		$label = $prefix ? $prefix . ': ' : '';

		if ( ApplyResult::FAILED === $result->status ) {
			$this->cli->err( $label . $result->message );
		} else {
			$this->cli->output( $label . $result->message );
		}

		foreach ( $result->warnings as $warning ) {
			$this->cli->msg( '  ! ' . $warning, 'yellow' );
		}

		return $result->ok() ? 0 : 1;
	}

	private function watcher(): Watcher {
		return $this->watcher ??= new Watcher( $this->home, $this->volumesRoot, $this->registry() );
	}

	private function registry(): EngineRegistry {
		return $this->registry ??= new EngineRegistry( $this->home, $this->volumesRoot );
	}
}
