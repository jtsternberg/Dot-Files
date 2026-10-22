<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use JT\Helpers\FoundationModels;
use JT\Helpers\Ollama;

#[Program(
	name: 'auto-commit-fm',
	description: "Generate a commit message from staged changes using Apple's on-device Foundation Model (`fm`) and create the commit.",
)]
final class AutoCommitFmCommand extends AutoCommitCommand {

	const DEBUG_FILE = '/tmp/auto-commit-fm-debug.txt';

	/** @var callable():array<string,string> */
	private $config;

	public function __construct(
		Helpers $cli,
		private ?FoundationModels $fm = null,
		?callable $config = null,
		?callable $shell = null,
		?callable $runWithCode = null,
		?callable $writeFile = null,
		?callable $speak = null,
	) {
		parent::__construct( $cli, $shell, $runWithCode, $writeFile, $speak );

		// The ai-tooling config is shared by every local-model tool; its reader
		// just happens to live on Ollama.
		$this->config = $config ?: static fn(): array => ( new Ollama() )->config();
	}

	#[Command(
		description: 'Generate a commit message from staged changes and create the commit.',
		default: true,
	)]
	public function run(
		#[Argument( description: 'Suggested commit message or context for the commit' )]
		?string $context = null,
		#[Option( description: 'fm model to use (default: ' . FoundationModels::DEFAULT_MODEL . ')' )]
		?string $model = null,
		#[Option(
			description: 'Regenerate commit message for HASH (default: HEAD) and amend it',
			valueName: 'hash',
			optionalValue: true,
		)]
		?string $retry = null,
		#[Option(
			description: 'Note the problem with the existing message directly, to steer the retry analysis (use with --retry)',
			valueName: 'reason',
		)]
		?string $retryReason = null,
		#[Option( description: 'Save the full prompt to ' . self::DEBUG_FILE )]
		bool $debug = false,
		#[Option( description: 'Speak the result using macOS say command' )]
		bool $say = false,
		#[Option( description: 'Suppress printing the result' )]
		bool $noprint = false
	): int {
		return $this->execute(
			$model ?: FoundationModels::DEFAULT_MODEL,
			$context,
			$retry,
			$retryReason,
			$debug,
			$say,
			$noprint
		);
	}

	protected function backendName(): string {
		return 'Apple Foundation Models';
	}

	protected function debugFile(): string {
		return self::DEBUG_FILE;
	}

	protected function signatureTemplate(): string {
		return (string) ( ( $this->config )()['AUTO_COMMIT_FM_SIGNATURE'] ?? '' );
	}

	protected function signatureModel( string $model ): string {
		return "apple-fm/{$model}";
	}

	protected function complete( string $model, string $systemPrompt, string $userPrompt ): ?string {
		$result = $this->fm()->respond( $systemPrompt, $userPrompt, $model );

		if ( null !== $result['error'] ) {
			$this->cli->err( "Error from Apple Foundation Models: {$result['error']}" );
			$this->cli->err( 'Check the model with: fm available (needs macOS 27+ on Apple silicon, terms accepted via: fm license)' );

			return null;
		}

		return (string) $result['content'];
	}

	private function fm(): FoundationModels {
		if ( null === $this->fm ) {
			$this->fm = new FoundationModels();
		}

		return $this->fm;
	}
}
