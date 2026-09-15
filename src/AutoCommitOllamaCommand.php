<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use JT\Helpers\Ollama;

#[Program(
	name: 'auto-commit-ollama',
	description: 'Generate a commit message from staged changes using a local Ollama model and create the commit.',
)]
final class AutoCommitOllamaCommand {

	const DEBUG_FILE = '/tmp/auto-commit-ollama-debug.txt';

	/** Enough diff for the model to judge a message by, without blowing the context. */
	const RETRY_DIFF_LINES = 50;

	const RETRY_SYSTEM_PROMPT = 'You are a commit message critic. Given a commit message and its '
		. 'diff, briefly explain what is wrong with the message and suggest how it should be '
		. 'corrected. Output ONLY the correction guidance — no pleasantries, no preamble. Be '
		. 'specific and concise.';

	/** @var callable(string):string Shell read: trimmed stdout. */
	private $shell;

	/** @var callable(string):array{output:string, error:string, exitCode:int} */
	private $runWithCode;

	/** @var callable(string,string):void */
	private $writeFile;

	/** @var callable(string):void */
	private $speak;

	public function __construct(
		private readonly Helpers $cli,
		private ?Ollama $ollama = null,
		?callable $shell = null,
		?callable $runWithCode = null,
		?callable $writeFile = null,
		?callable $speak = null,
	) {
		$this->shell = $shell ?: static fn( string $command ): string =>
			trim( (string) shell_exec( $command ) );
		$this->runWithCode = $runWithCode ?: fn( string $command ): array =>
			$this->cli->getCommandOutputAndExitCode( $command );
		$this->writeFile = $writeFile ?: static function ( string $path, string $contents ): void {
			file_put_contents( $path, $contents );
		};
		$this->speak = $speak ?: static function ( string $text ): void {
			exec( 'say ' . escapeshellarg( $text ) );
		};
	}

	#[Command(
		description: 'Generate a commit message from staged changes and create the commit.',
		default: true,
	)]
	public function run(
		#[Argument( description: 'Suggested commit message or context for the commit' )]
		?string $context = null,
		#[Option( description: 'Ollama model to use (default: auto-detected from storage location via config)' )]
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
		$model   = $model ?: $this->resolveModel();
		$context = (string) $context;

		if ( null !== $retry ) {
			return $this->retry( $retry, $model, $context, $retryReason, $debug );
		}

		if ( '' === ( $this->shell )( 'git diff --cached --name-only' ) ) {
			$this->cli->err( "Error: No staged changes found. Stage files with 'git add' first." );

			return 1;
		}

		$userPrompt = $this->changeContext();
		if ( '' !== $context ) {
			$userPrompt .= "\n\nContext from the developer: {$context}";
		}

		$commitMsg = $this->generate( $model, $this->buildSystemPrompt(), $userPrompt, $debug );
		if ( null === $commitMsg ) {
			return 1;
		}

		return $this->commit( $commitMsg, ! $noprint, $say );
	}

	/**
	 * Regenerate one commit's message, critiquing the existing one first.
	 *
	 * Only HEAD is amended in place; for anything older the new message is
	 * printed for the user to apply themselves, since rewriting an earlier
	 * commit would rewrite every commit after it.
	 *
	 * @param string $ref Commit to retry, or '' for a bare --retry (meaning HEAD).
	 */
	private function retry(
		string $ref,
		string $model,
		string $context,
		?string $reason,
		bool $debug
	): int {
		$commitRef = '' === $ref ? 'HEAD' : $ref;

		// --verify, and the ^{commit} peel, are what make an unresolvable ref look
		// unresolvable: plain `git rev-parse <bad-ref>` echoes the ref straight
		// back on stdout, so the guard below could never fire.
		$fullHash = ( $this->shell )(
			'git rev-parse --verify ' . escapeshellarg( $commitRef . '^{commit}' ) . ' 2>/dev/null'
		);
		if ( '' === $fullHash ) {
			$this->cli->err( "Error: Could not resolve commit '{$commitRef}'." );

			return 1;
		}

		$shortHash = ( $this->shell )( 'git rev-parse --short ' . escapeshellarg( $fullHash ) );
		$oldMsg    = ( $this->shell )( 'git log -1 --pretty=format:"%B" ' . escapeshellarg( $fullHash ) );
		$diff      = ( $this->shell )(
			"git diff {$fullHash}~1 {$fullHash} | head -n " . self::RETRY_DIFF_LINES
		);

		$this->cli->msg( "Analyzing commit {$shortHash} for retry...", 'yellow' );

		$critic = self::RETRY_SYSTEM_PROMPT;
		if ( ! empty( $reason ) ) {
			$critic .= "\n\nThe developer has noted the following issue: {$reason}";
			$this->cli->msg( "Retry reason given: {$reason}", 'cyan' );
		}

		$guidance = $this->callOllama(
			$model,
			$critic,
			"Commit message:\n{$oldMsg}\n\nDiff (truncated):\n{$diff}"
		);
		if ( null === $guidance ) {
			return 1;
		}
		if ( '' === $guidance ) {
			$this->cli->err( 'Error: Failed to get retry analysis from Ollama.' );

			return 1;
		}

		$this->cli->msg( 'Retry guidance:', 'cyan' );
		$this->cli->output( $guidance . "\n" );

		$correction = "Retry correction: {$guidance}";
		if ( '' !== $context ) {
			$correction = "{$context}. {$correction}";
		}

		$newMsg = $this->generate(
			$model,
			$this->buildSystemPrompt(),
			"Diff:\n{$diff}\n\nContext from the developer: {$correction}",
			$debug
		);
		if ( null === $newMsg ) {
			return 1;
		}

		if ( $fullHash !== ( $this->shell )( 'git rev-parse HEAD' ) ) {
			$this->cli->output( '' );
			$this->cli->msg( "New message for {$shortHash}:", 'green' );
			$this->cli->output( $newMsg );

			return 0;
		}

		$result = ( $this->runWithCode )( 'git commit --amend -m ' . escapeshellarg( $newMsg ) );
		if ( 0 !== $result['exitCode'] ) {
			$this->cli->err( 'Error: git commit --amend failed.' );

			return 1;
		}

		$this->cli->msg( "Amended {$shortHash}:", 'green' );
		$this->cli->output( $newMsg );

		return 0;
	}

	/** @return ?string Null when the message could not be generated; the error is already reported. */
	private function generate(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		bool $debug
	): ?string {
		if ( $debug ) {
			( $this->writeFile )(
				self::DEBUG_FILE,
				"=== MODEL ===\n{$model}\n\n"
				. "=== SYSTEM PROMPT ===\n{$systemPrompt}\n\n"
				. "=== USER PROMPT ===\n{$userPrompt}\n"
			);
			$this->cli->msg( 'Debug: prompt saved to ' . self::DEBUG_FILE, 'yellow' );
		}

		$commitMsg = $this->callOllama( $model, $systemPrompt, $userPrompt );
		if ( null === $commitMsg ) {
			return null;
		}

		if ( '' === $commitMsg ) {
			$this->cli->err( 'Error: Failed to get a commit message from Ollama.' );

			return null;
		}

		return $commitMsg;
	}

	private function commit( string $commitMsg, bool $print, bool $say ): int {
		$result = ( $this->runWithCode )( 'git commit -m ' . escapeshellarg( $commitMsg ) );

		if ( 0 !== $result['exitCode'] ) {
			$this->cli->err( 'Error: git commit failed.' );
			if ( ! empty( $result['error'] ) ) {
				$this->cli->err( $result['error'] );
			}

			return 1;
		}

		if ( $print ) {
			$this->cli->output( '' );
			$this->cli->msg(
				'Commit ' . ( $this->shell )( 'git log -1 --pretty=format:"%h"' ) . ':',
				'green'
			);
			$this->cli->output( $commitMsg );
		}

		if ( $say ) {
			( $this->speak )( "Committed: {$commitMsg}" );
		}

		return 0;
	}

	/** @return ?string Null on any Ollama failure; the error is already reported. */
	private function callOllama( string $model, string $systemPrompt, string $userPrompt ): ?string {
		$result = $this->ollama()->chat( $model, $systemPrompt, $userPrompt );

		if ( 'transport' === $result['errorType'] ) {
			$this->cli->err( "Error connecting to Ollama: {$result['error']}" );
			$this->cli->err( 'Is Ollama running?' );

			return null;
		}

		if ( 'api' === $result['errorType'] ) {
			$error = (string) $result['error'];
			$this->cli->err( "Error from Ollama: {$error}" );

			if ( $this->isModelNotFound( $error ) ) {
				$this->reportMissingModel( $model );
			}

			return null;
		}

		return $result['content'] ?? '';
	}

	/** Ollama has no error codes, so a missing model is only knowable from its wording. */
	private function isModelNotFound( string $error ): bool {
		return false !== stripos( $error, 'model' )
			&& (
				false !== stripos( $error, 'not found' )
				|| false !== stripos( $error, 'does not exist' )
				|| false !== stripos( $error, 'unknown' )
			);
	}

	/**
	 * The usual cause is a model that exists on the other storage location, so
	 * say which one is mounted alongside what is actually installed.
	 */
	private function reportMissingModel( string $model ): void {
		$this->cli->err( '' );
		$this->cli->msg( "Requested model: {$model}", 'yellow' );

		$available = ( $this->shell )( 'ollama list 2>/dev/null' );
		if ( '' !== $available ) {
			$this->cli->msg( "\nAvailable models (ollama list):", 'cyan' );
			$this->cli->output( $available );
		} else {
			$this->cli->msg( 'No models found. Pull one with: ollama pull <model>', 'yellow' );
		}

		$storage = $this->ollama()->storagePath( $this->home() );
		if ( null !== $storage ) {
			$this->cli->msg( "\nModels storage: {$storage}", 'cyan' );
			$this->cli->msg( 'Switch with: ollamodels [local|sd]', 'cyan' );
		}

		$this->cli->err( "\nTo fix:" );
		$this->cli->err( "  1. Pull the model:   ollama pull {$model}" );
		$this->cli->err( '  2. Use a different model: auto-commit-ollama --model=<name>' );
		$this->cli->err( '  3. Update config:    ~/.config/auto-commit-ollama/config' );
		$this->cli->err( '     (MODEL, MODEL_LOCAL, MODEL_SD)' );
	}

	/** The structured diff summary pr-description-generator already knows how to build. */
	private function changeContext(): string {
		return ( $this->shell )(
			"'" . dirname( __DIR__ ) . "/bin/pr-description-generator' --staged --smart 2>/dev/null"
		);
	}

	private function buildSystemPrompt(): string {
		$recentCommits = ( $this->shell )( 'git log --format="%h %s%n%b" -5 2>/dev/null' );

		return "You are a commit message generator. Output ONLY the commit message, nothing else. The subject line (first line) must be plain text — no markdown, no prefixes like ## or *, no backticks, no quotes. Keep it under 72 characters, starting with a lowercase verb (e.g. add, fix, update, refactor).

The body (after a blank line) may use bullet points or other formatting.

You MUST add a commit body (separated by a blank line from the subject) when:
- Changes span multiple files with different purposes
- The subject line alone cannot describe all the changes
- The why behind the change is not obvious from the diff

The body should briefly list or explain each distinct change. Keep it concise — use bullet points if multiple changes are involved. A simple single-purpose change does not need a body.

Here are recent commits from this repo. Match their style:
<recent-commits>
{$recentCommits}
</recent-commits>";
	}

	private function resolveModel(): string {
		return $this->ollama()->resolveModel(
			$this->ollama()->config(),
			Ollama::DEFAULT_MODEL,
			$this->home()
		);
	}

	private function home(): string {
		return getenv( 'HOME' ) ?: (string) $this->cli->convertPathToAbsolute( '~' );
	}

	private function ollama(): Ollama {
		if ( null === $this->ollama ) {
			$this->ollama = new Ollama();
		}

		return $this->ollama;
	}
}
