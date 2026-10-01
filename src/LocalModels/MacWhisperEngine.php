<?php

namespace JT\LocalModels;

use JT\Helpers\Ollama;

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
 *
 * It holds nothing open on the drive between transcriptions — validated live: it
 * was never the process blocking an eject. So it inherits the no-op
 * releaseHolds(), which is a measured fact rather than an unimplemented hook.
 */
final class MacWhisperEngine extends AbstractStoreEngine {

	/** Every runner mode, by label. All three follow the store. */
	private const MODES = [
		MacWhisperPrefs::SELECTED  => 'file transcription',
		MacWhisperPrefs::DICTATION => 'dictation',
		MacWhisperPrefs::LIVE      => 'live transcription',
	];

	/**
	 * `mw models list` id prefix => the engine key MacWhisper writes into a
	 * runner config for it. Each pair was observed in real prefs after a
	 * `mw models select`; an engine missing here is one never observed.
	 */
	private const ENGINE_KEYS = [
		'whisperkit'   => 'whisperKit',
		'qwen3-asr'    => 'qwenASRKitPro',
		'parakeet-pro' => 'parakeetKitPro',
		'whisper-cpp'  => 'whisperCPP',
	];

	/**
	 * Prefixes whose runner config was observed to be exactly
	 * {"engine":{<key>:{"model":{"id":<id after the colon>}}}} for every mode,
	 * so it can be written while the app is quit. whisper-cpp's embeds the app's
	 * whole model record (URLs, title, size order) — only the app may write it.
	 */
	private const DIRECTLY_WRITABLE = [ 'whisperkit', 'qwen3-asr', 'parakeet-pro' ];

	/**
	 * Diarization bundles, by top-level dir. The app chooses between them itself
	 * (pyannote in speakerkit, Nemotron in speakerkit-pro), so `--speakers` is
	 * offline-safe only while the local store holds every one AI-LAB does. They
	 * are small, unlike the ASR models, which is why these alone flow to local.
	 */
	private const SUPPORT_BUNDLES = [ 'speakerkit', 'speakerkit-pro' ];

	private AppControl $apps;

	private MacWhisperPrefs $prefs;

	private MacWhisperCli $mw;

	public function __construct(
		?string $home = null,
		string $volumesRoot = '/Volumes',
		?AppControl $apps = null,
		?MacWhisperPrefs $prefs = null,
		?MacWhisperCli $mw = null
	) {
		parent::__construct( $home, $volumesRoot );
		$this->apps  = $apps ?: new AppControl();
		$this->prefs = $prefs ?: new MacWhisperPrefs();
		$this->mw    = $mw ?: new MacWhisperCli();
	}

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
	 * Restart MacWhisper so its UI reflects the store it now points at, and
	 * select the store's configured model.
	 *
	 * Only ever reached from a flip that actually moved the symlink — apply()
	 * calls this on the APPLIED path alone, never for a noop or a dry run. That
	 * matters: the watcher fires on every /Volumes change and one mount produced
	 * three firings live, all of them already-external noops. Restarting on each
	 * would be a quit/reopen storm.
	 *
	 * @return string[]
	 */
	protected function postApply( string $location ): array {
		$key      = self::configKey( $location );
		$model    = $this->configuredModel( $key );
		$warnings = [];
		$whenQuit = null;
		$afterReopen = null;

		if ( null !== $model ) {
			if ( isset( $this->storeIds( $location )[ $model ] ) ) {
				$whenQuit    = fn(): array => $this->writeSelection( $model );
				$afterReopen = fn(): array => $this->mw->select( $model )
					? [ 'selected ' . $model . ' in MacWhisper.' ]
					: [ 'could not select ' . $model . ' with `mw models select` — select it in MacWhisper.' ];
			} else {
				$warnings[] = $key . '=' . $model . ' is not in the ' . $location
					. ' store — left MacWhisper\'s model selection alone.';
			}
		}

		return array_merge(
			$warnings,
			$this->restartIfSafe( $location, $whenQuit, $afterReopen ),
			$this->missingModelWarnings( $location )
		);
	}

	public static function configKey( string $location ): string {
		return self::EXTERNAL === $location ? 'WHISPER_MODEL_EXTERNAL' : 'WHISPER_MODEL_LOCAL';
	}

	/**
	 * The shared local-model config (see JT\Helpers\Ollama), read from $home
	 * rather than XDG_CONFIG_HOME so a test's temp home can never pick up the real
	 * file and rewrite the real app's selection.
	 *
	 * Values are `mw models list` ids. A bare id is WhisperKit: the config's
	 * original form, from before any other engine could be selected.
	 */
	private function configuredModel( string $key ): ?string {
		$value = trim( (string) ( ( new Ollama() )->config( $this->home . '/.config' )[ $key ] ?? '' ) );

		if ( '' === $value ) {
			return null;
		}

		return str_contains( $value, ':' ) ? $value : 'whisperkit:' . $value;
	}

	/**
	 * The `mw models list` ids of every ASR model in a store — the same ids
	 * `aimodels status` shows per residency row.
	 *
	 * @return array<string, true>
	 */
	private function storeIds( string $location ): array {
		$store = $this->storePath( $location );
		$ids   = [];

		foreach ( is_dir( $store ) ? $this->modelsIn( $store ) : [] as $model ) {
			if ( 'asr' === $model['kind'] ) {
				$ids[ $this->modelId( $model ) ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Point every mode at $model by writing the prefs, keeping the rest of each
	 * runner config (language etc). Only for a quit app — a running one overwrites
	 * the prefs from memory — and only in a shape observed in real prefs.
	 *
	 * @return string[]
	 */
	private function writeSelection( string $model ): array {
		[ $prefix, $id ] = explode( ':', $model, 2 );

		if ( ! in_array( $prefix, self::DIRECTLY_WRITABLE, true ) ) {
			return [ 'MacWhisper is not running, and only the app can write a ' . $prefix
				. ' selection — select ' . $model . ' in MacWhisper.' ];
		}

		$changed = [];
		foreach ( self::MODES as $pref => $label ) {
			$config = json_decode( (string) $this->prefs->get( $pref ), true );
			$config = is_array( $config ) ? $config : [];

			if ( $this->selectedIn( $config ) === $model ) {
				continue;
			}

			$config['engine'] = [ self::ENGINE_KEYS[ $prefix ] => [ 'model' => [ 'id' => $id ] ] ];
			if ( ! $this->prefs->set( $pref, (string) json_encode( $config, JSON_UNESCAPED_SLASHES ) ) ) {
				return [ 'could not write MacWhisper\'s ' . $label . ' model — select ' . $model . ' in MacWhisper.' ];
			}
			$changed[] = $label;
		}

		return empty( $changed ) ? [] : [ 'set MacWhisper\'s ' . self::labelList( $changed ) . ' model to ' . $model . '.' ];
	}

	/**
	 * A runner config's selection as an `mw models list` id; the bare engine key
	 * when it is one never observed; null when none is set.
	 *
	 * @param array<string, mixed> $config
	 */
	private function selectedIn( array $config ): ?string {
		$engine = $config['engine'] ?? null;
		if ( ! is_array( $engine ) || empty( $engine ) ) {
			return null;
		}

		$key    = (string) array_key_first( $engine );
		$id     = $engine[ $key ]['model']['id'] ?? null;
		$prefix = array_search( $key, self::ENGINE_KEYS, true );

		if ( false === $prefix ) {
			return $key;
		}

		return is_string( $id ) ? $prefix . ':' . $id : null;
	}

	/**
	 * Name every mode whose selected model the store lacks, whatever its engine —
	 * a mode left on an absent model breaks in the app only when it is next used.
	 *
	 * @return string[]
	 */
	private function missingModelWarnings( string $location ): array {
		$ids        = $this->storeIds( $location );
		$missing    = [];
		$unverified = [];

		foreach ( self::MODES as $pref => $label ) {
			$config   = json_decode( (string) $this->prefs->get( $pref ), true );
			$selected = is_array( $config ) ? $this->selectedIn( $config ) : null;

			if ( null === $selected || isset( $ids[ $selected ] ) ) {
				continue;
			}

			if ( str_contains( $selected, ':' ) ) {
				$missing[ $selected ][] = $label;
			} else {
				$unverified[ $selected ][] = $label;
			}
		}

		$warnings = [];
		foreach ( $missing as $id => $labels ) {
			$warnings[] = 'MacWhisper\'s ' . self::labelList( $labels ) . ' model ' . $id . ' is not in the '
				. $location . ' store — set ' . self::configKey( $location )
				. ' in ~/.config/ai-tooling/config, or pick another model in MacWhisper.';
		}
		foreach ( $unverified as $engine => $labels ) {
			$warnings[] = 'MacWhisper\'s ' . self::labelList( $labels ) . ' uses engine ' . $engine
				. ', which aimodels cannot check against the ' . $location . ' store.';
		}

		return $warnings;
	}

	/** @param string[] $labels */
	private static function labelList( array $labels ): string {
		$last = array_pop( $labels );

		return empty( $labels ) ? (string) $last : implode( ', ', $labels ) . ' and ' . $last;
	}

	/**
	 * @param ?callable(): string[] $whenQuit    Runs when the app was not running; must not launch it.
	 * @param ?callable(): string[] $afterReopen Runs once the app has relaunched onto the new store.
	 *
	 * @return string[] warnings
	 */
	private function restartIfSafe( string $location, ?callable $whenQuit = null, ?callable $afterReopen = null ): array {
		$app = 'MacWhisper';

		if ( ! $this->apps->isRunning( $app ) ) {
			return $whenQuit ? $whenQuit() : [];
		}

		$stale = $app . ' caches its model list at launch, so relaunch it to see the '
			. $location . ' store.';

		if ( getenv( 'AIMODELS_NO_RESTART' ) ) {
			return [ 'automatic restart disabled (AIMODELS_NO_RESTART) — ' . $stale ];
		}

		$busy = $this->busyReason( $app );
		if ( null !== $busy ) {
			// Quitting mid-transcription kills the job, so anything short of a
			// confident idle reading leaves the app alone.
			return [ $app . ' looks busy (' . $busy . ') — not restarting it; ' . $stale ];
		}

		if ( ! $this->apps->quit( $app ) ) {
			return [ 'could not quit ' . $app . ' — ' . $stale ];
		}

		$this->apps->waitForExit( $app );

		if ( ! $this->reopenWithRetry( $app ) ) {
			return [ $app . ' was quit but did not reopen — relaunch it to see the ' . $location . ' store.' ];
		}

		return array_merge(
			[ 'restarted MacWhisper so it lists the ' . $location . ' store.' ],
			$afterReopen ? $afterReopen() : []
		);
	}

	/**
	 * `open -a` can lose a race with the app's own teardown and fail, which left
	 * MacWhisper closed on two flips in quick succession. Retry before giving up.
	 */
	private function reopenWithRetry( string $app, int $tries = 3 ): bool {
		for ( $attempt = 0; $attempt < max( 1, $tries ); $attempt++ ) {
			if ( $this->apps->reopen( $app ) ) {
				return true;
			}

			if ( $attempt < $tries - 1 ) {
				Sleeper::usleep( 700000 );
			}
		}

		return false;
	}

	/**
	 * Why the app might be working, or null when it reads as idle. Every unknown
	 * counts as busy: a stale model list is cheap, a killed transcription is not.
	 */
	private function busyReason( string $app ): ?string {
		$cpu = $this->apps->cpuPercent( $app );
		if ( null === $cpu ) {
			return 'CPU unreadable';
		}

		if ( $cpu >= AppControl::BUSY_CPU_PERCENT ) {
			return 'CPU ' . $cpu . '%';
		}

		$media = $this->apps->openMediaFiles( $app );
		if ( ! empty( $media ) ) {
			return 'holding ' . basename( $media[0] ) . ' open';
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	public function advisories( string $location ): array {
		$advisories = [];

		// Says nothing about the drive's mount state: the local store is also
		// active after a deliberate flip with AI-LAB still plugged in, and this
		// used to claim "AI-LAB not mounted" while it plainly was.
		if ( self::LOCAL === $location ) {
			$advisories[] = 'on the local store — new MacWhisper model downloads land there, and need'
				. ' `aimodels whisper reconcile` to reach the AI-LAB superset.';
		}

		$stranded = array_column( $this->supportMissingLocally(), 'relative' );
		if ( ! empty( $stranded ) ) {
			$advisories[] = 'diarization support only on AI-LAB (' . implode( ', ', $stranded )
				. ') — --speakers can fail once it is ejected; run `aimodels whisper reconcile` while it is mounted.';
		}

		return $advisories;
	}

	/**
	 * Support-bundle entries AI-LAB holds and the local store lacks. Empty when
	 * the drive is not mounted: there is nothing to compare against.
	 *
	 * @return array<int, array{from: string, to: string, relative: string}>
	 */
	private function supportMissingLocally(): array {
		$local    = $this->storePath( self::LOCAL );
		$external = $this->storePath( self::EXTERNAL );
		$plan     = [];

		if ( ! is_dir( $local ) || ! is_dir( $external ) ) {
			return [];
		}

		foreach ( self::SUPPORT_BUNDLES as $bundle ) {
			$from = $external . '/' . $bundle;
			$to   = $local . '/' . $bundle;

			if ( ! is_dir( $from ) ) {
				continue;
			}

			if ( ! file_exists( $to ) ) {
				$plan[] = [ 'from' => $from, 'to' => $to, 'relative' => $bundle ];
				continue;
			}

			$plan = array_merge( $plan, $this->absentFrom( $from, $to, $bundle ) );
		}

		return $plan;
	}

	/**
	 * Copy anything the local store has and the external superset lacks, then
	 * any diarization support AI-LAB has and the local store lacks.
	 *
	 * Strictly additive: an entry the destination already holds is never
	 * overwritten and nothing is ever deleted. Local -> external for everything,
	 * because the external store is the authoritative superset; external -> local
	 * for SUPPORT_BUNDLES alone, because the app can download those onto AI-LAB
	 * and they must survive an eject. Copies, never symlinks — a symlinked bundle
	 * is invisible to the app, which is the whole reason this engine exists.
	 *
	 * @param array<string, mixed> $options
	 */
	public function reconcile( array $options = [] ): ApplyResult {
		$dryRun = ! empty( $options['dry-run'] );
		$local  = $this->storePath( self::LOCAL );
		$target = $this->storePath( self::EXTERNAL );

		if ( ! is_dir( $local ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'local store missing: ' . $local );
		}

		if ( ! is_dir( $target ) ) {
			return new ApplyResult(
				ApplyResult::FAILED,
				'external store not available (mount AI-LAB and retry): ' . $target
			);
		}

		$plan = [];
		foreach ( $this->absentFrom( $local, $target ) as $item ) {
			$plan[] = $item + [ 'direction' => 'local -> external' ];
		}
		foreach ( $this->supportMissingLocally() as $item ) {
			$plan[] = $item + [ 'direction' => 'external -> local' ];
		}

		if ( empty( $plan ) ) {
			return new ApplyResult(
				ApplyResult::NOOP,
				'external store already holds everything in the local store, and the local store every diarization bundle.'
			);
		}

		$count = count( $plan ) . ' entr' . ( 1 === count( $plan ) ? 'y' : 'ies' );

		if ( $dryRun ) {
			return new ApplyResult(
				ApplyResult::WOULD_APPLY,
				$count . ' to copy',
				self::EXTERNAL,
				$target,
				[],
				array_map(
					static fn( array $item ): string => 'would copy ' . $item['direction'] . ': ' . $item['relative'],
					$plan
				)
			);
		}

		$details = [];
		$failed  = 0;
		foreach ( $plan as $item ) {
			if ( $this->copyEntry( $item['from'], $item['to'] ) ) {
				$details[] = 'copied ' . $item['direction'] . ': ' . $item['relative'];
				continue;
			}

			$details[] = 'FAILED ' . $item['direction'] . ': ' . $item['relative'];
			$failed++;
		}

		return new ApplyResult(
			$failed > 0 ? ApplyResult::FAILED : ApplyResult::APPLIED,
			$failed > 0
				? $failed . ' of ' . count( $plan ) . ' entries failed to copy'
				: $count . ' copied',
			self::EXTERNAL,
			$target,
			[],
			$details
		);
	}

	/**
	 * Recursive additive diff: the shallowest entries present in $source and
	 * absent from $target. Descends only where both sides have a directory, so
	 * a whole new bundle is copied once rather than file by file.
	 *
	 * @return array<int, array{from: string, to: string, relative: string}>
	 */
	private function absentFrom( string $source, string $target, string $prefix = '' ): array {
		$plan    = [];
		$entries = @scandir( $source ) ?: [];

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.DS_Store' === $entry ) {
				continue;
			}

			$from     = $source . '/' . $entry;
			$to       = $target . '/' . $entry;
			$relative = ( '' === $prefix ? '' : $prefix . '/' ) . $entry;

			if ( ! file_exists( $to ) ) {
				$plan[] = [ 'from' => $from, 'to' => $to, 'relative' => $relative ];
				continue;
			}

			// Present on both sides: descend into directories, never touch files.
			if ( is_dir( $from ) && is_dir( $to ) ) {
				$plan = array_merge( $plan, $this->absentFrom( $from, $to, $relative ) );
			}
		}

		return $plan;
	}

	/**
	 * Copy one entry, then verify by file-count parity before declaring success.
	 * A partial copy is removed rather than left to masquerade as a real model.
	 */
	private function copyEntry( string $from, string $to ): bool {
		$parent = dirname( $to );
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return false;
		}

		exec( $this->copyCommand( $from, $to ) . ' 2>&1', $out, $code );
		if ( 0 !== $code ) {
			$this->removeTree( $to );

			return false;
		}

		if ( $this->countFiles( $from ) !== $this->countFiles( $to ) ) {
			$this->removeTree( $to );

			return false;
		}

		return true;
	}

	/**
	 * ditto preserves macOS metadata and resource forks; Linux has neither ditto
	 * nor a need for them, and this repo runs on both.
	 */
	public function copyCommand( string $from, string $to, ?string $osFamily = null ): string {
		$family = $osFamily ?: PHP_OS_FAMILY;

		return 'Darwin' === $family
			? 'ditto ' . escapeshellarg( $from ) . ' ' . escapeshellarg( $to )
			: 'cp -a ' . escapeshellarg( $from ) . ' ' . escapeshellarg( $to );
	}

	private function countFiles( string $path ): int {
		if ( is_file( $path ) ) {
			return 1;
		}

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$count = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$count++;
			}
		}

		return $count;
	}

	private function removeTree( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $path );
	}

	/**
	 * Which models each store holds, and which are reachable right now.
	 *
	 * A model is a directory containing at least one *.mlmodelc — NOT one
	 * containing config.json. The parakeet bundle has no config.json at all,
	 * while the registry catalogs under argmaxinc/ and the tokenizer stubs under
	 * <framework>/models/openai/ have nothing but json. Nesting depth differs
	 * between frameworks, so the rule cannot key off depth either.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function residency(): array {
		$active = $this->currentLocation();
		$rows   = [];

		foreach ( [ self::LOCAL, self::EXTERNAL ] as $location ) {
			$store = $this->storePath( $location );
			if ( ! is_dir( $store ) ) {
				continue;
			}

			foreach ( $this->modelsIn( $store ) as $key => $model ) {
				$rows[ $key ] ??= [
					'id'        => $this->modelId( $model ),
					'engine'    => $this->name(),
					'name'      => $model['name'],
					'framework' => $model['framework'],
					'kind'      => $model['kind'],
					'path'      => $model['relative'],
					'sizeMb'    => $model['sizeMb'],
					'local'     => false,
					'external'  => false,
					'available' => false,
				];

				$rows[ $key ][ $location ] = true;
				// Prefer the external store's size: it is the authoritative superset.
				if ( self::EXTERNAL === $location ) {
					$rows[ $key ]['sizeMb'] = $model['sizeMb'];
				}
				if ( $location === $active ) {
					$rows[ $key ]['available'] = true;
				}
			}
		}

		return array_values( $rows );
	}

	/**
	 * The ID `mw models list` prints for a bundle, which is what `aimodels why`
	 * keys notes by. `mw` only lists the active store, so the ID is derived from
	 * the bundle's path to cover the store that is not active (or not mounted).
	 * Qwen3-ASR's name is already the mw ID's (modelsIn() builds it from parts).
	 * Support bundles have no mw ID, so they take the engine's own prefix.
	 *
	 * @param array<string, mixed> $model
	 */
	private function modelId( array $model ): string {
		$relative = (string) $model['relative'];
		$name     = (string) $model['name'];

		if ( 'support' === $model['kind'] ) {
			return $this->name() . ':' . $name;
		}

		return match ( true ) {
			'whisper-cpp' === $model['framework']              => 'whisper-cpp:' . $name,
			str_ends_with( $relative, '/qwenasrkit-pro/qwen3-asr' ) => 'qwen3-asr:' . $name,
			str_contains( $relative, '/parakeetkit-pro/' )    => 'parakeet-pro:' . $name,
			default                                           => $model['framework'] . ':' . $name,
		};
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function modelsIn( string $store ): array {
		$found = [];

		// whisper-cpp models are plain top-level .bin files.
		foreach ( glob( $store . '/*.bin' ) ?: [] as $bin ) {
			$name           = preg_replace( '/\.bin$/', '', basename( $bin ) );
			$found[ $name ] = [
				'name'      => $name,
				'framework' => 'whisper-cpp',
				'kind'      => 'asr',
				'relative'  => basename( $bin ),
				'sizeMb'    => (int) round( ( filesize( $bin ) ?: 0 ) / 1048576 ),
			];
		}

		// CoreML bundles, at whatever depth their framework nests them.
		$parts = [];
		foreach ( $this->bundleDirs( $store ) as $dir ) {
			$relative = ltrim( substr( $dir, strlen( $store ) ), '/' );

			// Qwen3-ASR splits one model across …/qwen3-asr/<part>/<size>, and every
			// part's leaf is just the size ('1.7b'). Collected here, emitted below
			// as one model; keyed by leaf they would collide and keep one half.
			if ( preg_match( '#^(.*/qwenasrkit-pro/qwen3-asr)/[^/]+/([^/]+)$#', $relative, $match ) ) {
				$parts[ 'qwen3-asr-' . $match[2] ]['relative'] = $match[1];
				$parts[ 'qwen3-asr-' . $match[2] ]['bytes']    = ( $parts[ 'qwen3-asr-' . $match[2] ]['bytes'] ?? 0 ) + $this->treeSize( $dir );
				continue;
			}

			$name     = basename( $dir );
			$sizeMb   = (int) round( $this->treeSize( $dir ) / 1048576 );

			// Two directories in a store can share a bundle's name — the real one
			// and its download-cache stub. Keep the larger; a 0-byte stub must
			// never stand in for 1.2 GB of weights.
			if ( isset( $found[ $name ] ) && $found[ $name ]['sizeMb'] >= $sizeMb ) {
				continue;
			}

			$found[ $name ] = [
				'name'      => $name,
				'framework' => strtok( $relative, '/' ) ?: 'unknown',
				'kind'      => 'asr',
				'relative'  => $relative,
				'sizeMb'    => $sizeMb,
			];
		}

		foreach ( $parts as $name => $part ) {
			$found[ $name ] = [
				'name'      => $name,
				'framework' => strtok( $part['relative'], '/' ) ?: 'unknown',
				'kind'      => 'asr',
				'relative'  => $part['relative'],
				'sizeMb'    => (int) round( $part['bytes'] / 1048576 ),
			];
		}

		// Diarization is support, not a listed model, but it decides whether
		// --speakers works offline, so status must show whether it is present.
		foreach ( self::SUPPORT_BUNDLES as $bundle ) {
			if ( ! is_dir( $store . '/' . $bundle ) ) {
				continue;
			}

			$found[ $bundle ] = [
				'name'      => $bundle,
				'framework' => $bundle,
				'kind'      => 'support',
				'relative'  => $bundle,
				'sizeMb'    => (int) round( $this->treeSize( $store . '/' . $bundle ) / 1048576 ),
			];
		}

		return $found;
	}

	/**
	 * Directories holding at least one *.mlmodelc child.
	 *
	 * Two subtrees are excluded because both mirror the bundle shape without
	 * being models:
	 *
	 *   .cache/huggingface/download/<model>/  download scaffolding, often empty
	 *   speakerkit/..., speakerkit-pro/...    diarization internals (W8A16,
	 *                                         684_74MB etc), reported once per
	 *                                         bundle as a support row
	 *
	 * The cache exclusion is the important one — a local store with a large-v3
	 * download stub and none of its weights would otherwise be reported as
	 * holding large-v3.
	 *
	 * @return string[]
	 */
	private function bundleDirs( string $store ): array {
		$dirs  = [];
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $store, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $item ) {
			if ( ! $item->isDir() || '.mlmodelc' !== substr( $item->getFilename(), -9 ) ) {
				continue;
			}

			$bundle   = dirname( $item->getPathname() );
			$relative = ltrim( substr( $bundle, strlen( $store ) ), '/' );

			if ( str_contains( $relative, '/.cache/' ) || str_starts_with( $relative, '.cache/' ) ) {
				continue;
			}

			if ( in_array( strtok( $relative, '/' ), self::SUPPORT_BUNDLES, true ) ) {
				continue;
			}

			$dirs[ $bundle ] = true;
		}

		return array_keys( $dirs );
	}

	private function treeSize( string $path ): int {
		if ( ! is_dir( $path ) ) {
			return is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		$bytes = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$bytes += $item->getSize();
			}
		}

		return $bytes;
	}
}
