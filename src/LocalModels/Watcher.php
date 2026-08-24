<?php

namespace JT\LocalModels;

/**
 * The single LaunchAgent that keeps every model store following the AI-LAB drive.
 *
 * launchd's WatchPaths on /Volumes fires on any volume change — mount and eject
 * alike, since there is no mount-specific trigger — so the job simply recomputes
 * the correct location and asks each engine to apply it. Every apply is
 * idempotent, which is what makes that bluntness safe.
 *
 * This replaces com.jt.ollamodels-watcher, which drove Ollama alone. Cutover
 * installs this agent first and retires the legacy one after, so a crash between
 * the two leaves auto-switching working rather than off.
 */
final class Watcher {

	public const LABEL        = 'com.jt.aimodels-watcher';
	public const LEGACY_LABEL = 'com.jt.ollamodels-watcher';

	private string $home;
	private EngineRegistry $registry;

	public function __construct(
		?string $home = null,
		private readonly string $volumesRoot = '/Volumes',
		?EngineRegistry $registry = null,
		private readonly ?string $phpBin = null,
		private readonly ?string $binPath = null
	) {
		$this->home     = rtrim( $home ?: (string) getenv( 'HOME' ), '/' );
		$this->registry = $registry ?: new EngineRegistry( $this->home, $this->volumesRoot );
	}

	public function plistPath(): string {
		return $this->home . '/Library/LaunchAgents/' . self::LABEL . '.plist';
	}

	public function legacyPlistPath(): string {
		return $this->home . '/Library/LaunchAgents/' . self::LEGACY_LABEL . '.plist';
	}

	/**
	 * A version-stable php, never the versioned Cellar path the legacy agent
	 * hard-coded — a brew upgrade silently breaks that one.
	 */
	public function phpBinary(): string {
		if ( null !== $this->phpBin ) {
			return $this->phpBin;
		}

		foreach ( [ '/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php' ] as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return PHP_BINARY;
	}

	public function commandPath(): string {
		return $this->binPath ?: dirname( __DIR__, 2 ) . '/bin/aimodels';
	}

	/**
	 * Where the audit trail goes.
	 *
	 * A launchd job's stdout goes nowhere by default, so without this there is no
	 * record of what the watcher decided on any given /Volumes event — and the
	 * decisions look inexplicable after the fact (a failed eject bumps /Volumes,
	 * the drive is still mounted, so the watcher correctly puts both stores back
	 * on external, which reads as "my flip didn't stick").
	 */
	public function logPath(): string {
		return 'Darwin' === PHP_OS_FAMILY
			? $this->home . '/Library/Logs/aimodels-watcher.log'
			: $this->home . '/.local/state/aimodels-watcher.log';
	}

	/**
	 * @param array<string, string|bool> $fields
	 */
	private function log( array $fields ): void {
		$path   = $this->logPath();
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return;
		}

		$this->trim( $path );

		$line = date( 'c' );
		foreach ( $fields as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$value = (string) $value;
			$line .= ' ' . $key . '=' . ( str_contains( $value, ' ' ) ? '"' . str_replace( '"', "'", $value ) . '"' : $value );
		}

		@file_put_contents( $path, $line . "\n", FILE_APPEND );
	}

	/**
	 * Keep the newest half when the log passes the cap. It appends on every
	 * /Volumes event for the life of the machine, so it cannot grow unbounded.
	 */
	private function trim( string $path, int $maxBytes = 262144 ): void {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || filesize( $path ) <= $maxBytes ) {
			return;
		}

		$lines = @file( $path, FILE_IGNORE_NEW_LINES ) ?: [];
		$keep  = array_slice( $lines, (int) floor( count( $lines ) / 2 ) );
		@file_put_contents( $path, implode( "\n", $keep ) . "\n" );
	}

	/**
	 * The tail of the log, newest last.
	 *
	 * @return string[]
	 */
	public function recentLog( int $lines = 8 ): array {
		$path = $this->logPath();
		if ( ! is_file( $path ) ) {
			return [];
		}

		$all = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [];

		return array_slice( $all, -max( 1, $lines ) );
	}

	public function render(): string {
		$php  = htmlspecialchars( $this->phpBinary(), ENT_XML1 );
		$bin  = htmlspecialchars( $this->commandPath(), ENT_XML1 );
		$args = [ $php, $bin, 'watch', 'apply', '--silent' ];

		$argXml = '';
		foreach ( $args as $arg ) {
			$argXml .= "\t\t<string>{$arg}</string>\n";
		}

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key>
	<string>{$this->label()}</string>
	<key>WatchPaths</key>
	<array>
		<string>/Volumes</string>
	</array>
	<key>ProgramArguments</key>
	<array>
{$argXml}	</array>
</dict>
</plist>

XML;
	}

	public function label(): string {
		return self::LABEL;
	}

	/** Next to the log, so the pair is discoverable together. */
	public function statePath(): string {
		return dirname( $this->logPath() ) . '/aimodels-watcher.state';
	}

	/**
	 * Everything this job actually cares about, in one comparable string.
	 *
	 * The drive's presence AND each engine's current symlink target: including the
	 * targets means a store that drifted — a manual flip, a run that failed — is
	 * still corrected instead of being debounced away as "nothing changed".
	 *
	 * Deliberately does not settle: this must stay cheap enough to run on a firing
	 * that turns out to be noise.
	 */
	public function fingerprint(): string {
		$parts = [ 'ailab=' . ( ( new Drive( $this->volumesRoot ) )->isMounted( 'AI-LAB' ) ? '1' : '0' ) ];

		foreach ( $this->registry->engines() as $engine ) {
			$parts[] = $engine->name() . '=' . ( Flip::currentTarget( $engine->symlinkPath() ) ?? 'none' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * The watcher's entry point: do the work only if something moved.
	 *
	 * launchd fires this on every /Volumes change, and WatchPaths can get stuck
	 * re-triggering — observed live at its 10s throttle floor for minutes with a
	 * stable /Volumes and no mount activity, cleared by reloading the agent. The
	 * job cannot stop launchd firing, so it makes each firing nearly free instead.
	 *
	 * @return array<string, ApplyResult> empty when the firing was noise
	 */
	public function applyIfChanged( ?int $now = null ): array {
		$now         = $now ?? time();
		$fingerprint = $this->fingerprint();
		$state       = $this->readState();

		if ( ( $state['fingerprint'] ?? null ) === $fingerprint ) {
			$this->recordSuppressed( $state, $fingerprint, $now );

			return [];
		}

		$results = $this->applyAll();
		$this->writeState( [
			'fingerprint' => $this->fingerprint(),
			'suppressed'  => 0,
			'loggedAt'    => $now,
		] );

		return $results;
	}

	/**
	 * Count a noise firing, and summarise the run of them at most once a window,
	 * so the log stays the readable thing it exists to be.
	 *
	 * @param array<string, mixed> $state
	 */
	private function recordSuppressed( array $state, string $fingerprint, int $now, int $window = 900 ): void {
		$suppressed = (int) ( $state['suppressed'] ?? 0 ) + 1;
		$loggedAt   = (int) ( $state['loggedAt'] ?? $now );

		if ( $now - $loggedAt < $window ) {
			$this->writeState( [
				'fingerprint' => $fingerprint,
				'suppressed'  => $suppressed,
				'loggedAt'    => $loggedAt,
			] );

			return;
		}

		$fields = [
			'status'     => 'unchanged-debounced',
			'suppressed' => (string) $suppressed,
			'window'     => $window . 's',
		];

		// Roughly one firing every 10s or faster is the stuck-WatchPaths signature,
		// not normal volume activity. Name the remedy in the log itself.
		if ( $suppressed >= 30 ) {
			$fields['hint'] = 'runaway WatchPaths retrigger — `aimodels watch reload` clears it';
		}

		$this->log( $fields );
		$this->writeState( [
			'fingerprint' => $fingerprint,
			'suppressed'  => 0,
			'loggedAt'    => $now,
		] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readState(): array {
		$raw = is_file( $this->statePath() ) ? (string) file_get_contents( $this->statePath() ) : '';
		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function writeState( array $state ): void {
		$parent = dirname( $this->statePath() );
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return;
		}

		@file_put_contents( $this->statePath(), (string) json_encode( $state ) );
	}

	/**
	 * Unload and load the agent, which is what clears a stuck WatchPaths event.
	 */
	public function reload(): ApplyResult {
		if ( ! is_file( $this->plistPath() ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'watcher not installed; nothing to reload.' );
		}

		$this->launchctl( 'unload', $this->plistPath() );
		[ $out, $code ] = $this->launchctl( 'load', $this->plistPath() );
		if ( 0 !== $code ) {
			return new ApplyResult( ApplyResult::FAILED, 'launchctl load failed: ' . $out );
		}

		$this->log( [ 'event' => 'reload', 'plist' => $this->plistPath() ] );

		return new ApplyResult( ApplyResult::APPLIED, 'watcher reloaded (clears a stuck WatchPaths retrigger).' );
	}

	/**
	 * Install (or reinstall) the agent, retire the legacy one, then apply once so
	 * the stores — and Ollama's launchd OLLAMA_MODELS — are correct immediately.
	 */
	public function install(): ApplyResult {
		$dir = dirname( $this->plistPath() );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'could not create ' . $dir );
		}

		if ( false === file_put_contents( $this->plistPath(), $this->render() ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'could not write ' . $this->plistPath() );
		}

		$this->launchctl( 'unload', $this->plistPath() );
		[ $out, $code ] = $this->launchctl( 'load', $this->plistPath() );
		if ( 0 !== $code ) {
			return new ApplyResult( ApplyResult::FAILED, 'launchctl load failed: ' . $out );
		}

		$warnings = $this->retireLegacy();
		$this->log( [ 'event' => 'install', 'plist' => $this->plistPath() ] );
		$this->applyAll();

		return ( new ApplyResult(
			ApplyResult::APPLIED,
			'watcher installed: ' . $this->plistPath()
		) )->withWarnings( $warnings );
	}

	public function remove(): ApplyResult {
		if ( ! is_file( $this->plistPath() ) ) {
			return new ApplyResult( ApplyResult::NOOP, 'watcher not installed.' );
		}

		$this->launchctl( 'unload', $this->plistPath() );
		@unlink( $this->plistPath() );
		$this->log( [ 'event' => 'remove', 'plist' => $this->plistPath() ] );

		return new ApplyResult( ApplyResult::APPLIED, 'watcher removed.' );
	}

	/**
	 * @return string[] warnings
	 */
	private function retireLegacy(): array {
		$legacy = $this->legacyPlistPath();
		if ( ! is_file( $legacy ) ) {
			return [];
		}

		$this->launchctl( 'unload', $legacy );
		if ( ! @unlink( $legacy ) ) {
			return [ 'could not remove the legacy agent: ' . $legacy ];
		}

		return [ 'retired the legacy ' . self::LEGACY_LABEL . ' agent (it drove Ollama only).' ];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function status(): array {
		$engines = [];
		foreach ( $this->registry->engines() as $engine ) {
			$pre               = $engine->preflight();
			$engines[ $engine->name() ] = [
				'label'      => $engine->label(),
				'manageable' => $pre->manageable,
				'reason'     => $pre->reason,
				'location'   => $engine->currentLocation(),
				'symlink'    => $engine->symlinkPath(),
				'stores'     => [
					'local'    => $engine->storePath( AbstractStoreEngine::LOCAL ),
					'external' => $engine->storePath( AbstractStoreEngine::EXTERNAL ),
				],
				// One accessor, so text, --json and any later view show the same
				// rows; sharing only a renderer is what lets views drift.
				'models'     => $engine->residency(),
			];
		}

		return [
			'label'      => self::LABEL,
			'plist'      => $this->plistPath(),
			'installed'  => is_file( $this->plistPath() ),
			'legacy'     => is_file( $this->legacyPlistPath() ),
			'mounted'    => ( new Drive( $this->volumesRoot ) )->isMounted( 'AI-LAB' ),
			'engines'    => $engines,
			'log'        => $this->logPath(),
			'recent'     => $this->recentLog(),
		];
	}

	/**
	 * Drive every engine to the location the drive implies. Engines that are not
	 * yet manageable are skipped, never mutated — that self-gate is what lets the
	 * agent ship before a store has been built.
	 *
	 * @return array<string, ApplyResult>
	 */
	public function applyAll( bool $dryRun = false ): array {
		$lock    = $this->lock();
		$results = [];
		$mounted = ( new Drive( $this->volumesRoot ) )->settle( 'AI-LAB' );

		foreach ( $this->registry->engines() as $engine ) {
			$pre = $engine->preflight();
			if ( ! $pre->manageable ) {
				$results[ $engine->name() ] = new ApplyResult( ApplyResult::SKIPPED, $pre->reason );
				$this->log( [
					'mounted' => $mounted,
					'engine'  => $engine->name(),
					'status'  => ApplyResult::SKIPPED,
					'reason'  => $pre->reason,
				] );
				continue;
			}

			$location = $mounted ? AbstractStoreEngine::EXTERNAL : AbstractStoreEngine::LOCAL;
			$result   = $engine->apply( $location, $dryRun );

			$results[ $engine->name() ] = $result;
			$fields = [
				'mounted'  => $mounted,
				'engine'   => $engine->name(),
				'status'   => $result->status,
				'location' => $location,
				'msg'      => $result->message,
			];
			// Warnings carry the side effects — an automatic app restart, a skipped
			// one, a launchctl failure. Unlogged, they happen invisibly.
			if ( ! empty( $result->warnings ) ) {
				$fields['warn'] = implode( ' | ', $result->warnings );
			}
			$this->log( $fields );
		}

		$this->unlock( $lock );

		return $results;
	}

	/**
	 * Serialise concurrent runs: launchd can fire several /Volumes events at once.
	 *
	 * @return resource|null
	 */
	private function lock() {
		$handle = @fopen( sys_get_temp_dir() . '/aimodels-watcher.lock', 'c' );
		if ( ! $handle ) {
			return null;
		}

		flock( $handle, LOCK_EX );

		return $handle;
	}

	/**
	 * @param resource|null $handle
	 */
	private function unlock( $handle ): void {
		if ( $handle ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function launchctl( string $verb, string $plist ): array {
		$bin = getenv( 'AIMODELS_LAUNCHCTL_BIN' ) ?: 'launchctl';
		exec(
			escapeshellarg( $bin ) . ' ' . escapeshellarg( $verb ) . ' ' . escapeshellarg( $plist ) . ' 2>&1',
			$out,
			$code
		);

		return [ implode( "\n", $out ), (int) $code ];
	}
}
