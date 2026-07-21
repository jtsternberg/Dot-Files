<?php
namespace JT;

use JT\CLI\Helpers;

/**
 * LinuxCatchup — the Linux box "catch everything up" orchestrator.
 *
 * Steps (each toggleable via ~/.linux-catchup.json):
 *   1. codex update
 *   2. claude update
 *   3. repos: `godo <key>` for each configured key (runs that key's stored
 *      commands, defaulting to `git prb`)
 *   4. system: refresh apt, report upgradable + security + reboot-required;
 *      with --apply, actually run the upgrade.
 *
 * This class holds the testable logic — config loading/defaults and parsing
 * apt output into a structured report. The bin/linux-catchup entry script does
 * the actual command execution (passthru), which isn't unit-tested.
 *
 * The system step is Linux-only; on other platforms it's reported as skipped
 * so the codex/claude/repo steps still work for development.
 */
class LinuxCatchup {

	protected Helpers $helpers;

	/** Absolute path to the JSON config. */
	public string $configPath = '';

	protected array $config = [];

	/** Command offered (to write into the map) when a repo has no godo commands. */
	const DEFAULT_REPO_COMMAND = Godo::DEFAULT_COMMAND;

	const DEFAULT_CONFIG = [
		'repos'  => [ 'claudeplugins', 'notes', 'dotfiles', 'wpengine-jtsternberg' ],
		'codex'  => true,
		'claude' => true,
		'system' => true,
	];

	public function __construct( Helpers $helpers ) {
		$this->helpers = $helpers;

		$override = getenv( 'LINUX_CATCHUP_CONFIG' );
		$home     = $override ? dirname( $override ) : ( getenv( 'HOME' ) ?: dirname( JT_DOTFILES_DIR ) );
		$this->configPath = $override ?: $home . '/.linux-catchup.json';

		$this->config = $this->loadConfig();
	}

	/**
	 * Load config, writing the default file on first run. Merges over defaults
	 * so a partial config still has every key.
	 */
	public function loadConfig(): array {
		if ( ! file_exists( $this->configPath ) ) {
			file_put_contents(
				$this->configPath,
				json_encode( self::DEFAULT_CONFIG, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
			);

			return self::DEFAULT_CONFIG;
		}

		$decoded = json_decode( (string) file_get_contents( $this->configPath ), true );
		if ( ! is_array( $decoded ) ) {
			return self::DEFAULT_CONFIG;
		}

		return array_merge( self::DEFAULT_CONFIG, $decoded );
	}

	public function config(): array {
		return $this->config;
	}

	public function repos(): array {
		return array_values( (array) ( $this->config['repos'] ?? [] ) );
	}

	public function wants( string $step ): bool {
		return ! empty( $this->config[ $step ] );
	}

	public function isLinux(): bool {
		return 'Linux' === PHP_OS_FAMILY;
	}

	/**
	 * Parse `apt list --upgradable` output into a structured report.
	 *
	 * Lines look like:
	 *   Listing... Done
	 *   vim/jammy-security 2:8.2 amd64 [upgradable from: 2:8.1]
	 *
	 * @param string $raw               Raw stdout from `apt list --upgradable`.
	 * @param bool   $rebootRequired     Whether /var/run/reboot-required exists.
	 * @return array{count:int, security:string[], packages:string[], reboot_required:bool}
	 */
	public function parseUpgradable( string $raw, bool $rebootRequired = false ): array {
		$packages = [];
		$security = [];

		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === stripos( $line, 'Listing' ) ) {
				continue;
			}

			if ( ! preg_match( '#^(\S+?)/(\S+)#', $line, $m ) ) {
				continue;
			}

			$pkg  = $m[1];
			$repo = $m[2];
			$packages[] = $pkg;

			// Debian/Ubuntu security updates come from a *-security suite.
			if ( false !== stripos( $repo, '-security' ) ) {
				$security[] = $pkg;
			}
		}

		return [
			'count'           => count( $packages ),
			'security'        => $security,
			'packages'        => $packages,
			'reboot_required' => $rebootRequired,
		];
	}
}
