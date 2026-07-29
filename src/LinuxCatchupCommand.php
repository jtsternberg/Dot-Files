<?php
namespace JT;

use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'linux-catchup',
	description: 'Catch this Linux box up: update codex + claude, pull configured repos (via godo), and report/apply system (apt) updates.',
)]
final class LinuxCatchupCommand {

	/** @var callable(string):int */
	private $runner;

	/** @var callable(string):string */
	private $shellOutput;

	/** @var callable(string):bool */
	private $fileExists;

	/** @var callable(string):bool */
	private $commandExists;

	public function __construct(
		private readonly Helpers $cli,
		private ?LinuxCatchup $catchup = null,
		private ?Godo $godo = null,
		?callable $runner = null,
		?callable $shellOutput = null,
		?callable $fileExists = null,
		?callable $commandExists = null,
	) {
		$this->runner = $runner ?: static function ( string $command ): int {
			passthru( $command, $code );

			return $code;
		};
		$this->shellOutput = $shellOutput ?: static fn( string $command ): string =>
			(string) shell_exec( $command );
		$this->fileExists = $fileExists ?: static fn( string $path ): bool => file_exists( $path );
		$this->commandExists = $commandExists ?: function ( string $command ): bool {
			return '' !== trim( ( $this->shellOutput )(
				'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null'
			) );
		};
	}

	#[Command(
		description: 'Run the enabled catch-up steps.',
		default: true,
	)]
	public function run(
		#[Option(description: 'Apply system (apt) updates, not just report them.')]
		bool $apply = false,
		#[Option(
			aliases: [ 'y' ],
			description: 'Assume yes for prompts (map-seed offers, apt -y).',
		)]
		bool $yes = false,
		#[Option(
			description: 'Run only these comma-separated steps: codex, claude, repos, system (alias system-update).',
			valueName: 'steps',
		)]
		?string $only = null
	): int {
		if ( ! $this->validateOnly( $only ) ) {
			return 1;
		}

		$catchup = $this->catchup();
		$green   = $this->cli->color( 'green' );
		$reset   = $this->cli->color( 'none' );

		if ( $catchup->shouldRun( 'codex', $only ) ) {
			$this->section( 'codex update' );
			$this->runToolUpdate( 'codex' );
		}

		if ( $catchup->shouldRun( 'claude', $only ) ) {
			$this->section( 'claude update' );
			$this->runToolUpdate( 'claude' );
		}

		$repos = $catchup->repos();
		if ( $catchup->shouldRun( 'repos', $only ) ) {
			$this->section( sprintf( 'repos (%d)', count( $repos ) ) );
			foreach ( $repos as $key ) {
				$this->cli->msg( sprintf( "\n%s• %s%s", $green, $key, $reset ) );

				if ( '' === $this->godo()->resolvePath( $key ) ) {
					$this->cli->err( sprintf(
						"  No dirmap entry for '%s' — add it: dirmap add %s <path>",
						$key,
						$key
					) );
					continue;
				}

				if ( empty( $this->godo()->getStoredCommands( $key ) ) ) {
					$this->cli->err( sprintf( "  No godo commands set for '%s'.", $key ) );
					if ( $this->cli->confirm( sprintf(
						"  Add '%s' to the map for '%s'?",
						LinuxCatchup::DEFAULT_REPO_COMMAND,
						$key
					) ) ) {
						$this->godo()->appendCommand( $key, LinuxCatchup::DEFAULT_REPO_COMMAND );
						$this->cli->msg( sprintf(
							'  Added to map: %s => %s',
							$key,
							LinuxCatchup::DEFAULT_REPO_COMMAND
						), 'green' );
					} else {
						$this->cli->msg( sprintf(
							"  Skipped %s — set commands with: godo addcmd %s '<command>'",
							$key,
							$key
						), 'yellow' );
						continue;
					}
				}

				$this->runCommand(
					escapeshellarg( dirname( __DIR__ ) . '/bin/godo' ) . ' ' . escapeshellarg( $key )
				);
			}
		} elseif ( null !== ( $reposHint = $catchup->reposEmptyHint( $only ) ) ) {
			$this->section( 'repos (0)' );
			$this->cli->msg( '  ' . $reposHint, 'yellow' );
		}

		if ( $catchup->shouldRun( 'system', $only ) ) {
			$code = $this->runSystemUpdates( $apply, $yes );
			if ( 0 !== $code ) {
				return $code;
			}
		}

		$this->cli->msg( sprintf( "\n%sCaught up.%s\n", $green, $reset ) );

		return 0;
	}

	#[Command(
		name: 'config',
		description: 'Print the resolved config and where it lives.',
	)]
	public function config(): int {
		$catchup = $this->catchup();
		$this->cli->msg( sprintf( "\nConfig: %s\n", $catchup->configPath ), 'cyan' );
		$this->cli->msg( json_encode(
			$catchup->config(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) );

		return 0;
	}

	private function validateOnly( ?string $only ): bool {
		if ( null === $only ) {
			return true;
		}

		[ $known, $unknown ] = $this->catchup()->parseOnly( $only );
		if ( ! empty( $unknown ) ) {
			$this->cli->err( sprintf(
				"\nUnknown --only step(s): %s",
				implode( ', ', $unknown )
			) );
			$this->cli->msg( sprintf(
				"Valid steps: %s (alias: system-update).\n",
				implode( ', ', LinuxCatchup::STEPS )
			) );

			return false;
		}

		if ( empty( $known ) ) {
			$this->cli->err( "\n--only needs at least one step, e.g. --only=system\n" );

			return false;
		}

		return true;
	}

	private function runToolUpdate( string $tool ): void {
		if ( ( $this->commandExists )( $tool ) ) {
			$this->runCommand( $tool . ' update' );

			return;
		}

		$this->cli->msg( sprintf( '  %s not found on PATH — skipping.', $tool ), 'yellow' );
	}

	private function runSystemUpdates( bool $apply, bool $yes ): int {
		$this->section( 'system updates' );
		$catchup = $this->catchup();
		if ( ! $catchup->isLinux() ) {
			$this->cli->msg( sprintf(
				'  skipped: system step is Linux-only (this is %s).',
				PHP_OS_FAMILY
			), 'yellow' );

			return 0;
		}

		$this->cli->msg( '  refreshing package lists (sudo apt-get update)…' );
		( $this->runner )( 'sudo apt-get update -qq' );

		$raw = ( $this->shellOutput )( 'apt list --upgradable 2>/dev/null' );
		$report = $catchup->parseUpgradable(
			$raw,
			( $this->fileExists )( '/var/run/reboot-required' )
		);
		$reset = $this->cli->color( 'none' );

		if ( 0 === $report['count'] ) {
			$this->cli->msg( '  system is up to date. ✔', 'green' );
		} else {
			$this->cli->msg( sprintf(
				"\n  %d package(s) upgradable:",
				$report['count']
			), 'yellow' );
			foreach ( $report['packages'] as $package ) {
				$isSecurity = in_array( $package, $report['security'], true );
				$this->cli->msg( sprintf(
					'    %s%s%s',
					$isSecurity ? $this->cli->color( 'red' ) : '',
					$package . ( $isSecurity ? '  [security]' : '' ),
					$reset
				) );
			}

			if ( ! empty( $report['security'] ) ) {
				$this->cli->msg( sprintf(
					"\n  ⚠ %d security update(s) pending.",
					count( $report['security'] )
				), 'red' );
			}
		}

		if ( $report['reboot_required'] ) {
			$this->cli->msg(
				"\n  ⚠ reboot required (/var/run/reboot-required present).",
				'red'
			);
		}

		if ( $apply && $report['count'] > 0 ) {
			$this->cli->msg( "\n  applying updates (sudo apt-get upgrade)…", 'cyan' );
			$code = ( $this->runner )(
				'sudo apt-get upgrade ' . ( $yes ? '-y ' : '' ) . '2>&1'
			);
			if ( 0 !== $code ) {
				$this->cli->err( sprintf(
					"\n  apt-get upgrade exited %d.\n",
					$code
				), false );

				return $code;
			}
		} elseif ( $report['count'] > 0 ) {
			$this->cli->msg(
				"\n  (report-only — re-run with --apply to install: `linux-catchup --only=system --apply`)",
				'yellow'
			);
		}

		return 0;
	}

	private function section( string $title ): void {
		$this->cli->msg( sprintf(
			"\n%s══ %s ══%s",
			$this->cli->color( 'cyan' ),
			$title,
			$this->cli->color( 'none' )
		) );
	}

	private function runCommand( string $command ): int {
		$this->cli->msg( sprintf(
			'  %s➜ %s%s',
			$this->cli->color( 'dark_gray' ),
			$command,
			$this->cli->color( 'none' )
		) );

		return ( $this->runner )( $command );
	}

	private function catchup(): LinuxCatchup {
		if ( null === $this->catchup ) {
			$this->catchup = new LinuxCatchup( $this->cli );
		}

		return $this->catchup;
	}

	private function godo(): Godo {
		if ( null === $this->godo ) {
			$this->godo = new Godo( $this->cli );
		}

		return $this->godo;
	}
}
