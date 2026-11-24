<?php
namespace JT\Tests\CmuxBak;

use JT\CmuxBak;
use JT\CLI\Helpers;
use JT\Helpers\Cmux;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CmuxBakExitInterceptHelpers extends Helpers {

	public function __construct() {
		parent::__construct();
	}

	public function exitErr( $args, $code = 1, $lineBreak = true ) {
		throw new \RuntimeException( 'exitErr called: ' . $args );
	}
}

final class CmuxBakOperationalCmux extends Cmux {

	public bool $reachable = true;
	public int $treeCalls = 0;
	public array $treeData = [ 'windows' => [] ];
	public array $debugData = [];
	public array $claudeRows = [];
	public array $codexRows = [];
	public array $sent = [];
	public array $resumeArguments = [];
	public string $screen = '';

	/** Build the real resume command instead of the "resume <agent> <id>" marker. */
	public bool $realResume = false;

	public function __construct( Helpers $cli ) {
		parent::__construct( $cli, true );
	}

	public function ping(): bool {
		return $this->reachable;
	}

	public function tree(): array {
		$this->treeCalls++;

		return $this->treeData;
	}

	public function debugTerminals(): string {
		return '';
	}

	public function parseDebugTerminals( string $raw ): array {
		return $this->debugData;
	}

	public function loadClaudeSessionsByPid(): array {
		return [];
	}

	public function psProcTable(): string {
		return '';
	}

	public function parseProcTable( string $raw ): array {
		return [];
	}

	public function joinSessionsToSurfaces( array $sessions, array $proc, array $debug, array $surfaceUuids = [] ): array {
		return $this->claudeRows;
	}

	public function loadCodexSessionsByPid(): array {
		return [];
	}

	public function mapSurfaceUuids( array $tree ): array {
		return [];
	}

	public function joinCodexToSurfaces( array $codexSessions, array $surfaceUuids ): array {
		return $this->codexRows;
	}

	public function buildAgentResumeCommand(
		string $agent,
		string $sessionId,
		bool $skipPerms = false,
		?string $model = null,
		array $opts = []
	): string {
		$this->resumeArguments[] = [ $agent, $sessionId, $skipPerms, $model, $opts ];

		if ( $this->realResume ) {
			return parent::buildAgentResumeCommand( $agent, $sessionId, $skipPerms, $model, $opts );
		}

		return "resume {$agent} {$sessionId}";
	}

	public function sendToSurface( string $surfRef, string $wsRef, string $text ): void {
		$this->sent[] = [ $surfRef, $wsRef, $text ];
	}

	public function readScreen( string $surfRef, string $wsRef ): string {
		return $this->screen;
	}
}

final class CmuxBakOperationsTest extends TestCase {

	/** @var string[] */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ( $this->temporaryFiles as $file ) {
			@unlink( $file );
		}

		parent::tearDown();
	}

	public function testConstructionUsesExplicitConfigurationAndInjectedCmux(): void {
		$this->cli->setArgs( [
			'cmux-bak',
			'--file=/tmp/legacy-cli-value.json',
			'--dry-run',
			'--verbose',
		] );
		$file = sys_get_temp_dir() . '/cmux-bak-explicit-' . uniqid() . '.json';
		$cmux = new Cmux( $this->cli );
		$bak  = new CmuxBak( $this->cli, $file, false, false, $cmux );

		$this->assertSame( $file, $this->property( $bak, 'bakFile' ) );
		$this->assertFalse( $this->property( $bak, 'dryRun' ) );
		$this->assertFalse( $this->property( $bak, 'verbose' ) );
		$this->assertSame( $cmux, $this->property( $bak, 'cmux' ) );
	}

	public function testCodexTrustPromptIsReportedWithoutSendingConfirmation(): void {
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->screen = 'Do you trust the contents of this directory?  1. Yes, continue  2. No, quit';
		$bak = new CmuxBak( $this->cli, sys_get_temp_dir() . '/cmux-bak-trust-' . uniqid() . '.json', false, false, $cmux );

		$method = new \ReflectionMethod( CmuxBak::class, 'warnIfCodexTrustPrompt' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( $bak, 'codex', 'surface:1', 'workspace:1' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'awaiting trust confirmation', $output );
		$this->assertSame( [], $cmux->sent );
	}

	public function testConstructionLeavesOperationalCmuxLazy(): void {
		$file = $this->temporaryFile( 'lazy' );
		$bak  = new CmuxBak( $this->cli, $file );

		$this->assertNull( $this->property( $bak, 'cmux' ) );
		$this->assertFileDoesNotExist( $file );
	}

	public function testBackupWritesTheCurrentSchemaThroughInjectedCmuxSeams(): void {
		$file = $this->temporaryFile( 'backup' );
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->treeData = [
			'windows' => [
				[
					'workspaces' => [
						[
							'title' => 'dotfiles',
							'ref'   => 'workspace:1',
							'panes' => [
								[
									'ref'      => 'pane:1',
									'index'    => 0,
									'surfaces' => [
										[
											'ref'           => 'surface:1',
											'id'            => 'surface-id-1',
											'title'         => 'Codex',
											'type'          => 'terminal',
											'tty'           => 'ttys001',
											'index_in_pane' => 0,
										],
									],
								],
							],
						],
					],
				],
			],
		];
		$cmux->codexRows = [
			[
				'agent'       => 'codex',
				'session_id'  => '019fa599-6b5f-7de1-9822-52643135bb95',
				'surface_ref' => 'surface:1',
				'cwd'         => '/tmp/project',
				'model'       => 'gpt-5.6-terra',
				'skip_perms'  => false,
				'opts'        => [ 'sandbox' => 'read-only' ],
			],
		];
		$bak = new CmuxBak( $this->cli, $file, false, false, $cmux );

		ob_start();
		$code = $bak->backup();
		ob_end_clean();

		$data    = json_decode( (string) file_get_contents( $file ), true );
		$surface = $data['workspaces'][0]['panes'][0]['surfaces'][0];

		$this->assertSame( 0, $code );
		$this->assertSame( 3, $data['version'] );
		$this->assertSame( 'dotfiles', $data['workspaces'][0]['title'] );
		$this->assertSame( 'codex', $surface['agent'] );
		$this->assertSame( '019fa599-6b5f-7de1-9822-52643135bb95', $surface['agent_session_id'] );
		$this->assertSame( [ 'sandbox' => 'read-only' ], $surface['agent_opts'] );
	}

	public function testSecondAccountClaudeSessionRoundTripsToAResumeUnderItsConfigDir(): void {
		$file = $this->temporaryFile( 'alt-account' );
		$cwd  = $this->graveyardRoot;
		$alt  = '/Users/someone/.claude-alt';
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->realResume = true;
		$cmux->treeData   = [
			'windows' => [
				[
					'workspaces' => [
						[
							'title' => 'dotfiles',
							'ref'   => 'workspace:1',
							'panes' => [
								[
									'ref'      => 'pane:1',
									'index'    => 0,
									'surfaces' => [
										[
											'ref'           => 'surface:1',
											'id'            => 'surface-id-1',
											'title'         => 'Agent',
											'type'          => 'terminal',
											'tty'           => 'ttys001',
											'index_in_pane' => 0,
										],
									],
								],
							],
						],
					],
				],
			],
		];
		$cmux->claudeRows = [
			[
				'agent'       => 'claude',
				'session_id'  => 'deba7795-bcde-416e-9d57-e38b31509eb3',
				'surface_ref' => 'surface:1',
				'cwd'         => $cwd,
				'model'       => null,
				'skip_perms'  => true,
				'opts'        => [ 'config_dir' => $alt ],
			],
		];

		ob_start();
		$backupCode = ( new CmuxBak( $this->cli, $file, false, false, $cmux ) )->backup();
		// The session is gone by restore time; only the backup knows about it.
		$cmux->claudeRows = [];
		$restoreCode = ( new CmuxBak( $this->cli, $file, true, false, $cmux ) )->restore();
		ob_end_clean();

		$this->assertSame( 0, $backupCode );
		$this->assertSame( 0, $restoreCode );
		$this->assertSame(
			[
				'surface:1',
				'workspace:1',
				'CLAUDE_CONFIG_DIR=' . escapeshellarg( $alt ) . " claude --dangerously-skip-permissions --resume deba7795-bcde-416e-9d57-e38b31509eb3\n",
			],
			end( $cmux->sent )
		);
	}

	/** @return array{0:int,1:int,2:array,3:array} backup code, restore code, backed-up surface, last send */
	private function codexRoundTrip( string $name, array $codexRow ): array {
		$file = $this->temporaryFile( $name );
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->realResume = true;
		$cmux->treeData   = $this->singleSurfaceTree( 'Codex' );
		$cmux->codexRows  = [ $codexRow + [
			'agent'       => 'codex',
			'session_id'  => '01a20e0c-6080-7ed0-af9e-e7e378679e85',
			'surface_ref' => 'surface:1',
			'cwd'         => $this->graveyardRoot,
			'model'       => 'gpt-5.6-terra',
			'skip_perms'  => false,
			'opts'        => [ 'sandbox' => 'read-only' ],
		] ];

		ob_start();
		$backupCode = ( new CmuxBak( $this->cli, $file, false, false, $cmux ) )->backup();
		$cmux->codexRows = [];
		$restoreCode = ( new CmuxBak( $this->cli, $file, true, false, $cmux ) )->restore();
		ob_end_clean();

		$data = json_decode( (string) file_get_contents( $file ), true );

		return [ $backupCode, $restoreCode, $data['workspaces'][0]['panes'][0]['surfaces'][0], end( $cmux->sent ) ];
	}

	public function testNonDefaultCodexHomeRoundTripsToAResumeUnderThatHome(): void {
		$home = '/Users/someone/.codex-work';
		[ $backupCode, $restoreCode, $surface, $sent ] = $this->codexRoundTrip( 'codex-work', [ 'codex_home' => $home ] );

		$this->assertSame( 0, $backupCode );
		$this->assertSame( 0, $restoreCode );
		$this->assertSame( $home, $surface['agent_codex_home'] );
		$this->assertSame( [ 'sandbox' => 'read-only' ], $surface['agent_opts'] );
		$this->assertSame(
			[
				'surface:1',
				'workspace:1',
				'CODEX_HOME=' . escapeshellarg( $home ) . " codex resume --model=gpt-5.6-terra --sandbox=read-only 01a20e0c-6080-7ed0-af9e-e7e378679e85\n",
			],
			$sent
		);
	}

	public function testDefaultHomeCodexBackupAndResumeKeepTheirShape(): void {
		[ , , $surface, $sent ] = $this->codexRoundTrip( 'codex-default', [ 'codex_home' => null ] );

		$this->assertArrayNotHasKey( 'agent_codex_home', $surface );
		$this->assertSame(
			"codex resume --model=gpt-5.6-terra --sandbox=read-only 01a20e0c-6080-7ed0-af9e-e7e378679e85\n",
			$sent[2]
		);
	}

	public function testAuditFindsACodexTranscriptInTheRecordedHome(): void {
		$sid  = '01a20e0c-6080-7ed0-af9e-e7e378679e85';
		$home = $this->graveyardRoot . '/.codex-work';
		$dir  = $home . '/sessions/2026/11/24';
		mkdir( $dir, 0777, true );
		file_put_contents( "{$dir}/rollout-2026-11-24T09-53-41-{$sid}.jsonl", "{}\n" );
		// The current shell's home is empty, so only the recorded home can find it.
		mkdir( $this->graveyardRoot . '/empty-codex-sessions' );
		putenv( 'CODEX_SESSIONS_DIR=' . $this->graveyardRoot . '/empty-codex-sessions' );

		$file = $this->temporaryFile( 'codex-audit' );
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->treeData = $this->singleSurfaceTree( 'Codex' );
		$surface = [
			'ref'              => 'surface:1',
			'title'            => 'Codex',
			'type'             => 'terminal',
			'cwd'              => $this->graveyardRoot,
			'agent'            => 'codex',
			'agent_session_id' => $sid,
			'agent_opts'       => [],
		];
		$backup = fn( array $extra ) => file_put_contents( $file, json_encode( [
			'version'    => 3,
			'workspaces' => [ [ 'title' => 'dotfiles', 'panes' => [ [ 'surfaces' => [ $surface + $extra ] ] ] ] ],
		] ) );

		try {
			$backup( [ 'agent_codex_home' => $home ] );
			ob_start();
			( new CmuxBak( $this->cli, $file, true, false, $cmux ) )->audit();
			$withHome = (string) ob_get_clean();

			$backup( [] );
			ob_start();
			( new CmuxBak( $this->cli, $file, true, false, $cmux ) )->audit();
			$withoutHome = (string) ob_get_clean();
		} finally {
			putenv( 'CODEX_SESSIONS_DIR' );
		}

		$this->assertStringContainsString( 'resumable', $withHome );
		$this->assertStringContainsString( 'transcript NOT found', $withoutHome );
	}

	private function singleSurfaceTree( string $title ): array {
		return [
			'windows' => [
				[
					'workspaces' => [
						[
							'title' => 'dotfiles',
							'ref'   => 'workspace:1',
							'panes' => [
								[
									'ref'      => 'pane:1',
									'index'    => 0,
									'surfaces' => [
										[
											'ref'           => 'surface:1',
											'id'            => 'surface-id-1',
											'title'         => $title,
											'type'          => 'terminal',
											'tty'           => 'ttys001',
											'index_in_pane' => 0,
										],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	#[DataProvider('backupSchemaProvider')]
	public function testDryRunRestoreBuildsTheRightResumeCommandForV1AndV2(
		array $agentFields,
		array $expectedResumeArguments
	): void {
		$file = $this->temporaryFile( 'restore' );
		// A cwd that really exists: restore only cd's into a recorded directory that
		// is still there, so a fictional path would (correctly) yield no cd at all.
		$cwd = $this->graveyardRoot;
		file_put_contents(
			$file,
			json_encode(
				[
					'version'    => isset( $agentFields['claude_session_id'] ) ? 1 : 2,
					'timestamp'  => '2026-07-29T12:00:00Z',
					'workspaces' => [
						[
							'title' => 'dotfiles',
							'panes' => [
								[
									'surfaces' => [
										array_merge(
											[
												'title' => 'Agent',
												'type'  => 'terminal',
												'cwd'   => $cwd,
											],
											$agentFields
										),
									],
								],
							],
						],
					],
				],
				JSON_PRETTY_PRINT
			)
		);

		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->treeData = [
			'windows' => [
				[
					'workspaces' => [
						[
							'title' => 'dotfiles',
							'ref'   => 'workspace:1',
							'panes' => [
								[
									'ref'      => 'pane:1',
									'surfaces' => [
										[
											'title' => 'Agent',
											'ref'   => 'surface:1',
											'type'  => 'terminal',
											'tty'   => 'ttys001',
										],
									],
								],
							],
						],
					],
				],
			],
		];
		$bak = new CmuxBak( $this->cli, $file, true, false, $cmux );

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [ $expectedResumeArguments ], $cmux->resumeArguments );
		$this->assertSame(
			[
				[ 'surface:1', 'workspace:1', "cd {$cwd}\n" ],
				[
					'surface:1',
					'workspace:1',
					"resume {$expectedResumeArguments[0]} {$expectedResumeArguments[1]}\n",
				],
			],
			$cmux->sent
		);
	}

	public static function backupSchemaProvider(): array {
		return [
			'v1 Claude fields' => [
				[
					'claude_session_id'       => 'claude-session',
					'claude_model'            => 'opus',
					'claude_skip_permissions' => true,
				],
				[ 'claude', 'claude-session', true, 'opus', [] ],
			],
			'v2 Codex fields and options' => [
				[
					'agent'                  => 'codex',
					'agent_session_id'       => 'codex-session',
					'agent_model'            => 'gpt-5.6-terra',
					'agent_skip_permissions' => false,
					'agent_opts'             => [
						'sandbox'        => 'read-only',
						'approval_policy' => 'never',
						'effort'         => 'high',
					],
				],
				[
					'codex',
					'codex-session',
					false,
					'gpt-5.6-terra',
					[
						'sandbox'        => 'read-only',
						'approval_policy' => 'never',
						'effort'         => 'high',
					],
				],
			],
		];
	}

	public function testDryRunBackupLeavesTheExistingBackupUntouched(): void {
		// The backup file is often the only record of what was open before a restart,
		// so a --dry-run that rewrote it would destroy exactly what a restore needs.
		$file = $this->temporaryFile( 'dry-run' );
		file_put_contents( $file, '{"previous":"backup"}' );
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->treeData = [ 'windows' => [] ];
		$bak = new CmuxBak( $this->cli, $file, true, false, $cmux );

		ob_start();
		$code   = $bak->backup();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( '{"previous":"backup"}', file_get_contents( $file ) );
		$this->assertStringContainsString( 'Would save', $output );
	}

	public function testUnreachableCmuxReturnsFailureWithoutTouchingTheBackup(): void {
		$file = $this->temporaryFile( 'unreachable' );
		$cmux = new CmuxBakOperationalCmux( $this->cli );
		$cmux->reachable = false;
		$bak = new CmuxBak( $this->cli, $file, false, false, $cmux );

		ob_start();
		$code   = $bak->backup();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertFileDoesNotExist( $file );
		$this->assertStringContainsString( 'cmux is not reachable', $output );
	}

	#[DataProvider('missingBackupActionProvider')]
	public function testMissingBackupReturnsFailureWithoutFurtherCmuxActivity(
		string $action
	): void {
		$cli  = new CmuxBakExitInterceptHelpers();
		$file = $this->temporaryFile( 'missing-' . $action );
		$cmux = new CmuxBakOperationalCmux( $cli );
		$bak  = new CmuxBak( $cli, $file, false, false, $cmux );

		ob_start();
		$code   = $bak->{$action}();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( 0, $cmux->treeCalls );
		$this->assertStringContainsString( 'Backup file not found: ' . $file, $output );
	}

	public static function missingBackupActionProvider(): array {
		return [
			'restore' => [ 'restore' ],
			'audit'   => [ 'audit' ],
		];
	}

	private function property( CmuxBak $bak, string $name ): mixed {
		$property = new \ReflectionProperty( CmuxBak::class, $name );

		return $property->getValue( $bak );
	}

	private function temporaryFile( string $label ): string {
		$file = sys_get_temp_dir() . '/cmux-bak-' . $label . '-' . uniqid() . '.json';
		$this->temporaryFiles[] = $file;

		return $file;
	}
}
