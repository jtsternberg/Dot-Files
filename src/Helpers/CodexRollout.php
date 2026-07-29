<?php
namespace JT\Helpers;

/**
 * Read an OpenAI Codex CLI "rollout" .jsonl and present it as the markdown transcript
 * archive the Claude bury path already writes (dotfiles-6me).
 *
 * WHY A MARKDOWN ARCHIVE, and not a second rendering path. graveyard's readers are all
 * built on one interchange format: bury writes markdown, `graveyard show` opens it,
 * `search --full-text` greps it, the page modal runs it through TuiTranscript, and
 * resurrect hands its path to a fresh agent. Emitting that same format means codex
 * archives light up every one of those without a line of change in any of them. The raw
 * rollout stays on disk untouched, so nothing here is lossy in the archive sense — this
 * is a VIEW.
 *
 * WHY NOT SHELL OUT, the way the Claude path shells out to export-session.mjs. There is no
 * native equivalent: codex has no session-dump subcommand (checked every subcommand and
 * the hidden ones in the generated completion; upstream's export request is still open).
 * The one native mechanism, `codex app-server` + `thread/read`, is worse on every axis —
 * it is not a CLI verb, a cold start MUTATES the codex home (four SQLite DBs plus a git
 * clone of the plugin marketplace), and it is strictly LESS faithful than the file it
 * reads: codex builds that projection from event_msg records only, and exec events are
 * marked non-durable, so on a real session 1198 function_call + 183 custom_tool_call
 * records render as ZERO tool calls. Parsing the file beats codex's own resume preview.
 *
 * THE FORMAT IS UNDOCUMENTED AND DRIFTS. There is no schema upstream; the Rust structs are
 * the only spec, and across ten months and 30 cli_versions the shell tool alone was renamed
 * twice AND changed record type (shell_command → exec_command → exec, the last one moving
 * from function_call to custom_tool_call). So: dispatch on name with a default branch,
 * count what we do not recognise instead of failing, and treat every field as optional.
 * The rules encoded here were measured over the whole corpus, not inferred from one file.
 *
 * EVERYTHING STREAMS. Rollouts run to 60 MB (7 files over 10 MB in one corpus), and live
 * sessions are appended to while being read, so a truncated final line is expected rather
 * than exceptional. Nothing here holds a whole file in memory and nothing throws on a bad
 * line.
 */
class CodexRollout {

	/**
	 * Assistant text is written TWICE — as response_item/message and again as
	 * event_msg/agent_message, roughly 1:1 — so rendering both doubles every turn. We keep
	 * the response_item lane: in codex's Legacy history mode the event lane is the lossy
	 * one (it carries no exec calls at all), so preferring it would inherit the very gap
	 * that makes codex's own preview useless. The event lane is the FALLBACK, used only
	 * when a rollout has no response_item messages to render.
	 */
	const SPEAKER = 'Codex';

	/** Wrapper-only turns: machine-injected context, not anything a human or model said. */
	const SYNTHETIC_PREFIXES = [
		'<environment_context>',
		'<turn_aborted>',
		'<command-message>',
		'<command-args>',
		'<local-command-stdout>',
		'<local-command-caveat>',
		'<permissions instructions>',
		'<system-reminder>',
		'Caveat: The messages below',
	];

	/**
	 * Argument keys that carry the human-readable gist of a tool call, best first. Mirrors
	 * the hint order export-session.mjs uses for Claude tools so both archives read alike.
	 */
	const HINT_KEYS = ['command', 'cmd', 'file_path', 'path', 'pattern', 'query', 'url', 'description', 'prompt', 'input'];

	/** Cache of the per-file parse, keyed by path + mtime + size. */
	private array $cache = [];

	/** Unrecognised record types seen in a file, type => count. */
	private array $unknown = [];

	# =========================================================================
	# Public surface
	# =========================================================================

	/**
	 * Session facts for the archive header. Streams only as far as it must: session_meta is
	 * the first record and turn_context lands with the first turn, so a 60 MB rollout costs
	 * a few KB to describe.
	 *
	 * `model` is null for ~45% of real sessions — Codex Desktop simply does not emit
	 * turn_context, regardless of version — so callers must treat it as absent-capable
	 * rather than assuming an old-version quirk that will age out.
	 */
	public function meta(string $path): array {
		$out = [
			'session_id' => null, 'cwd' => null, 'model' => null, 'originator' => null,
			'cli_version' => null, 'source' => null, 'started_at' => null,
		];
		$seenMeta = false;

		$this->eachRecord($path, function (array $rec) use (&$out, &$seenMeta): bool {
			$type = (string) ($rec['type'] ?? '');
			$p    = $this->payload($rec);

			// Forks and resumes concatenate — one real rollout carries 55 session_meta
			// lines — and the FIRST is the session this file belongs to.
			if ($type === 'session_meta' && !$seenMeta) {
				$seenMeta = true;
				// Upstream's own deserializer back-fills session_id from `id` for rollouts
				// written before the rename; the oldest session here predates it.
				$out['session_id']  = $this->str($p['session_id'] ?? $p['id'] ?? null);
				$out['cwd']         = $this->str($p['cwd'] ?? null);
				$out['originator']  = $this->str($p['originator'] ?? null);
				$out['cli_version'] = $this->str($p['cli_version'] ?? null);
				// `source` is an enum upstream and deserializes as an OBJECT for subagent
				// threads. Flatten rather than fail.
				$out['source']      = $this->flatten($p['source'] ?? null);
				$out['started_at']  = $this->str($rec['timestamp'] ?? $p['timestamp'] ?? null);
			}

			if ($type === 'turn_context' && $out['model'] === null) {
				$out['model'] = $this->str($p['model'] ?? null);
			}

			return !($seenMeta && $out['model'] !== null); // stop once both are known
		}, 2000);

		return $out;
	}

	/**
	 * The conversation, in the shape Graveyard::genuineTurns() produces for Claude —
	 * [['role' => 'user'|'assistant', 'text' => string, 'tools' => [...]], ...] — so the
	 * existing turn renderer can print a codex session with no changes.
	 */
	public function genuineTurns(string $path): array {
		return array_values(array_filter(
			$this->timeline($path),
			fn(array $i) => $i['kind'] === 'turn'
		));
	}

	/** Unrecognised record types and their counts, so drift shows up instead of vanishing. */
	public function unknownCounts(string $path): array {
		$this->timeline($path);
		return $this->unknown[$this->key($path)] ?? [];
	}

	/**
	 * The first plausible user prompt, RAW — wrappers intact.
	 *
	 * Deliberately unstripped: Graveyard::summarizeUserText() is what turns
	 * "<command-name>/foo</command-name>" into "/foo", and that is the title a
	 * slash-command session should carry. Stripping here would throw it away and leave the
	 * tombstone named after its directory, which is the bug this exists to fix.
	 *
	 * Stops at the first hit, so it never reads past the top of a huge rollout.
	 */
	public function firstUserText(string $path): string {
		$found = '';

		$this->eachRecord($path, function (array $rec) use (&$found): bool {
			$p = $this->payload($rec);

			$text = null;
			if ((string) ($rec['type'] ?? '') === 'response_item' && ($p['type'] ?? '') === 'message') {
				if (($p['role'] ?? '') !== 'user') { return true; }
				$text = $this->contentText($p['content'] ?? null);
			} elseif ((string) ($rec['type'] ?? '') === 'event_msg' && ($p['type'] ?? '') === 'user_message') {
				$text = $this->str($p['message'] ?? null);
			} elseif (($rec['type'] ?? '') === 'message' && ($rec['role'] ?? '') === 'user') {
				$text = $this->contentText($rec['content'] ?? null); // pre-envelope shape
			}

			if ($text === null) { return true; }
			$text = trim($text);
			// Injected context is not a prompt — but a slash-command wrapper IS, so only
			// the whole-text noise forms are skipped here.
			if ($text === '' || $this->isInjectedContext($text)) { return true; }

			$found = $text;
			return false;
		});

		return $found;
	}

	/**
	 * The rollout as a markdown archive, byte-compatible with what the Claude bury path
	 * writes so every existing reader consumes it unchanged.
	 *
	 * Turn bodies are written INDENTED, which is load-bearing rather than cosmetic:
	 * TuiTranscript treats `##` and `---` as structure at column 0 only, and codex prose is
	 * markdown-heavy, so an unindented body would cut its own turn in half and leave the
	 * following tool calls leaking into the page as literal `↳` text.
	 */
	public function toMarkdownArchive(string $path, array $meta = []): string {
		if (!is_file($path)) { return ''; }

		$items = $this->timeline($path);
		$m     = $meta + $this->meta($path);
		$sid   = (string) ($m['session_id'] ?? '');
		$cwd   = (string) ($m['cwd'] ?? '');

		$last = $m['started_at'];
		foreach ($items as $i) {
			if (!empty($i['at'])) { $last = $i['at']; }
		}

		$title = (string) ($m['title'] ?? '');
		if ($title === '') { $title = $sid !== '' ? substr($sid, 0, 8) : 'codex session'; }

		$out = ['# ' . $title, ''];
		if ($sid !== '') { $out[] = '- session `' . $sid . '`'; }
		$out[] = '- cwd `' . ($cwd !== '' ? $cwd : '(unknown)') . '`';
		$out[] = '- ' . ($m['started_at'] ?: '?') . ' → ' . ($last ?: '?');
		$out[] = '';

		foreach ($items as $i) {
			if ($i['kind'] === 'compaction') {
				$out[] = '---';
				$out[] = '';
				$out[] = '### ⟲ Context compacted';
				$out[] = '';
				if (trim((string) $i['text']) !== '') {
					foreach ($this->bodyLines((string) $i['text']) as $ln) { $out[] = $ln; }
					$out[] = '';
				}
				continue;
			}

			$speaker = $i['role'] === 'user' ? 'You' : self::SPEAKER;
			$body    = $this->bodyLines((string) $i['text']);
			$first   = array_shift($body);
			$out[]   = '**' . $speaker . ':**' . ($first === null || trim($first) === ''
				? ' _(no text — tool calls only)_'
				: ' ' . ltrim($first));
			foreach ($body as $ln) { $out[] = $ln; }

			foreach ($i['tools'] as $t) {
				$out[] = '  ↳ `' . $this->oneLine((string) $t['label'], 400) . '`';
				if (trim((string) $t['output']) === '') { continue; }
				foreach (explode("\n", $this->clip((string) $t['output'], 2000)) as $ln) {
					// Exactly six spaces: TuiTranscript needs 4+ to see a result block, and
					// this is the width the Claude emitter uses.
					$out[] = '      ' . $ln;
				}
			}
			$out[] = '';
		}

		return rtrim(implode("\n", $out), "\n") . "\n";
	}

	# =========================================================================
	# Parsing
	# =========================================================================

	/**
	 * The ordered timeline: turn and compaction items.
	 *
	 * Both lanes are collected in one pass and the choice is made at the end, because
	 * whether the event lane is a duplicate or the only copy is not knowable until the file
	 * has been read.
	 */
	protected function timeline(string $path): array {
		$key = $this->key($path);
		if (isset($this->cache[$key])) { return $this->cache[$key]; }

		$primary = [];   // response_item lane — preferred, carries tool calls
		$events  = [];   // event_msg lane — fallback when the primary has no messages
		$byCall  = [];   // call_id => [lane, turn index, tool index]
		$this->unknown[$key] = [];

		$this->eachRecord($path, function (array $rec) use (&$primary, &$events, &$byCall, $key): bool {
			$type = (string) ($rec['type'] ?? '');
			$p    = $this->payload($rec);
			$at   = $this->str($rec['timestamp'] ?? null);

			// The oldest rollout predates the {type, payload} envelope: its records are
			// bare `{"type":"message",…}`. Treat those as response items.
			if (in_array($type, ['message', 'reasoning', 'function_call', 'function_call_output',
				'custom_tool_call', 'custom_tool_call_output', 'local_shell_call'], true)) {
				$this->absorbResponseItem($rec + ['type' => $type], $at, $primary, $byCall);
				return true;
			}

			switch ($type) {
				case 'session_meta':
				case 'turn_context':
				case 'world_state':
				case 'inter_agent_communication':
				case 'inter_agent_communication_metadata':
					return true; // metadata, not conversation

				case 'response_item':
					$this->absorbResponseItem($p, $at, $primary, $byCall, $key);
					return true;

				case 'event_msg':
					$this->absorbEventMsg($p, $at, $primary, $events, $key);
					return true;

				case 'compacted':
					$item = ['kind' => 'compaction', 'at' => $at, 'text' => $this->str($p['message'] ?? '') ?? ''];
					$primary[] = $item;
					$events[]  = $item;
					return true;

				default:
					if ($type !== '') { $this->bumpUnknown($key, $type); }
					return true;
			}
		});

		$hasPrimaryTurns = (bool) array_filter($primary, fn($i) => $i['kind'] === 'turn');
		$items = $hasPrimaryTurns ? $primary : $events;

		// Drop turns that ended up with neither text nor tools (pure noise).
		$items = array_values(array_filter($items, fn($i) => $i['kind'] !== 'turn'
			|| trim((string) $i['text']) !== '' || $i['tools']));

		return $this->cache[$key] = $items;
	}

	/** Fold one response_item into the primary lane. */
	protected function absorbResponseItem(array $p, ?string $at, array &$lane, array &$byCall, string $key = ''): void {
		switch ((string) ($p['type'] ?? '')) {
			case 'message':
				$role = (string) ($p['role'] ?? '');
				// `developer` carries harness-injected permissions/memory blocks.
				if ($role !== 'user' && $role !== 'assistant') { return; }
				$text = trim((string) $this->contentText($p['content'] ?? null));
				// A slash-command invocation IS the prompt — keep it as "/name args", the
				// way the Claude emitter does, instead of dropping the opening turn.
				$slash = $this->slashCommand($text);
				if ($slash !== null) {
					$this->openTurn($lane, 'user', $slash, $at);
					return;
				}
				if ($text === '' || $this->isSynthetic($text)) { return; }

				// Imported Codex Desktop sessions have no tool records at all: their tool
				// traffic is [external_agent_tool_call: X] markers inside the message text,
				// and the RESULT arrives as its own message wrapped in
				// [external_agent_tool_result]. A result is output, not speech.
				$lifted = $this->liftExternalToolCalls($text);
				foreach ($lifted['results'] as $r) { $this->applyPendingOutput($lane, $r); }
				if (trim($lifted['text']) === '' && !$lifted['tools']) { return; }
				$this->openTurn($lane, $role === 'user' ? 'user' : 'assistant', $lifted['text'], $at);
				foreach ($lifted['tools'] as $t) { $this->attachTool($lane, $t, $byCall, null); }
				return;

			case 'reasoning':
				// encrypted_content is an opaque blob — there is no plaintext reasoning to
				// render. Only a non-empty summary is usable.
				$text = trim((string) $this->contentText($p['summary'] ?? null));
				if ($text === '') { return; }
				$this->openTurn($lane, 'assistant', $text, $at);
				return;

			case 'function_call':
			case 'custom_tool_call':
			case 'local_shell_call':
			case 'web_search_call':
			case 'tool_search_call':
			case 'image_generation_call':
				$this->attachTool($lane, [
					'label'  => $this->toolLabel($p),
					'output' => '',
				], $byCall, $this->str($p['call_id'] ?? null));
				return;

			case 'function_call_output':
			case 'custom_tool_call_output':
			case 'tool_search_output':
				$this->applyOutput($lane, $byCall, $this->str($p['call_id'] ?? null),
					$this->unwrapExecEnvelope($this->outputText($p['output'] ?? null)));
				return;

			case 'compaction':
			case 'context_compaction':
				$lane[] = ['kind' => 'compaction', 'at' => $at, 'text' => $this->str($p['message'] ?? '') ?? ''];
				return;

			// Measured in the corpus and deliberately not rendered: ghost_snapshot is
			// internal state, and response_item/agent_message is sub-agent mail whose body
			// is an encrypted blob. Listing them keeps the drift counter meaningful.
			case 'ghost_snapshot':
			case 'agent_message':
				return;

			default:
				$t = (string) ($p['type'] ?? '');
				if ($t !== '' && $key !== '') { $this->bumpUnknown($key, 'response_item/' . $t); }
				return;
		}
	}

	/**
	 * Fold one event_msg. Text lanes go to the FALLBACK list (they duplicate response
	 * items); a compaction boundary goes to both, since the primary lane has no other
	 * record of it in most sessions.
	 */
	protected function absorbEventMsg(array $p, ?string $at, array &$primary, array &$events, string $key): void {
		$type = (string) ($p['type'] ?? '');

		switch ($type) {
			case 'user_message':
			case 'agent_message':
			case 'agent_reasoning':
			case 'agent_reasoning_raw_content':
				$text = trim((string) ($this->str($p['message'] ?? $p['text'] ?? null) ?? ''));
				if ($text === '' || $this->isSynthetic($text)) { return; }
				$lifted = $this->liftExternalToolCalls($text);
				$noop   = [];
				foreach ($lifted['results'] as $r) { $this->applyPendingOutput($events, $r); }
				if (trim($lifted['text']) === '' && !$lifted['tools']) { return; }
				$this->openTurn($events, $type === 'user_message' ? 'user' : 'assistant', $lifted['text'], $at);
				foreach ($lifted['tools'] as $t) { $this->attachTool($events, $t, $noop, null); }
				return;

			case 'context_compacted':
				// Bare in the wild: `{"type":"context_compacted"}` and nothing else. The
				// boundary still matters even with no summary to show.
				$item = ['kind' => 'compaction', 'at' => $at, 'text' => $this->str($p['message'] ?? '') ?? ''];
				$primary[] = $item;
				$events[]  = $item;
				return;

			// Lifecycle/accounting records that are not conversation, and are not drift.
			case 'token_count':
			case 'task_started':
			case 'task_complete':
			case 'turn_started':
			case 'turn_complete':
			case 'turn_aborted':
			case 'thread_settings_applied':
			case 'thread_goal_updated':
			case 'thread_rolled_back':
			case 'sub_agent_activity':
			case 'patch_apply_end':
			case 'mcp_tool_call_end':
			case 'web_search_end':
			case 'image_generation_end':
			case 'entered_review_mode':
			case 'exited_review_mode':
			case 'exec_command_begin':
			case 'exec_command_end':
			case 'thread_name_updated':
			// item_completed is codex's PAGINATED history mode, already appearing in the
			// wild ahead of the migration. When that mode becomes the default it will carry
			// full turn items and belongs on the primary spine — until then the
			// response_item lane already has everything it duplicates.
			case 'item_completed':
				return;

			default:
				if ($type !== '') { $this->bumpUnknown($key, 'event_msg/' . $type); }
				return;
		}
	}

	# =========================================================================
	# Turn assembly
	# =========================================================================

	/**
	 * Start a turn, or extend the one above it.
	 *
	 * Mirrors the Claude merge rule: a tool-only assistant turn absorbs the text that
	 * follows it, but an assistant record carrying its own text after text starts a new
	 * turn — otherwise a long exchange collapses into one wall.
	 */
	protected function openTurn(array &$lane, string $role, string $text, ?string $at): void {
		$last = $lane ? $lane[count($lane) - 1] : null;
		if ($last && $last['kind'] === 'turn' && $last['role'] === $role && trim((string) $last['text']) === '') {
			$lane[count($lane) - 1]['text'] = $text;
			$lane[count($lane) - 1]['at']   = $at ?: $last['at'];
			return;
		}
		$lane[] = ['kind' => 'turn', 'role' => $role, 'text' => $text, 'tools' => [], 'at' => $at];
	}

	/** Attach a tool call to the current assistant turn, opening one if there is none. */
	protected function attachTool(array &$lane, array $tool, array &$byCall, ?string $callId): void {
		$idx = count($lane) - 1;
		if ($idx < 0 || $lane[$idx]['kind'] !== 'turn' || $lane[$idx]['role'] !== 'assistant') {
			$lane[] = ['kind' => 'turn', 'role' => 'assistant', 'text' => '', 'tools' => [], 'at' => null];
			$idx = count($lane) - 1;
		}
		$lane[$idx]['tools'][] = ['label' => $tool['label'], 'output' => $tool['output'] ?? ''];
		if ($callId !== null && $callId !== '') {
			$byCall[$callId] = [$idx, count($lane[$idx]['tools']) - 1];
		}
	}

	/**
	 * Land a tool result on the call it belongs to, matched by call_id rather than
	 * adjacency — codex interleaves parallel calls, so the record after a call is often
	 * another call's output.
	 */
	protected function applyOutput(array &$lane, array &$byCall, ?string $callId, string $text): void {
		if ($callId === null || !isset($byCall[$callId])) { return; }
		[$turn, $tool] = $byCall[$callId];
		if (!isset($lane[$turn]['tools'][$tool])) { return; }
		$lane[$turn]['tools'][$tool]['output'] = $text;
	}

	# =========================================================================
	# Labels + text
	# =========================================================================

	/**
	 * A tool call's one-line label, "name: gist".
	 *
	 * Dispatch is on `name` with a default branch on purpose. The shell tool alone has been
	 * shell_command, then exec_command, then exec — and the last rename moved it from
	 * function_call (JSON in `arguments`) to custom_tool_call (a RAW string in `input`).
	 * A name nobody has seen yet still renders by name rather than disappearing.
	 */
	public function toolLabel(array $p): string {
		$name = (string) ($this->str($p['name'] ?? null) ?? 'tool');

		// custom_tool_call carries a raw string, not JSON. apply_patch's input is a patch
		// body, so json_decode yields null and a JSON-only reader loses the call entirely.
		$raw = $this->str($p['input'] ?? null);
		if ($raw !== null && $raw !== '') {
			if ($name === 'apply_patch' && preg_match('/^\*\*\* (?:Add|Update|Delete) File: (.+)$/m', $raw, $m)) {
				return $name . ': ' . trim($m[1]);
			}
			$decoded = json_decode($raw, true);
			$hint    = is_array($decoded) ? $this->hint($decoded) : $this->firstLine($raw);
			return $hint === '' ? $name : $name . ': ' . $hint;
		}

		$args = $p['arguments'] ?? null;
		if (is_string($args)) { $args = json_decode($args, true); }
		$hint = is_array($args) ? $this->hint($args) : '';
		if ($hint === '' && isset($p['query'])) { $hint = (string) $this->flatten($p['query']); }

		return $hint === '' ? $name : $name . ': ' . $hint;
	}

	/** The human-readable gist of a decoded argument bag. */
	protected function hint(array $args): string {
		foreach (self::HINT_KEYS as $k) {
			if (!isset($args[$k])) { continue; }
			$v = $args[$k];
			// `command` is sometimes an argv array (["bash","-lc","…"]).
			if (is_array($v)) { $v = implode(' ', array_map(fn($x) => (string) $this->flatten($x), $v)); }
			$v = trim((string) $this->flatten($v));
			if ($v !== '') { return $this->oneLine($v, 400); }
		}
		return '';
	}

	/**
	 * Pull [external_agent_tool_call: X] … [/external_agent_tool_call] blocks out of message
	 * text and return them as real tool calls.
	 *
	 * This is the IMPORTED dialect: a Codex Desktop session has no function_call records at
	 * all, and the one codex session already buried in the live store is exactly this
	 * shape. Left as prose, its whole tool history reads as noise.
	 */
	public function liftExternalToolCalls(string $text): array {
		if (!str_contains($text, '[external_agent_tool_')) {
			return ['text' => $text, 'tools' => [], 'results' => []];
		}

		$tools   = [];
		$results = [];

		// The invocation. Its body is the CALL (description:/command: lines), never the
		// result — that arrives separately — so it names the tool and produces no output.
		$clean = preg_replace_callback(
			'/\[external_agent_tool_call:\s*([^\]]*)\]\s*(.*?)\s*\[\/external_agent_tool_call\]/s',
			function (array $m) use (&$tools): string {
				$name = trim($m[1]) !== '' ? trim($m[1]) : 'tool';
				$gist = $this->externalCallGist(trim($m[2]));
				$tools[] = ['label' => $gist === '' ? $name : $name . ': ' . $gist, 'output' => ''];
				return '';
			},
			$text
		);

		$clean = preg_replace_callback(
			'/\[external_agent_tool_result[^\]]*\]\s*(.*?)\s*\[\/external_agent_tool_result\]/s',
			function (array $m) use (&$results): string {
				$body = trim($m[1]);
				if ($body !== '') { $results[] = $body; }
				return '';
			},
			(string) $clean
		);

		// An unterminated marker (a truncated live write, or a result whose closing tag was
		// clipped) would otherwise survive as prose.
		$clean = preg_replace('#\[/?external_agent_tool_(?:call|result)[^\]]*\]#', '', (string) $clean);

		return [
			'text'    => trim(preg_replace("/\n{3,}/", "\n\n", (string) $clean)),
			'tools'   => $tools,
			'results' => $results,
		];
	}

	/**
	 * The gist of an imported call body. Its lines are "description: …" and "command: …";
	 * the command is what actually ran, so it wins — labelling by first line names the
	 * description instead and buries what you want to read.
	 */
	protected function externalCallGist(string $body): string {
		if (preg_match('/^\s*command:\s*(.+)$/mis', $body, $m)) { return $this->oneLine($m[1], 400); }
		if (preg_match('/^\s*description:\s*(.+)$/mi', $body, $m)) { return $this->oneLine($m[1], 400); }
		// Any other "key: value" first line: the key is scaffolding, the value is the gist.
		$first = $this->firstLine($body);
		return preg_match('/^[a-z_]{2,20}:\s*(\S.*)$/i', $first, $m) ? $this->oneLine($m[1], 400) : $first;
	}

	/** Land an imported tool result on the most recent call still waiting for output. */
	protected function applyPendingOutput(array &$lane, string $output): void {
		for ($i = count($lane) - 1; $i >= 0; $i--) {
			if (($lane[$i]['kind'] ?? '') !== 'turn') { continue; }
			for ($j = count($lane[$i]['tools'] ?? []) - 1; $j >= 0; $j--) {
				if (trim((string) $lane[$i]['tools'][$j]['output']) !== '') { return; }
				$lane[$i]['tools'][$j]['output'] = $output;
				return;
			}
		}
	}

	/** Concatenate a content array's text parts. Handles input_text/output_text/summary_text. */
	protected function contentText($content): ?string {
		if (is_string($content)) { return $content; }
		if (!is_array($content)) { return null; }

		$parts = [];
		foreach ($content as $c) {
			if (is_string($c)) { $parts[] = $c; continue; }
			if (!is_array($c)) { continue; }
			// Never surface an encrypted blob.
			if (($c['type'] ?? '') === 'encrypted_content') { continue; }
			$t = $this->str($c['text'] ?? null);
			if ($t !== null && $t !== '') { $parts[] = $t; }
		}
		return $parts ? implode("\n", $parts) : null;
	}

	/**
	 * Strip exec_command's accounting preamble — "Chunk ID / Wall time / Process exited with
	 * code / Original token count / Output:" — and keep what the command actually printed.
	 * A non-zero exit is the one part worth surfacing, so it survives as a "⚠ exit N" line,
	 * matching how the Claude emitter flags an errored tool result.
	 */
	public function unwrapExecEnvelope(string $text): string {
		// Anchored to the block, not to the start of the output: exec sometimes echoes the
		// command before its envelope, so requiring "Chunk ID:" first leaves the machinery
		// in place for exactly the calls whose output is worth reading.
		// Matched by SHAPE, not by field name: "Chunk ID:", then a short run of header lines,
		// terminated by a bare "Output:". Enumerating the fields is what this format
		// punishes — the run carries "Process exited with code 0" on a finished command and
		// "Process running with session ID 95238" on a live one, neither of which is even
		// "Key: value", so the only safe assumption is "some lines, then Output:".
		//
		// Lazy and bounded: it stops at the FIRST "Output:" line, and the repetition cap
		// keeps a result that has no envelope at all from being scanned line by line.
		$block = '/(?:^|\n)Chunk ID: \S+\n(?:[^\n]*\n){0,12}?Output:\n/';

		if (!preg_match($block, $text, $m, PREG_OFFSET_CAPTURE)) { return $text; }

		$exit = null;
		if (preg_match('/^Process exited with code (-?\d+)$/m', $m[0][0], $e)) { $exit = (int) $e[1]; }

		$before = rtrim(substr($text, 0, $m[0][1]));
		$body   = rtrim(substr($text, $m[0][1] + strlen($m[0][0])));
		// Recurse: a chunked read emits several envelopes back to back.
		$body   = $this->unwrapExecEnvelope($body);

		$parts = [];
		if ($exit !== null && $exit !== 0) { $parts[] = "⚠ exit {$exit}"; }
		if ($before !== '') { $parts[] = $before; }
		if ($body !== '')   { $parts[] = $body; }

		return implode("\n", $parts);
	}

	/** A tool result, which may be a plain string or a content array. */
	protected function outputText($output): string {
		if (is_string($output)) { return $output; }
		$t = $this->contentText($output);
		if ($t !== null) { return $t; }
		return is_scalar($output) ? (string) $output : '';
	}

	# =========================================================================
	# Noise
	# =========================================================================

	/**
	 * "/name args" when this text is a slash-command invocation, else null. Mirrors
	 * Graveyard::summarizeUserText()'s extraction so a command names itself the same way in
	 * a turn, a summary, and a headstone.
	 */
	public function slashCommand(string $text): ?string {
		if (!preg_match('#<command-name>\s*/?([^<]+?)\s*</command-name>#i', $text, $m)) { return null; }
		$cmd = '/' . trim($m[1]);
		if (preg_match('#<command-args>\s*(.*?)\s*</command-args>#is', $text, $a) && trim($a[1]) !== '') {
			$cmd .= ' ' . trim($a[1]);
		}
		return $this->oneLine($cmd, 200);
	}

	/** Machine-injected wrapper content rather than something said. */
	public function isSynthetic(string $text): bool {
		$t = ltrim($text);
		foreach (self::SYNTHETIC_PREFIXES as $prefix) {
			if (stripos($t, $prefix) === 0) { return true; }
		}
		// A turn that is nothing but tags (a slash-command invocation, say) has no prose.
		return trim(preg_replace('/<[^>]+>/', ' ', $t)) === '';
	}

	/**
	 * Injected CONTEXT specifically — the subset firstUserText() skips. Narrower than
	 * isSynthetic() on purpose: a slash-command wrapper is a real prompt as far as the
	 * summary is concerned, and summarizeUserText() knows how to name it.
	 */
	protected function isInjectedContext(string $text): bool {
		$t = ltrim($text);
		foreach (['<environment_context>', '<turn_aborted>', '<permissions instructions>', '<system-reminder>', '<local-command-stdout>', '<local-command-caveat>'] as $prefix) {
			if (stripos($t, $prefix) === 0) { return true; }
		}
		return trim(preg_replace('/<[^>]+>/', ' ', $t)) === '' && !str_contains($t, '<command-name>');
	}

	# =========================================================================
	# Low-level streaming + helpers
	# =========================================================================

	/**
	 * Stream decoded records. $fn returns false to stop. Never throws: a line that will not
	 * decode is skipped, because a live rollout is being appended to while we read it and a
	 * truncated final line is normal rather than exceptional.
	 */
	protected function eachRecord(string $path, callable $fn, int $maxLines = 0): void {
		if (!is_file($path)) { return; }
		$fh = @fopen($path, 'rb');
		if (!$fh) { return; }

		$n = 0;
		while (($line = fgets($fh)) !== false) {
			if ($maxLines > 0 && ++$n > $maxLines) { break; }
			$line = trim($line);
			if ($line === '' || $line[0] !== '{') { continue; }
			$rec = json_decode($line, true);
			if (!is_array($rec)) { continue; }
			if ($fn($rec) === false) { break; }
		}
		fclose($fh);
	}

	/** A record's payload, falling back to the record itself for the pre-envelope shape. */
	protected function payload(array $rec): array {
		return is_array($rec['payload'] ?? null) ? $rec['payload'] : $rec;
	}

	protected function bumpUnknown(string $key, string $type): void {
		$this->unknown[$key][$type] = ($this->unknown[$key][$type] ?? 0) + 1;
	}

	/** Cache key that changes when the file does — a live rollout grows under us. */
	protected function key(string $path): string {
		clearstatcache(true, $path);
		return $path . '|' . (is_file($path) ? filemtime($path) . '|' . filesize($path) : '0');
	}

	/** Only ever return a string or null — many of these fields are optional or nested. */
	protected function str($v): ?string {
		return is_string($v) ? $v : (is_scalar($v) ? (string) $v : null);
	}

	/** Flatten a scalar-or-structure into something printable. */
	protected function flatten($v): string {
		if (is_string($v)) { return $v; }
		if (is_bool($v)) { return $v ? 'true' : 'false'; }
		if (is_scalar($v)) { return (string) $v; }
		if (is_array($v)) {
			// Name the variant rather than dumping the whole object: subagent sources
			// nest three levels deep and none of it belongs in a header line.
			$k = array_key_first($v);
			return is_string($k) ? $k : json_encode($v);
		}
		return '';
	}

	protected function oneLine(string $s, int $max): string {
		$s = trim(preg_replace('/\s+/', ' ', $s));
		return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
	}

	protected function firstLine(string $s): string {
		$lines = preg_split('/\r?\n/', trim($s));
		return $this->oneLine((string) ($lines[0] ?? ''), 400);
	}

	/** Clip an overlong tool result the way the Claude emitter does. */
	protected function clip(string $s, int $max): string {
		$s = rtrim($s);
		if (mb_strlen($s) <= $max) { return $s; }
		$cut = mb_strlen($s) - $max;
		return mb_substr($s, 0, $max) . "\n… _({$cut} chars elided)_";
	}

	/**
	 * A turn body, indented so nothing inside it can be read as document structure.
	 *
	 * One space is enough: TuiTranscript matches `##`/`---`/`**Speaker:**` at column 0
	 * only, and needs 4+ spaces before it sees a tool-result block, so a single space is
	 * invisible in the render and closes the hole. Fenced blocks pass through untouched
	 * because the renderer emits fences verbatim, which is what keeps aligned output
	 * readable.
	 */
	protected function bodyLines(string $text): array {
		$out    = [];
		$fenced = false;
		foreach (preg_split('/\r?\n/', rtrim($text)) as $line) {
			// EVERY line, fenced content included. A fenced block left at column 0 still
			// cuts the turn, because the loop that finds a turn's end tests startsTurn()
			// line by line and knows nothing about fences: one `---` or `## heading` inside
			// a code block ended the turn, and the tool labels after it were re-flowed as
			// top-level prose with their backticks stripped. Shifting every line by the same
			// single space preserves the alignment that makes fenced output worth keeping.
			if (preg_match('/^ {0,3}```/', $line)) { $fenced = !$fenced; }
			$out[] = ' ' . $line;
		}

		// An UNCLOSED fence swallows everything after the turn: the renderer stays in
		// verbatim mode, so the turn's own tool labels emerge as literal "↳ `…`" text
		// instead of rendering as calls. Codex prose opens fenced blocks constantly and does
		// not always close them (a truncated live message never will), so the writer
		// guarantees the balance rather than hoping for it.
		if ($fenced) { $out[] = ' ```'; }

		return $out;
	}
}
