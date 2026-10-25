<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'claudeping-install',
	description: 'Install, remove, or show the cron entry that pings `claude -p` cheaply at a given schedule (e.g. to start a usage-window clock early).',
)]
final class ClaudePingInstallCommand {

	private ?ClaudePing $claudePing;

	public function __construct(
		private readonly Helpers $cli,
		?ClaudePing $claudePing = null,
	) {
		$this->claudePing = $claudePing;
	}

	#[Command(
		description: 'Parse a schedule (plain English or a raw cron expression) via a cheap headless Claude call, and install it as the managed cron entry, replacing any previous one.',
		default: true,
	)]
	public function install(
		#[Argument(description: 'Schedule, e.g. "weekdays at 5:30am" or a raw "30 5 * * 2-5".')]
		string $text
	): int {
		$cron = $this->claudePing()->parseSchedule( $text );
		if ( null === $cron ) {
			$this->cli->err( sprintf( "\nCouldn't parse a cron schedule from: %s", $text ) );
			$this->cli->msg( "Try being more specific (e.g. \"weekdays at 5:30am\") or pass a raw cron expression.\n" );

			return 1;
		}

		$this->claudePing()->install( $cron );
		$this->cli->successMsg( sprintf(
			"\nInstalled cron schedule: %s\n  -> %s\n",
			$cron,
			$this->claudePing()->describeCronExpression( $cron )
		) );

		return 0;
	}

	#[Command(
		name: 'remove',
		description: 'Remove the managed cron entry, if any.',
	)]
	public function removeEntry(): int {
		if ( $this->claudePing()->remove() ) {
			$this->cli->successMsg( "\nRemoved the claudeping cron entry.\n" );
		} else {
			$this->cli->msg( "\nNo claudeping cron entry was installed.\n", 'yellow' );
		}

		return 0;
	}

	#[Command(
		name: 'list',
		description: 'Show the currently installed cron entry, if any.',
	)]
	public function showEntry(): int {
		$line = $this->claudePing()->managedLine();
		if ( null === $line ) {
			$this->cli->msg( "\nNo claudeping cron entry is installed.\n", 'yellow' );

			return 0;
		}

		$this->cli->output( $line );
		$this->cli->output( '  -> ' . $this->claudePing()->describeCronExpression(
			$this->claudePing()->cronFieldsFromLine( $line )
		) );

		return 0;
	}

	private function claudePing(): ClaudePing {
		if ( null === $this->claudePing ) {
			$this->claudePing = new ClaudePing( $this->cli );
		}

		return $this->claudePing;
	}
}
