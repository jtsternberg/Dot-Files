<?php
namespace JT\Tests\LocalReview;

use JT\Helpers\Ollama;

/** An Ollama with a canned config and storage target that replays scripted replies per model and logs every call in order. */
final class StubReviewOllama extends Ollama {

	/** @var array<int,array{call:string, model:string, messages?:array, request?:array, timeout?:int}> */
	public array $calls = [];

	/**
	 * @param array<string,array<int,array{content:?string,error:?string,errorType:?string}>> $replies Per model, consumed in order; the last one repeats.
	 */
	public function __construct(
		private array $stubConfig = [],
		string|false $storage = false,
		private array $replies = []
	) {
		parent::__construct( static fn(): string|false => $storage );
	}

	public function config( ?string $configDir = null ): array {
		return $this->stubConfig;
	}

	public function chatMessages(
		string $model,
		array $messages,
		string $url = self::DEFAULT_URL,
		int $timeoutSeconds = 300,
		array $request = []
	): array {
		$this->calls[] = [ 'call' => 'chat', 'model' => $model, 'messages' => $messages, 'request' => $request, 'timeout' => $timeoutSeconds ];
		$queue = $this->replies[ $model ] ?? [ [ 'content' => 'No bugs found.', 'error' => null, 'errorType' => null ] ];
		$reply = count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
		$this->replies[ $model ] = $queue;

		return $reply;
	}

	public function unload( string $model, string $url = self::DEFAULT_URL ): ?string {
		$this->calls[] = [ 'call' => 'unload', 'model' => $model ];

		return null;
	}

	/** @return string[] "chat:model" / "unload:model", in call order. */
	public function sequence(): array {
		return array_map( static fn( array $c ): string => $c['call'] . ':' . $c['model'], $this->calls );
	}

	public static function reply( string $content ): array {
		return [ 'content' => $content, 'error' => null, 'errorType' => null ];
	}
}
