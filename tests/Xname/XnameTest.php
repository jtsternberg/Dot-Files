<?php
namespace JT\Tests\Xname;

use JT\Tests\TestCase;
use JT\Xname;
use PHPUnit\Framework\Attributes\DataProvider;

final class XnameTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = $this->graveyardRoot . '/files';
		mkdir( $this->dir, 0777, true );
	}

	#[DataProvider( 'sanitizeProvider' )]
	public function testSanitize( string $input, bool $lower, string $expected ): void {
		$this->assertSame( $expected, ( new Xname() )->sanitize( $input, $lower ) );
	}

	public static function sanitizeProvider(): array {
		return [
			'spaces to dashes'          => [ 'My File.txt', false, 'My-File.txt' ],
			'collapse space runs'       => [ 'report   final.pdf', false, 'report-final.pdf' ],
			'strip windows-illegal'     => [ 'a<b>c:d"e/f\\g|h?i*j.txt', false, 'a-b-c-d-e-f-g-h-i-j.txt' ],
			'dash beside dot dropped'   => [ 'file .txt', false, 'file.txt' ],
			'trim edges'                => [ '  spaced  .md  ', false, 'spaced.md' ],
			'dotfile keeps leading dot' => [ '.gitignore', false, '.gitignore' ],
			'empty when only junk'      => [ '   ', false, '' ],
			'already clean untouched'   => [ 'already-clean.txt', false, 'already-clean.txt' ],
			'accents and parens kept'   => [ 'Résumé (v2).PDF', false, 'Résumé-(v2).PDF' ],
			'lower option'              => [ 'My File.TXT', true, 'my-file.txt' ],
		];
	}

	public function testPlanClassifiesEachPath(): void {
		file_put_contents( $this->dir . '/My File.txt', 'x' );
		file_put_contents( $this->dir . '/clean.txt', 'x' );
		file_put_contents( $this->dir . '/taken.txt', 'x' );
		file_put_contents( $this->dir . '/Taken File.txt', 'x' ); // sanitizes to Taken-File, no clash

		$plan = ( new Xname() )->plan( [
			$this->dir . '/My File.txt',
			$this->dir . '/clean.txt',
			$this->dir . '/nope.txt',
		] );

		$this->assertSame( 'ready', $plan[0]['status'] );
		$this->assertSame( 'My-File.txt', $plan[0]['new'] );
		$this->assertSame( 'unchanged', $plan[1]['status'] );
		$this->assertSame( 'missing', $plan[2]['status'] );
	}

	public function testPlanFlagsCollisionWithExistingTarget(): void {
		file_put_contents( $this->dir . '/My File.txt', 'x' );
		file_put_contents( $this->dir . '/My-File.txt', 'y' ); // target already exists

		$plan = ( new Xname() )->plan( [ $this->dir . '/My File.txt' ] );

		$this->assertSame( 'collision', $plan[0]['status'] );
	}

	public function testRenamePlainFile(): void {
		file_put_contents( $this->dir . '/My File.txt', 'body' );
		$xname = new Xname();
		$rec   = $xname->plan( [ $this->dir . '/My File.txt' ] )[0];

		$this->assertTrue( $xname->rename( $rec ) );
		$this->assertFileDoesNotExist( $this->dir . '/My File.txt' );
		$this->assertSame( 'body', file_get_contents( $this->dir . '/My-File.txt' ) );
	}

	public function testCommittedFileIsTrackedAndCommittable(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/My Doc.md', "content\n" );
		$this->git( $repo, [ 'add', 'My Doc.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'add' ] );

		$xname = new Xname();
		$rec   = $xname->plan( [ $repo . '/My Doc.md' ] )[0];

		$this->assertTrue( $rec['tracked'] );
		$this->assertTrue( $rec['committed'] );
		// git canonicalizes symlinks (macOS /var -> /private/var), so compare realpaths.
		$this->assertSame( realpath( $repo ), realpath( (string) $rec['repoRoot'] ) );
		$this->assertTrue( $xname->rename( $rec ) );

		// git sees a staged rename (R), not a delete+add.
		$status = $this->git( $repo, [ 'status', '--porcelain' ] );
		$this->assertStringContainsString( 'R', $status );
		$this->assertFileExists( $repo . '/My-Doc.md' );
	}

	public function testStagedButUncommittedFileIsTrackedButNotCommittable(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/seed.md', "seed\n" );
		$this->git( $repo, [ 'add', 'seed.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'seed' ] ); // gives the repo a HEAD

		file_put_contents( $repo . '/New Doc.md', "new\n" );
		$this->git( $repo, [ 'add', 'New Doc.md' ] ); // staged, never committed

		$rec = ( new Xname() )->plan( [ $repo . '/New Doc.md' ] )[0];

		$this->assertTrue( $rec['tracked'] );   // git mv still preserves the staged add
		$this->assertFalse( $rec['committed'] ); // but it is not yet in version control
	}

	public function testCommittedFileWithPendingChangesIsNotCommittable(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/Dirty Doc.md', "one\n" );
		$this->git( $repo, [ 'add', 'Dirty Doc.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'add' ] );
		file_put_contents( $repo . '/Dirty Doc.md', "changed\n" ); // uncommitted edit

		$rec = ( new Xname() )->plan( [ $repo . '/Dirty Doc.md' ] )[0];

		$this->assertTrue( $rec['tracked'] );
		$this->assertFalse( $rec['committed'] );
	}

	public function testUntrackedFileInRepoIsNeitherTrackedNorCommittable(): void {
		$repo = $this->initRepo();
		file_put_contents( $repo . '/seed.md', "seed\n" );
		$this->git( $repo, [ 'add', 'seed.md' ] );
		$this->git( $repo, [ 'commit', '-m', 'seed' ] );
		file_put_contents( $repo . '/Loose File.md', "loose\n" ); // never added

		$rec = ( new Xname() )->plan( [ $repo . '/Loose File.md' ] )[0];

		$this->assertFalse( $rec['tracked'] );
		$this->assertFalse( $rec['committed'] );
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
