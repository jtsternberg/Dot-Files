<?php
namespace JT\Tests\ToText;

use JT\ImageToText;
use JT\Tests\TestCase;

final class ImageToTextTest extends TestCase {

	private string $image;

	protected function setUp(): void {
		parent::setUp();
		$this->image = sys_get_temp_dir() . '/to-text-' . uniqid() . '.png';
		file_put_contents( $this->image, 'png-bytes' );
	}

	protected function tearDown(): void {
		@unlink( $this->image );
	}

	public function testSendsTheImageBase64EncodedToTheModel(): void {
		$ollama = new StubVisionOllama();

		$result = ( new ImageToText( $ollama, '/home/jt' ) )->extract( $this->image, 'a-model' );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'Some text', $result['text'] );
		$this->assertSame( 'a-model', $ollama->chats[0]['model'] );
		$this->assertSame( [ base64_encode( 'png-bytes' ) ], $ollama->chats[0]['images'] );
		$this->assertFalse( $ollama->chats[0]['request']['think'] );
		$this->assertSame( 0, $ollama->chats[0]['request']['options']['temperature'] );
	}

	public function testResolvesTheModelFromItsOwnConfigKeysPerStore(): void {
		$config = [ 'MODEL' => 'code-model', 'OCR_MODEL_SD' => 'big-vision', 'OCR_MODEL_LOCAL' => 'small-vision' ];

		$sd    = new ImageToText( new StubVisionOllama( $config, '/Volumes/AI-LAB/ollama/models' ), '/home/jt' );
		$local = new ImageToText( new StubVisionOllama( $config, '/home/jt/.ollama-local' ), '/home/jt' );

		$this->assertSame( 'big-vision', $sd->resolveModel() );
		$this->assertSame( 'small-vision', $local->resolveModel() );
	}

	public function testDefaultsToAVisionModelThatSurvivesAnEject(): void {
		$this->assertSame( ImageToText::DEFAULT_MODEL, ( new ImageToText( new StubVisionOllama(), '/home/jt' ) )->resolveModel() );
	}

	public function testAMissingImageFailsWithoutCallingTheModel(): void {
		$ollama = new StubVisionOllama();

		$result = ( new ImageToText( $ollama, '/home/jt' ) )->extract( '/nope/missing.png', 'a-model' );

		$this->assertSame( 'input', $result['errorType'] );
		$this->assertStringContainsString( '/nope/missing.png', $result['error'] );
		$this->assertSame( [], $ollama->chats );
	}

	public function testAnEmptyReplyMeansNoTextWasFound(): void {
		$ollama = new StubVisionOllama( [], false, [ 'content' => "  \n", 'error' => null, 'errorType' => null ] );

		$result = ( new ImageToText( $ollama, '/home/jt' ) )->extract( $this->image, 'a-model' );

		$this->assertSame( 'empty', $result['errorType'] );
	}

	public function testTheNoTextSentinelMeansNoTextWasFound(): void {
		$ollama = new StubVisionOllama( [], false, [ 'content' => ImageToText::NO_TEXT . "\n", 'error' => null, 'errorType' => null ] );

		$result = ( new ImageToText( $ollama, '/home/jt' ) )->extract( $this->image, 'a-model' );

		$this->assertSame( 'empty', $result['errorType'] );
		$this->assertStringContainsString( ImageToText::NO_TEXT, $ollama->chats[0]['user'] );
	}

	public function testPassesOllamaErrorsThrough(): void {
		$ollama = new StubVisionOllama( [], false, [ 'content' => null, 'error' => 'model not found', 'errorType' => 'api' ] );

		$result = ( new ImageToText( $ollama, '/home/jt' ) )->extract( $this->image, 'bogus' );

		$this->assertSame( 'api', $result['errorType'] );
		$this->assertSame( 'model not found', $result['error'] );
		$this->assertSame( 'bogus', $result['model'] );
	}
}
