<?php
namespace JT;

use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

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
		description: 'Restore missing workspaces, panes and agent sessions from the backup (extra panes come back as right-splits; cmux records no split geometry).',
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
		bool $verbose = false
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
		bool $verbose = false
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
