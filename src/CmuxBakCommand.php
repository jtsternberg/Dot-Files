<?php
namespace JT;

use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

/**
 * The `$yes` and `$silent` parameters on the prompting verbs exist to declare
 * `--yes`/`-y` and `--silent`/`-shh` as part of those verbs' interface so the
 * dispatcher accepts them. Their bound values are deliberately not threaded into
 * the service: both are prompt-layer concerns which `Helpers::isAutoconfirm()`,
 * `Helpers::confirm()` and `Helpers::isSilent()` read straight back off the parsed
 * arguments. Drop a parameter and the flag it declares stops parsing — and an
 * unregistered `--silent` fails validation with an error message that
 * `--silent` itself suppresses, so the run ends silently with exit 1.
 *
 * Only `restore` declares `--silent`, because it is the only verb whose every
 * prompt has a default it can take unattended. `audit`'s resume prompt goes
 * through `confirm()`, which in silent mode prints nothing and still waits for an
 * answer — declaring the flag there would advertise a hang.
 */
#[Program(
	name: 'cmux-bak',
	description: 'Backup and restore cmux workspace/session state (Claude Code + codex).',
)]
final class CmuxBakCommand {

	/** @var callable(string,bool,bool):object */
	private $factory;

	public function __construct(
		private readonly Helpers $cli,
		?callable $factory = null,
	) {
		$this->factory = $factory ?: fn(
			string $file,
			bool $dryRun,
			bool $verbose
		): CmuxBak => new CmuxBak( $this->cli, $file, $dryRun, $verbose );
	}

	#[Command(
		description: 'Save the current cmux workspace and agent-session state.',
		default: true,
	)]
	public function backup(
		#[Option(
			description: 'Backup file path.',
			valueName: 'path',
		)]
		string $file = CmuxBak::BAK_DEFAULT,
		#[Option(
			name: 'dry-run',
			description: 'Show what would be done without making changes.',
		)]
		bool $dryRun = false,
		#[Option(description: 'Show detailed progress.')]
		bool $verbose = false
	): int {
		return $this->execute( 'backup', $file, $dryRun, $verbose );
	}

	#[Command(
		name: 'restore',
		description: 'Restore missing workspaces, agent sessions, and each workspace\'s recorded split geometry from the backup (panes come back as right-splits where no geometry was recorded).',
	)]
	public function restore(
		#[Option(
			description: 'Backup file path.',
			valueName: 'path',
		)]
		string $file = CmuxBak::BAK_DEFAULT,
		#[Option(
			name: 'dry-run',
			description: 'Show what would be done without making changes.',
		)]
		bool $dryRun = false,
		#[Option(description: 'Show detailed progress.')]
		bool $verbose = false,
		#[Option(
			name: 'yes',
			aliases: [ 'y' ],
			description: 'Answer restore\'s prompts without asking: recreate agent-less workspaces, and open a new surface for a session whose surface is gone (unless that session is already running somewhere in cmux).',
		)]
		bool $yes = false,
		#[Option(
			name: 'silent',
			aliases: [ 'shh' ],
			description: 'Suppress progress output and take every prompt\'s default (leave anything it would have asked about alone).',
		)]
		bool $silent = false
	): int {
		return $this->execute( 'restore', $file, $dryRun, $verbose );
	}

	#[Command(
		name: 'audit',
		description: 'Report backed-up agent sessions that are not running and offer to resume them.',
	)]
	public function audit(
		#[Option(
			description: 'Backup file path.',
			valueName: 'path',
		)]
		string $file = CmuxBak::BAK_DEFAULT,
		#[Option(
			name: 'dry-run',
			description: 'Show what would be done without making changes.',
		)]
		bool $dryRun = false,
		#[Option(description: 'Show detailed progress.')]
		bool $verbose = false,
		#[Option(
			name: 'yes',
			aliases: [ 'y' ],
			description: 'Resume the missing sessions without asking.',
		)]
		bool $yes = false
	): int {
		return $this->execute( 'audit', $file, $dryRun, $verbose );
	}

	private function execute(
		string $action,
		string $file,
		bool $dryRun,
		bool $verbose
	): int {
		try {
			$service = ( $this->factory )( $file, $dryRun, $verbose );

			return $service->{$action}();
		} catch ( \Exception $e ) {
			$this->cli->err( $e->getMessage() );

			return 1;
		}
	}
}
