<?php
namespace JT\Tests\LocalModels;

use JT\AiModelsCommand;
use JT\CLI\Command\Dispatcher;
use JT\Tests\TestCase;

/**
 * Dispatch, the shared status accessor, and the completion contract.
 *
 * Every case runs against a throwaway $home/$volumesRoot, so `aimodels` under
 * test can never reach the real stores.
 */
final class AiModelsCommandTest extends TestCase {

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home, 0777, true );
		mkdir( $this->volumes, 0777, true );
	}

	private function dispatch( array $argv ): array {
		$this->cli->setArgs( $argv );
		ob_start();
		$code = ( new Dispatcher(
			$this->cli,
			new AiModelsCommand( $this->cli, $this->home, $this->volumes )
		) )->run();

		return [ $code, (string) ob_get_clean() ];
	}

	public function testStatusIsTheDefaultCommand(): void {
		[ $code, $out ] = $this->dispatch( [ 'aimodels' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'ollama', $out );
		$this->assertStringContainsString( 'macwhisper', $out );
	}

	public function testStatusJsonCarriesEveryEngine(): void {
		[ $code, $out ] = $this->dispatch( [ 'aimodels', '--json' ] );
		$decoded = json_decode( $out, true );

		$this->assertSame( 0, $code );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'ollama', $decoded['engines'] );
		$this->assertArrayHasKey( 'macwhisper', $decoded['engines'] );
	}

	/**
	 * `status` and `where` are two views of one dataset; both must read the same
	 * accessor, so a fact visible in one is never missing from the other.
	 */
	public function testWhereAgreesWithStatusOnTheActiveStore(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		$this->dispatch( [ 'aimodels', 'ollama', 'local' ] );

		[ , $json ]  = $this->dispatch( [ 'aimodels', '--json' ] );
		[ , $where ] = $this->dispatch( [ 'aimodels', 'where' ] );

		$decoded = json_decode( $json, true );
		$this->assertSame( 'local', $decoded['engines']['ollama']['location'] );
		$this->assertStringContainsString( 'ollama: local', $where );
	}

	public function testEngineFlipDispatchesThroughTheCli(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'ollama', 'local' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'local', $out );
		$this->assertSame(
			$this->home . '/.ollama-local-models',
			readlink( $this->home . '/.ollama-models' )
		);
	}

	public function testDryRunFlagIsHonoured(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		[ $code ] = $this->dispatch( [ 'aimodels', 'ollama', 'local', '--dry-run' ] );

		$this->assertSame( 0, $code );
		$this->assertFalse( is_link( $this->home . '/.ollama-models' ) );
	}

	public function testUnmanageableEngineFailsWithoutTouchingRealData(): void {
		$models = $this->home . '/Library/Application Support/MacWhisper/models';
		mkdir( $models, 0777, true );
		file_put_contents( $models . '/keep-me', 'real user data' );

		[ $code ] = $this->dispatch( [ 'aimodels', 'whisper', 'auto' ] );

		$this->assertSame( 1, $code );
		$this->assertFileExists( $models . '/keep-me' );
		$this->assertFalse( is_link( $models ) );
	}

	/**
	 * The LaunchAgent runs exactly `aimodels watch apply --silent`. An undeclared
	 * --silent is rejected by the dispatcher as an unknown option, and in silent
	 * mode that error message is itself suppressed — so the agent fails on every
	 * /Volumes event and says nothing. This pins the invocation the plist uses.
	 */
	public function testWatcherInvocationFromThePlistSucceeds(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		[ $code ] = $this->dispatch( [ 'aimodels', 'watch', 'apply', '--silent' ] );

		$this->assertSame( 0, $code );
	}

	public function testStatusAcceptsSilent(): void {
		[ $code ] = $this->dispatch( [ 'aimodels', 'status', '--silent' ] );

		$this->assertSame( 0, $code );
	}

	public function testStatusPointsAtEjectWhileAStoreIsOnTheDrive(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );
		$this->dispatch( [ 'aimodels', 'ollama', 'external' ] );

		[ , $out ] = $this->dispatch( [ 'aimodels', 'where' ] );

		$this->assertStringContainsString( 'aimodels eject', $out );
	}

	public function testNoEjectHintWhenTheDriveIsAbsent(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		[ , $out ] = $this->dispatch( [ 'aimodels', 'where' ] );

		$this->assertStringNotContainsString( 'aimodels eject', $out );
	}

	/**
	 * The flip back to local is where eject learns things JT must hear before the
	 * drive is gone — e.g. diarization that exists only on AI-LAB.
	 */
	public function testEjectPrintsTheWarningsTheReleaseFlipReturned(): void {
		foreach ( [ 'diskutil' => "echo 'Volume AI-LAB ejected'", 'lsof' => ':', 'ollama' => ':' ] as $tool => $body ) {
			putenv( 'AIMODELS_' . strtoupper( $tool ) . '_BIN=' . self::sharedStub( $tool, "#!/bin/sh\n{$body}\nexit 0\n" ) );
		}

		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		mkdir( $this->home . '/.macwhisper-local-models', 0777, true );
		mkdir( $this->volumes . '/AI-LAB/macwhisper/models/speakerkit-pro/x', 0777, true );
		$this->dispatch( [ 'aimodels', 'whisper', 'external' ] );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'eject' ] );

		putenv( 'AIMODELS_DISKUTIL_BIN' );
		putenv( 'AIMODELS_LSOF_BIN' );
		putenv( 'AIMODELS_OLLAMA_BIN' );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'diarization support only on AI-LAB (speakerkit-pro)', $out );
	}

	/**
	 * `status --json` and `why --json` are two views of one residency accessor:
	 * the multi-part Qwen row must read identically in both.
	 */
	public function testStatusAndWhyShowTheSameMultiPartQwenRow(): void {
		$base = $this->volumes . '/AI-LAB/macwhisper/models/whisperkitpro/models/argmaxinc/qwenasrkit-pro/qwen3-asr';
		foreach ( [ 'audio_encoder' => 1, 'text_decoder' => 2 ] as $part => $mb ) {
			mkdir( "{$base}/{$part}/1.7b/M.mlmodelc", 0777, true );
			file_put_contents( "{$base}/{$part}/1.7b/M.mlmodelc/w.bin", str_repeat( 'w', $mb * 1048576 ) );
		}

		[ , $status ] = $this->dispatch( [ 'aimodels', '--json' ] );
		[ , $why ]    = $this->dispatch( [ 'aimodels', 'why', 'list', '--json', '--engine=macwhisper' ] );

		$pick = static function ( array $rows ): array {
			foreach ( $rows as $row ) {
				if ( 'qwen3-asr:qwen3-asr-1.7b' === $row['id'] ) {
					return [ $row['name'], $row['sizeMb'] ];
				}
			}

			return [];
		};

		$this->assertSame( [ 'qwen3-asr-1.7b', 3 ], $pick( json_decode( $status, true )['engines']['macwhisper']['models'] ) );
		$this->assertSame( [ 'qwen3-asr-1.7b', 3 ], $pick( json_decode( $why, true )['models'] ) );
	}

	public function testUnknownActionIsAUsageFailure(): void {
		[ $code ] = $this->dispatch( [ 'aimodels', 'ollama', 'sideways' ] );

		$this->assertSame( 1, $code );
	}

	public function testCompletionGeneratesEveryCommand(): void {
		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'completion', 'zsh' ] );

		$this->assertSame( 0, $code );
		foreach ( [ 'where', 'ollama', 'whisper', 'watch' ] as $command ) {
			$this->assertStringContainsString( "'{$command}:", $out );
		}
	}

	/**
	 * The checked-in loader must not enumerate command metadata — that would be a
	 * second interface definition, free to drift from the attributes.
	 */
	public function testCheckedInCompletionLoaderCarriesNoCommandMetadata(): void {
		$plugin = file_get_contents(
			dirname( __DIR__, 2 ) . '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh'
		);

		$this->assertStringContainsString( '_aimodels_lazy', $plugin );
		$this->assertStringContainsString( 'compdef _aimodels_lazy aimodels', $plugin );
		$this->assertStringNotContainsString( "'whisper:", $plugin );
		$this->assertStringNotContainsString( 'AI-LAB', $plugin );
	}
}
