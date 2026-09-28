<?php
namespace JT\Tests\LocalLlmSummarizer;

use JT\Helpers\Ollama;

/** An Ollama with a canned config, storage target, and chat reply that records each request. */
final class StubSummaryOllama extends Ollama {

	/** @var array<int,array{model:string, system:string, user:string, timeout:int, request:array}> */
	public array $chats = [];

	/** @param array{content:?string,error:?string,errorType:?string} $reply */
	public function __construct(
		private array $stubConfig = [],
		string|false $storage = false,
		private array $reply = [ 'content' => "TITLE: A title\nOne. Two.", 'error' => null, 'errorType' => null ]
	) {
		parent::__construct( static fn(): string|false => $storage );
	}

	public function config( ?string $configDir = null ): array {
		return $this->stubConfig;
	}

	public function chat(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		string $url = self::DEFAULT_URL,
		int $timeoutSeconds = 300,
		array $request = []
	): array {
		$this->chats[] = [
			'model'   => $model,
			'system'  => $systemPrompt,
			'user'    => $userPrompt,
			'timeout' => $timeoutSeconds,
			'request' => $request,
		];

		return $this->reply;
	}
}
