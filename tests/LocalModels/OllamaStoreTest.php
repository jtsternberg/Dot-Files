<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\OllamaEngine;
use JT\Tests\TestCase;

/**
 * Ollama residency, read from manifests rather than the API.
 *
 * Reading the store on disk is what lets status report the EXTERNAL store's
 * models while the local store is active — an /api/tags call only ever describes
 * whichever store Ollama is pointed at right now.
 */
final class OllamaStoreTest extends TestCase {

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home, 0777, true );
		mkdir( $this->volumes, 0777, true );
	}

	private function seedModel( string $store, string $model, string $tag ): void {
		$dir = $store . '/manifests/registry.ollama.ai/library/' . $model;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/' . $tag, '{"layers":[]}' );
	}

	public function testResidencyListsModelTagsFromBothStores(): void {
		$this->seedModel( $this->home . '/.ollama-local-models', 'gemma4', '26b' );
		$this->seedModel( $this->volumes . '/AI-LAB/ollama/models', 'gemma4', '26b' );
		$this->seedModel( $this->volumes . '/AI-LAB/ollama/models', 'qwen3.5', '35b' );

		$rows = [];
		foreach ( ( new OllamaEngine( $this->home, $this->volumes ) )->residency() as $row ) {
			$rows[ $row['name'] ] = $row;
		}

		$this->assertArrayHasKey( 'gemma4:26b', $rows );
		$this->assertTrue( $rows['gemma4:26b']['local'] );
		$this->assertTrue( $rows['gemma4:26b']['external'] );
		$this->assertFalse( $rows['qwen3.5:35b']['local'] );
		$this->assertTrue( $rows['qwen3.5:35b']['external'] );
	}

	public function testResidencyMarksAvailabilityFromTheActiveStore(): void {
		$this->seedModel( $this->home . '/.ollama-local-models', 'gemma4', '26b' );
		$this->seedModel( $this->volumes . '/AI-LAB/ollama/models', 'qwen3.5', '35b' );
		$engine = new OllamaEngine( $this->home, $this->volumes );
		$engine->apply( 'local' );

		$rows = [];
		foreach ( $engine->residency() as $row ) {
			$rows[ $row['name'] ] = $row;
		}

		$this->assertTrue( $rows['gemma4:26b']['available'] );
		$this->assertFalse( $rows['qwen3.5:35b']['available'] );
	}

	public function testResidencyIsEmptyWhenNoStoreHasManifests(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		$this->assertSame( [], ( new OllamaEngine( $this->home, $this->volumes ) )->residency() );
	}
}
