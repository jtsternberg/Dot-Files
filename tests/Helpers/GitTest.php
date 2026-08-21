<?php
namespace JT\Tests\Helpers;

use JT\CLI\Helpers\Git;
use JT\Tests\TestCase;

/**
 * Covers the directory-scoped, exit-code-returning primitives added for
 * per-file git reasoning. Uses real temp repos rather than a stub git, since
 * the whole point of these methods is fidelity to what git actually reports.
 */
final class GitTest extends TestCase {

	private Git $git;
	private string $repo = '';
	private string $plain = '';

	protected function setUp(): void {
		parent::setUp();

		$this->git   = new Git();
		$this->repo  = $this->graveyardRoot . '/repo';
		$this->plain = $this->graveyardRoot . '/plain';
		mkdir( $this->repo, 0777, true );
		mkdir( $this->plain, 0777, true );

		$this->gitCmd( [ 'init' ] );
		$this->gitCmd( [ 'config', 'user.email', 't@t' ] );
		$this->gitCmd( [ 'config', 'user.name', 't' ] );
	}

	public function testIsInGit(): void {
		$this->assertTrue( $this->git->isInGit( $this->repo ) );
		$this->assertFalse( $this->git->isInGit( $this->plain ) );
	}

	public function testTopLevel(): void {
		$this->assertSame( realpath( $this->repo ), realpath( (string) $this->git->topLevel( $this->repo ) ) );
		$this->assertNull( $this->git->topLevel( $this->plain ) );
	}

	public function testIsTracked(): void {
		file_put_contents( $this->repo . '/tracked.md', "x\n" );
		file_put_contents( $this->repo . '/loose.md', "x\n" );
		$this->gitCmd( [ 'add', 'tracked.md' ] );

		$this->assertTrue( $this->git->isTracked( $this->repo . '/tracked.md' ) );
		$this->assertFalse( $this->git->isTracked( $this->repo . '/loose.md' ) );
	}

	public function testIsInHead(): void {
		file_put_contents( $this->repo . '/committed.md', "x\n" );
		$this->gitCmd( [ 'add', 'committed.md' ] );
		$this->gitCmd( [ 'commit', '-m', 'add' ] );

		file_put_contents( $this->repo . '/staged.md', "x\n" );
		$this->gitCmd( [ 'add', 'staged.md' ] );

		$this->assertTrue( $this->git->isInHead( $this->repo . '/committed.md' ) );
		$this->assertFalse( $this->git->isInHead( $this->repo . '/staged.md' ) );
	}

	public function testIsClean(): void {
		file_put_contents( $this->repo . '/doc.md', "one\n" );
		$this->gitCmd( [ 'add', 'doc.md' ] );
		$this->gitCmd( [ 'commit', '-m', 'add' ] );
		$this->assertTrue( $this->git->isClean( $this->repo . '/doc.md' ) );

		file_put_contents( $this->repo . '/doc.md', "two\n" );
		$this->assertFalse( $this->git->isClean( $this->repo . '/doc.md' ) );
	}

	public function testMvPreservesHistory(): void {
		file_put_contents( $this->repo . '/Old Name.md', "x\n" );
		$this->gitCmd( [ 'add', 'Old Name.md' ] );
		$this->gitCmd( [ 'commit', '-m', 'add' ] );

		$this->assertTrue( $this->git->mv( $this->repo . '/Old Name.md', $this->repo . '/new-name.md' ) );
		$this->assertFileExists( $this->repo . '/new-name.md' );
		$this->assertStringContainsString( 'R', $this->gitCmd( [ 'status', '--porcelain' ] ) );
	}

	public function testCommitPathsReturnsShaAndScopesToPathspecs(): void {
		file_put_contents( $this->repo . '/seed.md', "x\n" );
		$this->gitCmd( [ 'add', 'seed.md' ] );
		$this->gitCmd( [ 'commit', '-m', 'seed' ] );

		file_put_contents( $this->repo . '/a.md', "a\n" );
		file_put_contents( $this->repo . '/b.md', "b\n" );
		$this->gitCmd( [ 'add', 'a.md', 'b.md' ] );

		$sha = $this->git->commitPaths( $this->repo, "chore: add a\n", [ 'a.md' ] );

		$this->assertNotNull( $sha );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7,}$/', $sha );
		// b.md was NOT in the pathspec, so it is still staged/uncommitted.
		$this->assertStringContainsString( 'A  b.md', $this->gitCmd( [ 'status', '--porcelain' ] ) );
	}

	public function testCommitPathsReturnsNullOnFailure(): void {
		// Nothing staged for this path and no HEAD: commit fails.
		$this->assertNull( $this->git->commitPaths( $this->repo, "empty\n", [ 'nope.md' ] ) );
	}

	/** @param string[] $args */
	private function gitCmd( array $args ): string {
		$cmd = 'git -C ' . escapeshellarg( $this->repo );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}
		exec( $cmd . ' 2>&1', $out );

		return implode( "\n", $out );
	}
}
