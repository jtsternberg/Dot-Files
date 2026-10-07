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
use JT\LocalModels\ModelNotes;
use JT\LocalModels\StoreEngine;
use JT\LocalModels\Watcher;

#[Program(
	name: 'aimodels',
	description: 'Manage local model stores (Ollama LLMs, MacWhisper ASR) across local disk and the AI-LAB drive.',
)]
final class AiModelsCommand {

	private ?Watcher $watcher = null;
	private ?EngineRegistry $registry = null;
	private ?ModelNotes $notes = null;

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
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Report the flip or reconcile without making it.' )]
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
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Report the flip or reconcile without making it.' )]
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
		#[Option( name: 'no-release', description: 'Do not unload loaded models to free the drive; just report holders.' )]
		bool $noRelease = false
	): int {
		$report = ( new Ejector( $this->registry(), $this->volumesRoot ) )->eject( [
			'dry-run'    => $dryRun,
			'force'      => $force,
			'no-release' => $noRelease,
		] );

		foreach ( $report['engines'] as $name => $result ) {
			if ( ApplyResult::NOOP !== $result->status ) {
				$this->cli->msg( '  ' . $name . ': ' . $result->message, 'cyan' );
			}
			// Even a noop's: advice like "diarization only on AI-LAB" matters most
			// in the moment before the drive goes away.
			foreach ( $result->warnings as $warning ) {
				$this->cli->msg( '  ! ' . $name . ': ' . $warning, 'yellow' );
			}
		}

		foreach ( $report['released'] as $engine => $items ) {
			$this->cli->msg(
				'  ' . $engine . ': unloaded ' . implode( ', ', $items ) . ' to free the drive',
				'cyan'
			);
		}

		// Only when it FAILED: holders from a superseded first attempt that the
		// release then fixed would read as a problem where there is none.
		if ( ! $report['ejected'] && ! empty( $report['holders'] ) ) {
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
		#[Argument( description: 'status (default) | install | remove | reload | apply' )]
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

			case 'reload':
				return $this->report( $watcher->reload() );

			// Machine-facing: this is what the LaunchAgent itself runs, on every
			// /Volumes change, so it goes through the debounce rather than doing
			// engine work for a firing where nothing moved.
			case 'apply':
				$failed = 0;
				foreach ( $watcher->applyIfChanged() as $name => $result ) {
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

	#[Command(
		description: 'Notes on when to use each local model, joined to where it lives, plus a graveyard of models tried and removed.',
	)]
	public function why(
		#[Argument( description: 'list (default) | set | rm | locate | history | forget | notes | path | keys' )]
		string $action = 'list',
		#[Argument(
			description: 'An mw ID for MacWhisper (whisperkit:openai_whisper-small), or an Ollama tag, bare or ollama:-prefixed.',
			completionCommand: 'aimodels why keys',
		)]
		?string $model = null,
		#[Argument( description: 'set: the when-text. locate: local | external | both.' )]
		?string $text = null,
		#[Option( description: 'When/why to use this model.' )]
		?string $when = null,
		#[Option( description: 'Speed note, e.g. "~40 tok/s gen".' )]
		?string $speed = null,
		#[Option( description: 'Comma-separated tags.' )]
		?string $tags = null,
		#[Option( description: 'Test result, appended to the existing history with " | " (on set, or in the graveyard by rm --delete-model).' )]
		?string $tested = null,
		#[Option( name: 'replace-tested', description: 'Overwrite the tested history with --tested instead of appending.' )]
		bool $replaceTested = false,
		#[Option( description: 'Where it lives: local | external | both (sd = external). Recorded from the inventory on set.' )]
		?string $location = null,
		#[Option( description: 'Alias for --location.' )]
		?string $loc = null,
		#[Option( name: 'delete-model', description: 'rm: archive the note to the graveyard; for Ollama also `ollama rm` the model.' )]
		bool $deleteModel = false,
		#[Option( description: 'Only this engine: ollama | macwhisper (whisper).' )]
		?string $engine = null,
		#[Option( description: 'Machine-readable output (list, history, notes).' )]
		bool $json = false
	): int {
		$engineFilter = match ( $engine ) {
			null                    => null,
			'ollama'                => 'ollama',
			'macwhisper', 'whisper' => 'macwhisper',
			default                 => false,
		};
		if ( false === $engineFilter ) {
			$this->cli->err( "Unknown engine: {$engine}. Use ollama or macwhisper." );

			return 1;
		}

		$locationValue = $location ?? $loc;
		if ( null !== $locationValue && null === ModelNotes::normalizeLocation( $locationValue ) ) {
			$this->cli->err( "Invalid location \"{$locationValue}\". Use one of: local, external, both." );

			return 1;
		}

		if ( in_array( $action, [ 'set', 'rm', 'locate', 'forget' ], true ) && ( null === $model || '' === $model ) ) {
			$this->cli->err( "Missing <model>. Usage: aimodels why {$action} <model>" );

			return 1;
		}

		switch ( $action ) {
			case 'list':
				return $this->whyList( $engineFilter, $json );

			case 'set':
				$fields = [];
				if ( null !== $text && null === $when ) {
					$fields['when'] = $text;
				}
				foreach ( [ 'when' => $when, 'speed' => $speed ] as $field => $value ) {
					if ( null !== $value ) {
						$fields[ $field ] = $value;
					}
				}
				if ( null !== $tested ) {
					$fields['tested'] = $replaceTested
						? $tested
						: ModelNotes::appendTested( $this->notes()->note( $this->whyKey( $model ) )['tested'] ?? null, $tested );
				}
				if ( null !== $tags ) {
					$fields['tags'] = array_values( array_filter( array_map( 'trim', explode( ',', $tags ) ), 'strlen' ) );
				}
				if ( null !== $locationValue ) {
					$fields['location'] = ModelNotes::normalizeLocation( $locationValue );
				}

				return $this->whySet( $this->whyKey( $model ), $fields );

			case 'rm':
				return $this->whyRemove( $this->whyKey( $model ), $deleteModel, $tested, $replaceTested );

			case 'locate':
				$value = ModelNotes::normalizeLocation( (string) $text );
				if ( null === $value ) {
					$this->cli->err( 'Usage: aimodels why locate <model> <local|external|both>' );

					return 1;
				}
				$key = $this->whyKey( $model );
				$this->notes()->set( $key, [ 'location' => $value ] );
				$this->cli->successMsg( "Set location for {$key}: {$value}" );

				return 0;

			case 'history':
				return $this->whyHistory( $engineFilter, $json );

			case 'forget':
				$key = $this->whyKey( $model );
				if ( ! $this->notes()->forget( $key ) ) {
					$this->cli->err( "No graveyard entry for {$key}" );

					return 1;
				}
				$this->cli->successMsg( "Forgot graveyard entry for {$key}" );

				return 0;

			case 'notes':
				$data = $this->notes()->data();
				$this->cli->output( (string) json_encode(
					[ 'version' => ModelNotes::VERSION, 'models' => (object) $data['models'], 'graveyard' => (object) $data['graveyard'] ],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				) );

				return 0;

			case 'path':
				$this->cli->output( $this->notes()->path() );

				return 0;

			// Machine-facing: the completion value provider for <model>.
			case 'keys':
				$keys = array_merge(
					array_column( $this->whyInventory( null )['models'], 'id' ),
					array_keys( $this->notes()->data()['graveyard'] )
				);
				foreach ( array_unique( array_map( [ ModelNotes::class, 'engineName' ], $keys ) ) as $key ) {
					$this->cli->output( $key );
				}

				return 0;

			default:
				$this->cli->err( "Unknown why action: {$action}" );

				return 1;
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function whySet( string $key, array $fields ): int {
		$note = $this->notes()->note( $key );

		// Last-known location, for when the drive holding it is not mounted.
		if ( ! isset( $fields['location'] ) && empty( $note['location'] ) ) {
			foreach ( $this->whyInventory( null )['models'] as $row ) {
				if ( $row['id'] === $key && null !== $row['location'] ) {
					$fields['location'] = $row['location'];
					$this->cli->msg( "Recorded location from the inventory: {$row['location']}", 'yellow' );
				}
			}
		}

		if ( empty( $fields ) ) {
			$this->cli->err( 'Nothing to set. Provide a when-text or --when/--speed/--tags/--tested/--location.' );

			return 1;
		}

		$buried = $this->notes()->buried( $key );
		if ( null === $note && null !== $buried ) {
			$this->cli->msg(
				"Note: {$key} is in the graveyard (removed " . ( $buried['removed_at'] ?? '?' ) . '). Creating a fresh note;'
					. " `aimodels why forget {$key}` clears the graveyard entry.",
				'yellow'
			);
		}

		$saved = $this->notes()->set( $key, $fields );
		$this->cli->successMsg( "Saved note for {$key}" );
		foreach ( $saved as $field => $value ) {
			$this->cli->msg( "  {$field}: " . ( is_array( $value ) ? implode( ',', $value ) : $value ), 'cyan' );
		}

		return 0;
	}

	private function whyRemove( string $key, bool $deleteModel, ?string $tested, bool $replaceTested ): int {
		$had = $this->notes()->remove( $key, $deleteModel, $tested, null, $replaceTested );

		if ( $had ) {
			$this->cli->successMsg( $deleteModel ? "Archived note for {$key} to the graveyard" : "Removed note for {$key}" );
		} else {
			$this->cli->msg( "No note found for {$key}", 'yellow' );
			if ( ! $deleteModel ) {
				return 1;
			}
			$this->cli->msg( "Added graveyard tombstone for {$key}", 'yellow' );
		}

		if ( ! $deleteModel ) {
			return 0;
		}

		if ( 'ollama' !== ModelNotes::engineOf( $key ) ) {
			// No CLI deletes a MacWhisper bundle safely, and removing one from under
			// a store replica is exactly what reconcile would then "fix" back.
			$this->cli->msg( 'Model files left in place: delete MacWhisper models from the app\'s settings.', 'yellow' );

			return 0;
		}

		$tag = ModelNotes::engineName( $key );
		$bin = getenv( 'AIMODELS_OLLAMA_BIN' ) ?: 'ollama';
		$this->cli->msg( "Running: ollama rm {$tag}", 'cyan' );
		exec( escapeshellarg( $bin ) . ' rm ' . escapeshellarg( $tag ) . ' 2>&1', $out, $code );
		foreach ( $out as $line ) {
			$this->cli->output( $line );
		}

		if ( 0 !== $code ) {
			$this->cli->err( "`ollama rm` failed with exit code {$code}" );

			return 1;
		}
		$this->cli->successMsg( "Deleted model {$tag}" );

		return 0;
	}

	private function whyList( ?string $engine, bool $json ): int {
		$inventory = $this->whyInventory( $engine );

		if ( $json ) {
			$this->cli->output( (string) json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return 0;
		}

		$groups = [];
		foreach ( $inventory['models'] as $row ) {
			$groups[ $row['state'] ][] = $row;
		}

		$width = 0;
		foreach ( $inventory['models'] as $row ) {
			$width = max( $width, strlen( $this->whyLabel( $row ) ) );
		}
		foreach ( $inventory['graveyard'] as $entry ) {
			$width = max( $width, strlen( $entry['name'] ) );
		}
		$width += 2;

		$lastEngine = null;
		foreach ( $groups['available'] ?? [] as $row ) {
			if ( $row['engine'] !== $lastEngine ) {
				$this->cli->msg( ( null === $lastEngine ? '' : "\n" ) . $row['engine'], 'white' );
				$lastEngine = $row['engine'];
			}
			$this->whyRow( $row, $width );
		}

		$sections = [
			'stranded' => [ 'Not in the active store (`aimodels <engine> reconcile`):', null ],
			'offline'  => [ 'Offline (AI-LAB not mounted):', 'Tip: mount the drive; the watcher flips the stores back.' ],
			'orphaned' => [ 'Orphaned notes (models no longer installed):', 'Tip: `aimodels why rm <model>` to clean up.' ],
		];
		foreach ( $sections as $state => [ $heading, $tip ] ) {
			if ( empty( $groups[ $state ] ) ) {
				continue;
			}
			$this->cli->msg( "\n" . $heading, 'yellow' );
			foreach ( $groups[ $state ] as $row ) {
				$this->whyRow( $row, $width );
			}
			if ( $tip ) {
				$this->cli->msg( $tip, 'yellow' );
			}
		}

		if ( ! empty( $inventory['graveyard'] ) ) {
			$this->cli->msg( "\nPreviously tested & removed (see `aimodels why history`):", 'yellow' );
			foreach ( $inventory['graveyard'] as $entry ) {
				$this->cli->output(
					'  ' . $this->cli->color( 'red' ) . str_pad( $entry['name'], $width ) . $this->cli->color( 'none' )
						. str_pad( (string) ( $entry['removed_at'] ?? '?' ), 12 )
						. ( $entry['tested'] ?? ( $entry['when'] ?? '' ) )
				);
			}
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function whyRow( array $row, int $width ): void {
		$note  = $row['note'];
		$label = $this->whyLabel( $row );
		$badge = $this->whyBadge( $row['location'] );
		$line  = '  ' . str_pad( $label, $width ) . $badge . ' ';

		if ( null === $note ) {
			// Support bundles are not something anyone picks, so they are never nagged.
			$this->cli->output(
				$line . ( 'support' === $row['kind']
					? $this->cli->color( 'dark_gray' ) . '(support)' . $this->cli->color( 'none' )
					: $this->cli->color( 'yellow' ) . '(no note — run: aimodels why set ' . $label . ' "...")' . $this->cli->color( 'none' ) )
			);

			return;
		}

		$this->cli->output( $line . $this->cli->color( 'green' ) . ( $note['when'] ?? '' ) . $this->cli->color( 'none' ) );
		$indent = str_repeat( ' ', $width + 13 );
		foreach ( [ 'speed' => 'cyan', 'tested' => 'cyan', 'tags' => 'magenta' ] as $field => $color ) {
			if ( ! empty( $note[ $field ] ) ) {
				$value = is_array( $note[ $field ] ) ? implode( ', ', $note[ $field ] ) : $note[ $field ];
				$this->cli->msg( $indent . $field . ': ' . $value, $color );
			}
		}
	}

	/**
	 * Ollama rows show the bare tag (what `ollama run` takes); MacWhisper rows the
	 * mw ID (what `mw transcribe --model` takes).
	 *
	 * @param array<string, mixed> $row
	 */
	private function whyLabel( array $row ): string {
		return ModelNotes::engineName( $row['id'] );
	}

	private function whyBadge( ?string $location ): string {
		$colors = [ 'local' => 'cyan', 'external' => 'magenta', 'both' => 'green' ];

		return $this->cli->color( $colors[ $location ] ?? 'dark_gray' )
			. str_pad( '[' . ( $location ?? '?' ) . ']', 10 )
			. $this->cli->color( 'none' );
	}

	private function whyHistory( ?string $engine, bool $json ): int {
		$graveyard = $this->notes()->graveyard( $engine );

		if ( $json ) {
			$this->cli->output( (string) json_encode( [ 'graveyard' => $graveyard ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return 0;
		}

		if ( empty( $graveyard ) ) {
			$this->cli->msg( 'Graveyard is empty — no models tested & removed yet.', 'yellow' );

			return 0;
		}

		$this->cli->msg( 'Previously tested & removed:', 'white' );
		foreach ( $graveyard as $entry ) {
			$this->cli->output(
				'  ' . $this->cli->color( 'red' ) . $entry['name'] . $this->cli->color( 'none' )
					. ( empty( $entry['location'] ) ? '' : ' ' . trim( $this->whyBadge( $entry['location'] ) ) )
					. $this->cli->color( 'yellow' ) . '  (removed ' . ( $entry['removed_at'] ?? '?' ) . ')' . $this->cli->color( 'none' )
			);
			foreach ( [ 'tested' => 'cyan', 'when' => 'green', 'speed' => 'cyan', 'tags' => 'magenta' ] as $field => $color ) {
				if ( ! empty( $entry[ $field ] ) ) {
					$value = is_array( $entry[ $field ] ) ? implode( ', ', $entry[ $field ] ) : $entry[ $field ];
					$this->cli->msg( '      ' . $field . ': ' . $value, $color );
				}
			}
		}

		return 0;
	}

	/**
	 * Residency comes from Watcher::status(), the accessor `aimodels status`
	 * renders — never from a second list of store paths.
	 *
	 * @return array{mounted: bool, models: array<int, array<string, mixed>>, graveyard: array<int, array<string, mixed>>}
	 */
	private function whyInventory( ?string $engine ): array {
		return $this->notes()->inventory( $this->watcher()->status(), $engine );
	}

	private function whyKey( string $input ): string {
		$ids = [];
		foreach ( $this->watcher()->status()['engines'] as $engine ) {
			$ids = array_merge( $ids, array_column( $engine['models'], 'id' ) );
		}

		return ModelNotes::resolveKey( $input, $ids );
	}

	/**
	 * `bin/ollama-why` argv as the equivalent `aimodels why` argv. Scoped to
	 * Ollama, as ollama-why always was, unless the caller picked an engine.
	 *
	 * @param string[] $argv
	 * @return string[]
	 */
	public static function ollamaWhyArgv( array $argv ): array {
		$rest = array_slice( $argv, 1 );
		if ( 'help' === ( $rest[0] ?? null ) ) {
			$rest[0] = '-h';
		}

		$scoped = false;
		foreach ( $rest as $arg ) {
			$scoped = $scoped || str_starts_with( $arg, '--engine=' );
		}

		return array_merge( [ 'aimodels', 'why' ], $rest, $scoped ? [] : [ '--engine=ollama' ] );
	}

	/**
	 * `bin/ollamodels` argv as the equivalent `aimodels` argv, or null for the
	 * watcher verbs: the LaunchAgent now follows AI-LAB for every engine, so an
	 * Ollama-only install/remove must not be mistaken for managing it.
	 *
	 * -y/--yes is dropped: `aimodels ollama reconcile` never prompts, because it
	 * only ever adds symlinks.
	 *
	 * @param string[] $argv
	 * @return string[]|null
	 */
	public static function ollamodelsArgv( array $argv ): ?array {
		$action = null;
		$flags  = [];
		foreach ( array_slice( $argv, 1 ) as $arg ) {
			if ( in_array( $arg, [ '-h', '--help' ], true ) ) {
				return [ 'aimodels', 'help', 'ollama' ];
			}
			if ( in_array( $arg, [ '--dry-run', '-n' ], true ) ) {
				$flags[] = '--dry-run';
			} elseif ( ! str_starts_with( $arg, '-' ) ) {
				$action ??= strtolower( $arg );
			}
		}

		return match ( $action ) {
			'help'                             => [ 'aimodels', 'help', 'ollama' ],
			'installwatcher', 'removewatcher'  => null,
			default                            => array_merge( [ 'aimodels', 'ollama', $action ?? 'auto' ], $flags ),
		};
	}

	private function notes(): ModelNotes {
		return $this->notes ??= new ModelNotes( ModelNotes::defaultPath( $this->home ) );
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

		foreach ( $result->details as $detail ) {
			$this->cli->output( '  ' . $detail );
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
