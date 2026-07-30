<?php
namespace JT\Tests\DirMap;

use JT\DirMap;
use JT\Tests\TestCase;

final class DirMapTest extends TestCase
{
	private string $store;

	protected function setUp(): void
	{
		parent::setUp();
		$this->store = $this->graveyardRoot . '/dirmap.json';
		putenv( 'DIRMAP_FILE=' . $this->store );
	}

	protected function tearDown(): void
	{
		putenv( 'DIRMAP_FILE' );
		parent::tearDown();
	}

	public function testAddWritesPrettyJsonWithoutEscapedSlashesOrShellQuoting(): void
	{
		$this->cli->setArgs( [ 'dirmap', 'add', 'project', "/tmp/JT's/project" ] );
		( new DirMap( $this->cli ) )->add( $this->cli->args );

		$this->assertSame(
			"{\n    \"project\": \"/tmp/JT's/project\"\n}\n",
			file_get_contents( $this->store )
		);
	}

	public function testRemoveDeletesOnlyRequestedMapping(): void
	{
		file_put_contents( $this->store, "{\"keep\":\"/tmp/keep\",\"drop\":\"/tmp/drop\"}\n" );
		$this->cli->setArgs( [ 'dirmap', 'remove', 'drop' ] );
		( new DirMap( $this->cli ) )->remove( $this->cli->args );

		$this->assertSame( [ 'keep' => '/tmp/keep' ], json_decode( file_get_contents( $this->store ), true ) );
	}

	public function testRenamePreservesPathAndReplacesKey(): void
	{
		file_put_contents( $this->store, "{\"old\":\"/tmp/project\"}\n" );
		$this->cli->setArgs( [ 'dirmap', 'rename', 'old', 'new' ] );
		( new DirMap( $this->cli ) )->rename( $this->cli->args );

		$this->assertSame( [ 'new' => '/tmp/project' ], json_decode( file_get_contents( $this->store ), true ) );
	}

	public function testIdentifyFindsTheShortestAliasForTheCurrentPath(): void
	{
		file_put_contents( $this->store, "{\"project\":\"/tmp/project\",\"p\":\"/tmp/project\"}\n" );

		exec(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/dirmap' )
			. ' identify /tmp/project 2>&1',
			$output,
			$status
		);

		$this->assertSame( 0, $status );
		$this->assertSame( [ 'p' ], $output );
	}
}
