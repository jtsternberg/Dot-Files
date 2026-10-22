<?php
namespace JT;

use JT\CLI\Helpers;

/**
 * The commit/retry flow shared by every auto-commit-* backend. A subclass owns
 * its #[Program] and #[Command] attributes (the flags differ per backend) and
 * says how to ask its model for text; everything else lives here.
 */
abstract class AutoCommitCommand {

	/** Enough diff for the model to judge a message by, without blowing the context. */
	const RETRY_DIFF_LINES = 50;

	const RETRY_SYSTEM_PROMPT = 'You are a commit message critic. Given a commit message and its '
		. 'diff, briefly explain what is wrong with the message and suggest how it should be '
		. 'corrected. Output ONLY the correction guidance — no pleasantries, no preamble. Be '
		. 'specific and concise.';

	/** @var callable(string):string Shell read: trimmed stdout. */
	protected $shell;

	/** @var callable(string):array{output:string, error:string, exitCode:int} */
	protected $runWithCode;

	/** @var callable(string,string):void */
	protected $writeFile;

	/** @var callable(string):void */
	protected $speak;

	public function __construct(
		protected readonly Helpers $cli,
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

	/** Shown in "Failed to get … from <name>." errors. */
	abstract protected function backendName(): string;

	abstract protected function debugFile(): string;

	/**
	 * Raw signature template from config; {model}/<model> are filled in here.
	 */
	abstract protected function signatureTemplate(): string;

	/**
	 * @return ?string Null on a backend failure, already reported to the user;
	 *                 '' when the model answered with nothing.
	 */
	abstract protected function complete( string $model, string $systemPrompt, string $userPrompt ): ?string;

	/** Model name as it should read in the signature. */
	protected function signatureModel( string $model ): string {
		return $model;
	}

	protected function execute(
		string $model,
		?string $context,
		?string $retry,
		?string $retryReason,
		bool $debug,
		bool $say,
		bool $noprint
	): int {
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

		$guidance = $this->complete(
			$model,
			$critic,
			"Commit message:\n{$oldMsg}\n\nDiff (truncated):\n{$diff}"
		);
		if ( null === $guidance ) {
			return 1;
		}
		if ( '' === $guidance ) {
			$this->cli->err( "Error: Failed to get retry analysis from {$this->backendName()}." );

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
				$this->debugFile(),
				"=== MODEL ===\n{$model}\n\n"
				. "=== SYSTEM PROMPT ===\n{$systemPrompt}\n\n"
				. "=== USER PROMPT ===\n{$userPrompt}\n"
			);
			$this->cli->msg( 'Debug: prompt saved to ' . $this->debugFile(), 'yellow' );
		}

		$commitMsg = $this->complete( $model, $systemPrompt, $userPrompt );
		if ( null === $commitMsg ) {
			return null;
		}

		if ( '' === $commitMsg ) {
			$this->cli->err( "Error: Failed to get a commit message from {$this->backendName()}." );

			return null;
		}

		return $this->withSignature( $commitMsg, $model );
	}

	/**
	 * Appended here, not requested from the model: a prompted trailer is
	 * unreliable and would be missing from some commits.
	 */
	private function withSignature( string $commitMsg, string $model ): string {
		$signature = trim( $this->signatureTemplate() );
		if ( '' === $signature ) {
			return $commitMsg;
		}

		// Both spellings: the config file's own example uses <model>.
		$signature = str_replace( [ '{model}', '<model>' ], $this->signatureModel( $model ), $signature );

		return rtrim( $commitMsg ) . "\n\n" . $signature;
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

	protected function home(): string {
		return getenv( 'HOME' ) ?: (string) $this->cli->convertPathToAbsolute( '~' );
	}
}
