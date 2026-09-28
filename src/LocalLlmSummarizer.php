<?php
namespace JT;

use JT\Helpers\Ollama;

/**
 * Summarize text, or an archived agent session, with a local Ollama model.
 *
 * The session prompt and TITLE format come from awesomemotive/claude-sessions-board
 * (src/llm.js), where they were benchmarked across local models: gemma4:26b-nvfp4
 * held the format best, qwen3.5:9b is the fallback that lives on both stores, and
 * code models copied the input title verbatim. The generic prompt is the one
 * bin/llmsummarize uses.
 *
 * The model resolves through the shared ~/.config/auto-commit-ollama/config under
 * its own SUMMARY_MODEL* keys, because the commit tool's MODEL* keys name code models.
 */
class LocalLlmSummarizer {

	const CONFIG_PREFIX = 'SUMMARY_MODEL';

	/** Last resort when the config names nothing: present in the local store, so it survives an AI-LAB eject. */
	const DEFAULT_MODEL = 'qwen3.5:9b';

	/** A model Ollama has unloaded takes ~30s to load back before it writes anything. */
	const TIMEOUT_SECONDS = 90;

	/** The digest is small by construction; this is headroom, and lifts gemma4:e4b's silent 16K cap. */
	const SESSION_NUM_CTX = 8192;

	const TEXT_MIN_CTX = 8192;
	const TEXT_MAX_CTX = 131072;

	const PROMPT_CHARS     = 240;
	const REPLY_CHARS      = 300;
	const LAST_REPLY_CHARS = 700;
	const HEAD_PROMPTS     = 4;
	const TAIL_PROMPTS     = 8;
	const TAIL_REPLIES     = 3;
	const ACTION_CHARS     = 100;
	const TAIL_ACTIONS     = 12;
	const NOTES_CHARS      = 4000;
	const NUM_PREDICT      = 400;

	const SESSION_PROMPT = <<<'PROMPT'
You summarize a finished software engineering session between a person and an AI coding agent, for an archive of past sessions.

Reply in exactly this shape, with nothing before or after:

TITLE: <the job, 3 to 7 words>
<sentence 1> <sentence 2>

The TITLE names the work the way the person doing it would say it to a colleague.
Lead with the thing being changed and what is being done to it. Be specific
enough to tell this session apart from a similar one: "Stripe payout errors on
the Connect proxy" rather than "Investigate errors", "Q3 reviews for the WPSP
team" rather than "Q3 review files". No trailing full stop. Never use the folder
or branch name as the title, and never copy the original title.

Then 2 sentences, maximum 50 words total, in plain direct English:
- Sentence 1: the overall job the session was doing, from the whole session.
- Sentence 2: where it was left or what it ended up producing, in past tense.

Rules: no preamble, no "this session", no bullet points, no markdown, no em
dashes. Name concrete things (files, features, branches) over generic phrases.
Always write both using whatever evidence is present, even if it is thin.
If the person's own notes appear, they are the most reliable account
of what the session was for: let them decide the TITLE and sentence 1, and use
the transcript only to fill in the rest.

Session data follows.
PROMPT;

	const TEXT_SYSTEM = 'You are a summarization engine. You describe documents in neutral third-person prose. '
		. "You never reproduce a document's formatting, and you never address the reader.";

	const TEXT_TASK = 'TASK: In one short paragraph of 2 to 4 sentences, describe what the document above is and '
		. 'what it contains. Write plain prose sentences only. Do not use headings, bullets, tables, bold, or '
		. 'emoji. Refer to it as "the document" or "the file"; never say "you" or "we".';

	private ?Ollama $ollama;

	public function __construct( ?Ollama $ollama = null, private ?string $home = null ) {
		$this->ollama = $ollama;
	}

	/** The model a summary would use right now: explicit, else per-store config, else the default. */
	public function resolveModel( ?string $model = null ): string {
		if ( null !== $model && '' !== trim( $model ) ) {
			return trim( $model );
		}

		return $this->ollama()->resolveModel(
			$this->ollama()->config(),
			self::DEFAULT_MODEL,
			$this->home(),
			self::CONFIG_PREFIX
		);
	}

	/**
	 * Summarize an archived session transcript (the graveyard's transcript.md
	 * shape: `**You:**` / `**Claude:**` / `**Codex:**` turns) as a TITLE plus
	 * two sentences.
	 *
	 * $notes are the person's own notes on the session, placed both before and
	 * after the digest under a heading that says what to use them for. Measured
	 * 2026-09-28 on a note the transcript never states: at temperature 0.7,
	 * qwen3.5:9b used it 0/3 times with the notes last, 1/3 first, 3/3 at both
	 * ends; at temperature 0 it needed the directive heading too. gemma4:26b-nvfp4
	 * used it in every placement.
	 *
	 * @return array{title:?string, text:?string, model:string, durationMs:int, error:?string, errorType:?string}
	 */
	public function summarizeSession( string $markdown, ?string $model = null, string $notes = '' ): array {
		$model  = $this->resolveModel( $model );
		$digest = $this->digestTranscript( $markdown );
		$notes  = trim( $notes );

		if ( '' === $digest && '' === $notes ) {
			return $this->failure( $model, 'the transcript has no conversation to summarize', 'input' );
		}

		$section = '' === $notes ? '' : "## What this session was for, in the person's own words (base the TITLE and sentence 1 on this)\n"
			. $this->clip( $notes, self::NOTES_CHARS ) . "\n";
		$prompt  = self::SESSION_PROMPT . "\n\n"
			. ( '' === $section ? '' : $section . "\n" )
			. ( '' === $digest ? '' : $digest . "\n" )
			. ( '' === $section ? '' : "\n" . $section );

		return $this->run( $model, '', $prompt, self::SESSION_NUM_CTX );
	}

	/**
	 * Describe arbitrary text in 2 to 4 sentences. The context window is sized
	 * to the input so a long file is not silently truncated.
	 *
	 * @return array{title:?string, text:?string, model:string, durationMs:int, error:?string, errorType:?string}
	 */
	public function summarizeText( string $text, ?string $model = null ): array {
		$model = $this->resolveModel( $model );

		if ( '' === trim( $text ) ) {
			return $this->failure( $model, 'nothing to summarize', 'input' );
		}

		return $this->run(
			$model,
			self::TEXT_SYSTEM,
			$text . "\n\n----------\n" . self::TEXT_TASK,
			$this->textContext( $text ),
			false
		);
	}

	/**
	 * Reduce a session transcript to the facts worth sending: its original title
	 * and project, the person's own instructions, the agent's last actions, and
	 * how it left things.
	 *
	 * Turns that open with `<` are harness-injected (skill bodies, command
	 * expansions, reminders, task notifications), not the person's words.
	 */
	public function digestTranscript( string $markdown ): string {
		$title   = preg_match( '/^#[ \t]+(.+)$/m', $markdown, $m ) ? trim( $m[1] ) : '';
		$project = preg_match( '/^- cwd `([^`]+)`/m', $markdown, $m ) ? basename( trim( $m[1] ) ) : '';

		$prompts = [];
		$replies = [];
		$actions = [];
		$parts   = preg_split( '/^\*\*(You|Claude|Codex):\*\*[ \t]*/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [];
		for ( $i = 1; $i + 1 < count( $parts ); $i += 2 ) {
			$body = trim( $parts[ $i + 1 ] );
			if ( '' === $body ) {
				continue;
			}
			if ( 'You' === $parts[ $i ] ) {
				if ( '<' !== $body[0] ) {
					$prompts[] = $body;
				}
				continue;
			}

			// The archive renders a tool call as an indented `↳ \`Tool: args\`` line
			// followed by its indented output; only the unindented lines are prose.
			$prose = [];
			foreach ( explode( "\n", $body ) as $line ) {
				if ( preg_match( '/^\s+↳ /u', $line ) ) {
					preg_match_all( '/`([^`]+)`/u', $line, $calls );
					foreach ( $calls[1] as $call ) {
						$actions[] = $this->squash( $call, self::ACTION_CHARS );
					}
				} elseif ( '' !== $line && ! ctype_space( $line[0] ) ) {
					$prose[] = $line;
				}
			}
			if ( $prose ) {
				$replies[] = implode( "\n", $prose );
			}
		}

		if ( ! $prompts && ! $replies ) {
			return '';
		}

		$lines = [];
		if ( '' !== $title ) {
			$lines[] = 'Original title: ' . $this->squash( $title, self::PROMPT_CHARS );
		}
		if ( '' !== $project ) {
			$lines[] = "Project: {$project}";
		}
		$lines[] = 'Instructions given: ' . count( $prompts );

		if ( $prompts ) {
			$lines[] = '';
			$lines[] = "The person's instructions, oldest first:";
			$keep    = self::HEAD_PROMPTS + self::TAIL_PROMPTS;
			$shown   = count( $prompts ) > $keep
				? array_merge(
					array_slice( $prompts, 0, self::HEAD_PROMPTS ),
					[ null ],
					array_slice( $prompts, -self::TAIL_PROMPTS )
				)
				: $prompts;
			foreach ( $shown as $p ) {
				$lines[] = null === $p
					? '- (' . ( count( $prompts ) - $keep ) . ' more instructions omitted)'
					: '- ' . $this->squash( $p, self::PROMPT_CHARS );
			}
		}

		if ( $actions ) {
			$lines[] = '';
			$lines[] = "The agent's last actions, oldest first:";
			foreach ( array_slice( $actions, -self::TAIL_ACTIONS ) as $a ) {
				$lines[] = "- {$a}";
			}
		}

		if ( $replies ) {
			$last    = array_pop( $replies );
			$earlier = array_slice( $replies, -( self::TAIL_REPLIES - 1 ) );
			if ( $earlier ) {
				$lines[] = '';
				$lines[] = "The agent's closing replies, oldest first:";
				foreach ( $earlier as $r ) {
					$lines[] = '- ' . $this->squash( $r, self::REPLY_CHARS );
				}
			}
			$lines[] = '';
			$lines[] = "## How it ended\nThe agent's final reply: " . $this->squash( $last, self::LAST_REPLY_CHARS );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Pull the TITLE line off the front of the reply. A model that ignores the
	 * format still gives usable prose, so a missing title is not an error.
	 *
	 * @return array{title:?string, text:string}
	 */
	public static function splitTitle( string $raw ): array {
		$lines = explode( "\n", trim( $raw ) );
		$title = null;

		if ( preg_match( '/^\s*title\s*:/i', $lines[0] ) ) {
			$title = trim( (string) preg_replace(
				[ '/^\s*title\s*:/i', '/^[\s"\'\x{201c}]+|[\s"\'\x{201d}.]+$/u' ],
				'',
				array_shift( $lines )
			) );
			if ( mb_strlen( $title ) > 80 ) {
				$title = rtrim( mb_substr( $title, 0, 79 ) ) . '…';
			}
		}

		return [ 'title' => '' === $title ? null : $title, 'text' => trim( implode( "\n", $lines ) ) ];
	}

	/**
	 * @return array{title:?string, text:?string, model:string, durationMs:int, error:?string, errorType:?string}
	 */
	private function run( string $model, string $system, string $user, int $numCtx, bool $titled = true ): array {
		$started = microtime( true );
		$reply   = $this->ollama()->chat( $model, $system, $user, Ollama::DEFAULT_URL, self::TIMEOUT_SECONDS, [
			// Thinking models spend a minute reasoning about a 50-word summary and can leak it into the reply.
			'think'   => false,
			// num_predict caps a model that ignores the length rule and keeps going.
			'options' => [ 'temperature' => 0, 'num_ctx' => $numCtx, 'num_predict' => self::NUM_PREDICT ],
		] );
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( null !== $reply['error'] ) {
			return $this->failure( $model, (string) $reply['error'], (string) $reply['errorType'], $ms );
		}

		$content = trim( (string) $reply['content'] );
		if ( '' === $content ) {
			return $this->failure( $model, "{$model} returned an empty reply", 'empty', $ms );
		}

		$split = $titled ? self::splitTitle( $content ) : [ 'title' => null, 'text' => $content ];
		if ( '' === $split['text'] ) {
			return $this->failure( $model, "{$model} returned only a title", 'empty', $ms );
		}

		return [
			'title'      => $split['title'],
			'text'       => $split['text'],
			'model'      => $model,
			'durationMs' => $ms,
			'error'      => null,
			'errorType'  => null,
		];
	}

	/** @return array{title:null, text:null, model:string, durationMs:int, error:string, errorType:string} */
	private function failure( string $model, string $error, string $type, int $ms = 0 ): array {
		return [
			'title'      => null,
			'text'       => null,
			'model'      => $model,
			'durationMs' => $ms,
			'error'      => $error,
			'errorType'  => $type,
		];
	}

	/** ~4 bytes per token, plus prompt overhead and 30% headroom, in 2048-token steps. */
	private function textContext( string $text ): int {
		$tokens = (int) ( ( strlen( $text ) / 4 + 600 ) * 1.3 );
		$ctx    = (int) ( ceil( $tokens / 2048 ) * 2048 );

		return max( self::TEXT_MIN_CTX, min( self::TEXT_MAX_CTX, $ctx ) );
	}

	/** Cut without squashing whitespace, so markdown notes keep their lines. */
	private function clip( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	private function squash( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	private function home(): string {
		return $this->home ?? ( getenv( 'HOME' ) ?: '' );
	}

	private function ollama(): Ollama {
		return $this->ollama ??= new Ollama();
	}
}
