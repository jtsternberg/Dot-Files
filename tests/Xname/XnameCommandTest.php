<?php
namespace JT\Tests\Xname;

use JT\CLI\Command\Dispatcher;
use JT\Tests\TestCase;
use JT\XnameCommand;
use PHPUnit\Framework\Attributes\DataProvider;

final class XnameCommandTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = $this->graveyardRoot . '/files';
		mkdir( $this->dir, 0777, true );
	}

	/** @return array{code: int, output: string} */
	private function dispatch( array $arguments ): array {
		$this->cli->setArgs( array_merge( [ 'xname' ], $arguments ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, new XnameCommand( $this->cli ) ) )->run();
		$output = (string) ob_get_clean();

		return [ 'code' => $code, 'output' => $output ];
	}

	public function testAppliesRenameByDefault(): void {
		file_put_contents( $this->dir . '/My File.txt', 'body' );

		$result = $this->dispatch( [ $this->dir . '/My File.txt' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileDoesNotExist( $this->dir . '/My File.txt' );
		$this->assertSame( 'body', file_get_contents( $this->dir . '/My-File.txt' ) );
		$this->assertStringContainsString( 'My-File.txt', $result['output'] );
	}

	public function testDryRunTouchesNothing(): void {
		file_put_contents( $this->dir . '/My File.txt', 'body' );

		$result = $this->dispatch( [ $this->dir . '/My File.txt', '--dry-run' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $this->dir . '/My File.txt' );
		$this->assertFileDoesNotExist( $this->dir . '/My-File.txt' );
		$this->assertStringContainsString( 'My-File.txt', $result['output'] );
	}

	public function testLowerOption(): void {
		file_put_contents( $this->dir . '/My File.TXT', 'body' );

		$result = $this->dispatch( [ $this->dir . '/My File.TXT', '--lower' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $this->dir . '/my-file.txt' );
	}

	public function testRenamesMultiplePaths(): void {
		file_put_contents( $this->dir . '/one two.txt', 'a' );
		file_put_contents( $this->dir . '/three four.txt', 'b' );

		$result = $this->dispatch( [ $this->dir . '/one two.txt', $this->dir . '/three four.txt' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $this->dir . '/one-two.txt' );
		$this->assertFileExists( $this->dir . '/three-four.txt' );
	}

	public function testCollisionIsSkippedAndReportsError(): void {
		file_put_contents( $this->dir . '/My File.txt', 'source' );
		file_put_contents( $this->dir . '/My-File.txt', 'existing' );

		$result = $this->dispatch( [ $this->dir . '/My File.txt' ] );

		$this->assertSame( 1, $result['code'] );
		$this->assertSame( 'source', file_get_contents( $this->dir . '/My File.txt' ) );
		$this->assertSame( 'existing', file_get_contents( $this->dir . '/My-File.txt' ) );
		$this->assertStringContainsString( 'exists', $result['output'] );
	}

	public function testMissingPathReportsError(): void {
		$result = $this->dispatch( [ $this->dir . '/nope.txt' ] );

		$this->assertSame( 1, $result['code'] );
	}

	public function testCleanNameReportedUnchanged(): void {
		file_put_contents( $this->dir . '/clean.txt', 'x' );

		$result = $this->dispatch( [ $this->dir . '/clean.txt' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $this->dir . '/clean.txt' );
	}

	public function testCommittedFileIsAutoCommittedWithShaNotice(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/My Doc.md', "content\n" );
		$this->git( $repo, [ 'add', 'My Doc.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'add' ] );

		$result = $this->dispatch( [ $repo . '/My Doc.md' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $repo . '/My-Doc.md' );
		$this->assertStringContainsString( 'committed ', $result['output'] );
		$sha = trim( $this->git( $repo, [ 'rev-parse', '--short', 'HEAD' ] ) );
		$this->assertStringContainsString( $sha, $result['output'] );
		$this->assertStringContainsString( 'normalize filenames', $this->git( $repo, [ 'log', '-1', '--pretty=%s%n%b' ] ) );
		$this->assertSame( '', $this->git( $repo, [ 'status', '--porcelain' ] ) );
	}

	public function testNoCommitRenamesButLeavesItStaged(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/My Doc.md', "content\n" );
		$this->git( $repo, [ 'add', 'My Doc.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'add' ] );
		$head = trim( $this->git( $repo, [ 'rev-parse', 'HEAD' ] ) );

		$result = $this->dispatch( [ $repo . '/My Doc.md', '--no-commit' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $repo . '/My-Doc.md' );
		$this->assertStringNotContainsString( 'committed ', $result['output'] );
		$this->assertSame( $head, trim( $this->git( $repo, [ 'rev-parse', 'HEAD' ] ) ) ); // no new commit
		$this->assertStringContainsString( 'R', $this->git( $repo, [ 'status', '--porcelain' ] ) ); // staged rename
	}

	public function testUncommittedTrackedFileIsRenamedButNotCommitted(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/seed.md', "seed\n" );
		$this->git( $repo, [ 'add', 'seed.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'seed' ] );
		$head = trim( $this->git( $repo, [ 'rev-parse', 'HEAD' ] ) );

		file_put_contents( $repo . '/New Doc.md', "new\n" );
		$this->git( $repo, [ 'add', 'New Doc.md' ] ); // staged, never committed

		$result = $this->dispatch( [ $repo . '/New Doc.md' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertFileExists( $repo . '/New-Doc.md' );
		$this->assertStringNotContainsString( 'committed ', $result['output'] );
		$this->assertSame( $head, trim( $this->git( $repo, [ 'rev-parse', 'HEAD' ] ) ) );
	}

	#[DataProvider( 'reflectionOnlyProvider' )]
	public function testReflectionOnlyPathsTouchNothing( array $arguments, string $expected ): void {
		file_put_contents( $this->dir . '/My File.txt', 'body' );

		$result = $this->dispatch( $arguments );

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( $expected, $result['output'] );
		$this->assertFileExists( $this->dir . '/My File.txt' );
	}

	public static function reflectionOnlyProvider(): array {
		return [
			'help'         => [ [ '--help' ], 'usage: xname' ],
			'completion'   => [ [ 'completion', 'zsh' ], 'compdef _xname xname' ],
			'completes files' => [ [ 'completion', 'zsh' ], "'*:paths:_files'" ],
		];
	}

	public function testCompletionPluginLazyLoadsGeneratedOutputExactlyOnce(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/xname-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/xname';

		$this->assertStringContainsString( '_xname_lazy()', $contents );
		$this->assertStringContainsString( 'command xname completion zsh', $contents );
		$this->assertStringNotContainsString( '--dry-run[', $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_xname() { return 0; }\n"
			. "compdef _xname xname\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[xname]} == _xname_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_xname_lazy || exit 12
[[ ${_comps[xname]} == _xname ]] || exit 13
[[ $+functions[_xname] -eq 1 ]] || exit 14
ZSH;
		exec(
			'PATH=' . escapeshellarg( $binDir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc '
			. escapeshellarg( $script )
			. ' -- '
			. escapeshellarg( $plugin )
			. ' '
			. escapeshellarg( $calls ),
			$output,
			$code
		);

		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\n", file_get_contents( $calls ) );

		@unlink( $calls );
		@unlink( $stub );
		@rmdir( $binDir );
	}

	public function testCompletionPluginFailsWithoutRecursingWhenGenerationFails(): void {
		$plugin = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$binDir = sys_get_temp_dir() . '/xname-completion-failure-' . uniqid();
		$stub   = $binDir . '/xname';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_xname_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[xname]} == _xname_lazy ]] || exit 21
[[ $+functions[_xname] -eq 0 ]] || exit 22
ZSH;
		exec(
			'PATH=' . escapeshellarg( $binDir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc '
			. escapeshellarg( $script )
			. ' -- '
			. escapeshellarg( $plugin ),
			$output,
			$code
		);

		$this->assertSame( 0, $code );

		@unlink( $stub );
		@rmdir( $binDir );
	}

	private function initRepo(): string {
		$repo = $this->graveyardRoot . '/repo';
		mkdir( $repo, 0777, true );
		$this->git( $repo, [ 'init' ] );
		$this->git( $repo, [ 'config', 'user.email', 't@t' ] );
		$this->git( $repo, [ 'config', 'user.name', 't' ] );

		return $repo;
	}

	/** @param string[] $args */
	private function git( string $repo, array $args ): string {
		$cmd = 'git -C ' . escapeshellarg( $repo );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}
		exec( $cmd . ' 2>&1', $out );

		return implode( "\n", $out );
	}
}
