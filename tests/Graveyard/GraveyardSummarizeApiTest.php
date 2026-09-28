<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\LocalLlmSummarizer;
use JT\Tests\LocalLlmSummarizer\StubSummaryOllama;
use JT\Tests\TestCase;

/**
 * POST /api/summarize — the page's summarize button. The description is saved on
 * the tombstone; the TITLE only comes back as a suggestion, because the page
 * lets the person accept it before anything is renamed.
 */
final class GraveyardSummarizeApiTest extends TestCase
{
	private StubSummaryOllama $ollama;

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	private function graveyard(array $reply = ['content' => "TITLE: Login cookie flake\nIt fixed a flake. It committed.", 'error' => null, 'errorType' => null], bool $withTranscript = true): Graveyard
	{
		$root = sys_get_temp_dir() . '/gy-summarize-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);

		$gy = new Graveyard($this->cli, $this->transport);
		$gy->upsertIndex([
			'session_id' => 'sess1234-full', 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => 'fix the login test', 'model' => 'opus',
			'buried_at' => '2026-07-15T10:00:00Z', 'last_active' => '2026-07-14T09:59:00Z',
		]);
		if ($withTranscript) {
			@mkdir($gy->sessionDir('sess1234-full'), 0755, true);
			file_put_contents($gy->transcriptMdPath('sess1234-full'), "# fix the login test\n\n**You:** fix the login test\n\n**Claude:** Fixed and committed.\n");
		}

		$this->ollama = new StubSummaryOllama(['SUMMARY_MODEL' => 'gemma-big'], false, $reply);
		$gy->setSummarizer(new LocalLlmSummarizer($this->ollama, '/home/x'));

		return $gy;
	}

	public function testSummarizeSavesTheDescriptionAndOnlySuggestsTheTitle(): void
	{
		$gy = $this->graveyard();

		$res = $gy->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'sess1234']);

		$this->assertSame(200, $res['status']);
		$this->assertTrue($res['body']['ok']);
		$this->assertSame('Login cookie flake', $res['body']['title']);
		$this->assertSame('It fixed a flake. It committed.', $res['body']['description']);
		$this->assertSame('gemma-big', $res['body']['model']);

		$tomb = $gy->readIndex()['tombstones'][0];
		$this->assertSame('It fixed a flake. It committed.', $tomb['description']);
		$this->assertSame('gemma-big', $tomb['description_model']);
		$this->assertArrayNotHasKey('name', $tomb, 'the suggested title must not rename the session');
		$this->assertStringContainsString('fix the login test', $this->ollama->chats[0]['user']);
	}

	public function testTheDescriptionRendersOnTheStoneForTheModal(): void
	{
		$gy = $this->graveyard();
		$gy->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'sess1234']);

		$html = $gy->renderStorePageHtml();

		$this->assertStringContainsString('data-description="It fixed a flake. It committed."', $html);
	}

	public function testUnknownSessionReturns404(): void
	{
		$res = $this->graveyard()->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'ghost']);

		$this->assertSame(404, $res['status']);
		$this->assertSame([], $this->ollama->chats);
	}

	public function testOnlySessionsCanBeSummarized(): void
	{
		$res = $this->graveyard()->handleApi('POST', '/api/summarize', ['scope' => 'group', 'id' => 'g1']);

		$this->assertSame(400, $res['status']);
	}

	public function testAMissingTranscriptIsReportedWithoutCallingTheModel(): void
	{
		$gy  = $this->graveyard(withTranscript: false);
		$res = $gy->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'sess1234']);

		$this->assertSame(422, $res['status']);
		$this->assertFalse($res['body']['ok']);
		$this->assertSame([], $this->ollama->chats);
	}

	public function testAnOllamaFailureIsA502AndSavesNothing(): void
	{
		$gy  = $this->graveyard(['content' => null, 'error' => 'Connection refused', 'errorType' => 'transport']);
		$res = $gy->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'sess1234']);

		$this->assertSame(502, $res['status']);
		$this->assertStringContainsString('Connection refused', $res['body']['error']);
		$this->assertStringContainsString('Ollama', $res['body']['error']);
		$this->assertArrayNotHasKey('description', $gy->readIndex()['tombstones'][0]);
	}

	public function testSummarizeDoesNotLeakOutputIntoTheResponse(): void
	{
		$gy = $this->graveyard();

		ob_start();
		$gy->handleApi('POST', '/api/summarize', ['scope' => 'session', 'id' => 'sess1234']);
		$this->assertSame('', ob_get_clean());
	}
}
