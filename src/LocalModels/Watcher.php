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
			];
		}

		return [
			'label'      => self::LABEL,
			'plist'      => $this->plistPath(),
			'installed'  => is_file( $this->plistPath() ),
			'legacy'     => is_file( $this->legacyPlistPath() ),
			'mounted'    => ( new Drive( $this->volumesRoot ) )->isMounted( 'AI-LAB' ),
			'engines'    => $engines,
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

		foreach ( $this->registry->engines() as $engine ) {
			$pre = $engine->preflight();
			if ( ! $pre->manageable ) {
				$results[ $engine->name() ] = new ApplyResult( ApplyResult::SKIPPED, $pre->reason );
				continue;
			}

			$results[ $engine->name() ] = $engine->apply(
				$engine instanceof AbstractStoreEngine ? $engine->locationForDrive( true ) : AbstractStoreEngine::LOCAL,
				$dryRun
			);
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
