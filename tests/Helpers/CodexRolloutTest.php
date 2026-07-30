<?php
namespace JT\Tests\Helpers;

use JT\Tests\TestCase;
use JT\Helpers\CodexRollout;

/**
 * dotfiles-6me: read a codex rollout .jsonl and present it as the same markdown archive
 * the Claude bury path writes, so every existing reader (`show`, --full-text search, the
 * page modal, resurrect-from-transcript) works on codex sessions with no changes.
 *
 * The format is not documented upstream; these fixtures mirror shapes measured across the
 * real corpus (223 rollouts, 30 cli_versions, ten months). Two dialects exist in the wild
 * and both are pinned here: NATIVE codex-tui sessions, whose tool traffic is
 * function_call/custom_tool_call records, and IMPORTED "Codex Desktop" sessions, whose
 * tool traffic arrives as [external_agent_tool_call: X] markers inside message text.
 */
final class CodexRolloutTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = $this->graveyardRoot . '/rollouts';
		mkdir($this->dir, 0777, true);
	}

	/** Write a rollout from record arrays (or raw strings, for malformed-line cases). */
	private function rollout(array $records, string $name = 'r.jsonl'): string
	{
		$path  = $this->dir . '/' . $name;
		$lines = array_map(fn($r) => is_string($r) ? $r : json_encode($r), $records);
		file_put_contents($path, implode("\n", $lines) . "\n");
		return $path;
	}

	private function meta(array $extra = []): array
	{
		return ['timestamp' => '2026-07-29T09:00:00.000Z', 'type' => 'session_meta', 'payload' => array_merge([
			'session_id'  => '019fa586-a9b7-7df0-a430-49907c5193f6',
			'cwd'         => '/Users/JT/.dotfiles',
			'originator'  => 'codex-tui',
			'cli_version' => '0.146.0',
			'source'      => 'cli',
		], $extra)];
	}

	private function msg(string $role, string $text, string $stamp = '2026-07-29T09:01:00.000Z'): array
	{
		$type = $role === 'assistant' ? 'output_text' : 'input_text';
		return ['timestamp' => $stamp, 'type' => 'response_item', 'payload' => [
			'type' => 'message', 'role' => $role, 'content' => [['type' => $type, 'text' => $text]],
		]];
	}

	private function reader(): CodexRollout
	{
		return new CodexRollout();
	}

	// =====================================================================
	// Session metadata
	// =====================================================================

	public function testReadsSessionMetadata(): void
	{
		$m = $this->reader()->meta($this->rollout([$this->meta(), $this->msg('user', 'hi')]));

		$this->assertSame('019fa586-a9b7-7df0-a430-49907c5193f6', $m['session_id']);
		$this->assertSame('/Users/JT/.dotfiles', $m['cwd']);
		$this->assertSame('codex-tui', $m['originator']);
	}

	/**
	 * Upstream's own deserializer back-fills session_id from `id` for old rollouts
	 * (SessionMetaLine, protocol.rs) — the oldest session on this machine predates the
	 * rename, so the reader has to do the same or it cannot identify its own archive.
	 */
	public function testBackfillsSessionIdFromTheLegacyIdKey(): void
	{
		$meta = $this->meta();
		unset($meta['payload']['session_id']);
		$meta['payload']['id'] = 'legacy-id-0001';

		$m = $this->reader()->meta($this->rollout([$meta]));

		$this->assertSame('legacy-id-0001', $m['session_id']);
	}

	/**
	 * `source` is an enum upstream, and for subagent sessions it deserializes as an OBJECT
	 * rather than a string. A reader that assumes string here dies on those files.
	 */
	public function testToleratesSourceBeingAnObject(): void
	{
		$path = $this->rollout([
			$this->meta(['source' => ['subagent' => ['thread_spawn' => ['depth' => 1, 'agent_nickname' => 'Herschel']]]]),
			$this->msg('user', 'hi'),
		]);

		$m = $this->reader()->meta($path);

		$this->assertSame('019fa586-a9b7-7df0-a430-49907c5193f6', $m['session_id']);
		$this->assertIsString($m['source'], 'a non-scalar source must be flattened, not fatal');
	}

	/** Forks and resumes concatenate: one real rollout carries 55 session_meta lines. */
	public function testTheFirstSessionMetaWins(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'hi'),
			$this->meta(['session_id' => 'later-fork-9999', 'cwd' => '/somewhere/else']),
		]);

		$this->assertSame('019fa586-a9b7-7df0-a430-49907c5193f6', $this->reader()->meta($path)['session_id']);
	}

	// =====================================================================
	// selfMeta — WHICH thread the file itself is
	// =====================================================================

	/**
	 * meta() answers "what conversation does this archive contain" and so takes the FIRST
	 * session_meta. selfMeta() answers a different question — "which thread IS this file" —
	 * and must take the LAST, because a fork/resume/subagent rollout begins with a verbatim
	 * copy of its ancestor's records, that ancestor's session_meta included. Measured on
	 * codex 0.146: rollout-…-019fafa8-b788… opens with meta(id=019faf70) and only later
	 * carries meta(id=019fafa8-b788, session_id=019faf70).
	 *
	 * Note `session_id` is the ANCESTOR's id on such a record, not the file's own; `id` is
	 * the file's own. Anything proving identity (bury's GATE 2, the pid→session join) has to
	 * read `id`, or every resumed session looks like a stranger.
	 */
	public function testSelfMetaIsTheLastSessionMetaBecauseAForkPrependsItsAncestor(): void
	{
		$path = $this->rollout([
			$this->meta(['id' => 'root-0001', 'session_id' => 'root-0001']),
			$this->msg('user', 'in the ancestor'),
			$this->meta(['id' => 'fork-0002', 'session_id' => 'root-0001', 'thread_source' => 'user']),
			$this->msg('user', 'in the fork'),
		]);

		$self = $this->reader()->selfMeta($path);

		$this->assertSame('fork-0002', $self['id']);
		$this->assertSame('root-0001', $self['session_id'], 'session_id names the ancestor, not this thread');
		$this->assertFalse($self['is_subagent']);
	}

	/** A spawned subagent thread must be recognisable as one — it is not a session to bury. */
	public function testSelfMetaFlagsASubagentThread(): void
	{
		$path = $this->rollout([
			$this->meta(['id' => 'root-0001', 'session_id' => 'root-0001']),
			$this->meta([
				'id'               => 'sub-0002',
				'session_id'       => 'root-0001',
				'parent_thread_id' => 'root-0001',
				'thread_source'    => 'subagent',
				'source'           => ['subagent' => ['thread_spawn' => ['parent_thread_id' => 'root-0001', 'depth' => 1]]],
			]),
		]);

		$self = $this->reader()->selfMeta($path);

		$this->assertSame('sub-0002', $self['id']);
		$this->assertTrue($self['is_subagent']);
	}

	/** Older rollouts predate the `id` field; `session_id` is then the file's own thread. */
	public function testSelfMetaFallsBackToSessionIdOnPreRenameRollouts(): void
	{
		$path = $this->rollout([$this->meta(), $this->msg('user', 'hi')]);

		$self = $this->reader()->selfMeta($path);

		$this->assertSame('019fa586-a9b7-7df0-a430-49907c5193f6', $self['id']);
		$this->assertFalse($self['is_subagent']);
	}

	/** A source with no readable header answers "unknown", never a wrong id. */
	public function testSelfMetaOnAHeaderlessOrMissingFile(): void
	{
		$this->assertNull($this->reader()->selfMeta('/no/such/rollout.jsonl')['id']);
		$this->assertNull($this->reader()->selfMeta($this->rollout([$this->msg('user', 'no header')]))['id']);
	}

	/** 100 of 223 real rollouts carry no turn_context at all — absence is normal, not an error. */
	public function testWorksWithNoTurnContext(): void
	{
		$path = $this->rollout([$this->meta(), $this->msg('user', 'do the thing')]);

		$this->assertNull($this->reader()->meta($path)['model']);
		$this->assertCount(1, $this->reader()->genuineTurns($path));
	}

	public function testReadsModelFromTurnContextWhenPresent(): void
	{
		$path = $this->rollout([
			$this->meta(),
			['timestamp' => '2026-07-29T09:00:01.000Z', 'type' => 'turn_context', 'payload' => [
				'model' => 'gpt-5.6-terra', 'approval_policy' => 'never', 'sandbox_policy' => ['type' => 'danger-full-access'],
			]],
			$this->msg('user', 'hi'),
		]);

		$this->assertSame('gpt-5.6-terra', $this->reader()->meta($path)['model']);
	}

	// =====================================================================
	// Turn normalisation — the genuineTurns() contract
	// =====================================================================

	public function testNormalisesUserAndAssistantMessages(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('user', 'add a sync subcommand'),
			$this->msg('assistant', 'Done — added sync.'),
		]));

		$this->assertSame(
			[['role' => 'user', 'text' => 'add a sync subcommand'], ['role' => 'assistant', 'text' => 'Done — added sync.']],
			array_map(fn($t) => ['role' => $t['role'], 'text' => $t['text']], $turns)
		);
	}

	/**
	 * Assistant text is written TWICE — once as event_msg/agent_message and once as
	 * response_item/message — roughly 1:1. Rendering both doubles every assistant turn.
	 * response_item is the lane to keep: in codex's Legacy history mode event_msg is the
	 * lossy side (it never carries exec calls at all).
	 */
	public function testSuppressesTheEventMsgDuplicateOfAssistantText(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('user', 'ship it'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'event_msg', 'payload' => [
				'type' => 'agent_message', 'message' => 'Shipped.',
			]],
			$this->msg('assistant', 'Shipped.'),
		]));

		$texts = array_column($turns, 'text');
		$this->assertSame(['ship it', 'Shipped.'], $texts, 'the event_msg copy must not double the turn');
	}

	/** A rollout with ONLY the event_msg lane still has to render — nothing else carries the text. */
	public function testFallsBackToTheEventMsgLaneWhenThereAreNoResponseItems(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			['timestamp' => '2026-07-29T09:01:00.000Z', 'type' => 'event_msg', 'payload' => ['type' => 'user_message', 'message' => 'hello']],
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'event_msg', 'payload' => ['type' => 'agent_message', 'message' => 'hi back']],
		]));

		$this->assertSame([['role' => 'user', 'text' => 'hello'], ['role' => 'assistant', 'text' => 'hi back']],
			array_map(fn($t) => ['role' => $t['role'], 'text' => $t['text']], $turns));
	}

	/**
	 * Measured across the corpus: `developer` (436 records) carries harness-injected
	 * permissions/memory blocks, `<environment_context>` (181) is a whole-text cwd/shell
	 * dump, and `<turn_aborted>` (45) is a synthetic user turn left by an interrupt. Note
	 * there is no `<user_instructions>` tag anywhere — AGENTS.md arrives structurally, in
	 * turn_context.user_instructions and world_state, never as a turn.
	 */
	public function testDropsTheDeveloperRoleAndSyntheticWrappers(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('developer', "<permissions instructions>\n…\n</permissions instructions>\n## Memory"),
			$this->msg('user', '<environment_context><cwd>/x</cwd><shell>zsh</shell></environment_context>'),
			$this->msg('user', '<turn_aborted>'),
			$this->msg('user', '<local-command-stdout>on branch master</local-command-stdout>'),
			$this->msg('user', 'the real question'),
		]));

		$this->assertCount(1, $turns);
		$this->assertSame('the real question', $turns[0]['text']);
	}

	/**
	 * The corpus's oldest rollout predates the {type, payload} envelope entirely: its
	 * records are bare `{"type":"message",…}` at top level, and two carry no timestamp.
	 * Treating a payload-less record as its own payload costs one line and keeps the
	 * oldest session readable.
	 */
	public function testReadsTheLegacyPreEnvelopeShape(): void
	{
		$path = $this->rollout([
			['type' => 'session_meta', 'payload' => ['id' => 'oldest-0001', 'cwd' => '/x']],
			['record_type' => 'state'],
			['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'ancient prompt']]],
			['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'ancient reply']]],
		]);

		$this->assertSame(['ancient prompt', 'ancient reply'], array_column($this->reader()->genuineTurns($path), 'text'));
	}

	/** reasoning payloads are an opaque encrypted blob; only a non-empty summary is renderable. */
	public function testSkipsEncryptedReasoningButKeepsASummary(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'why'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'gAAAAAB...opaque...',
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => '**Checking the index**']],
				'encrypted_content' => 'gAAAAAB...opaque...',
			]],
		]);

		$out = $this->reader()->toMarkdownArchive($path);

		$this->assertStringNotContainsString('opaque', $out, 'the encrypted blob must never reach the archive');
		$this->assertStringContainsString('Checking the index', $out);
	}

	// =====================================================================
	// Tool calls — both dialects, and ten months of name drift
	// =====================================================================

	public function testAttachesFunctionCallAndItsOutputByCallId(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'list the files'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'c1', 'name' => 'exec_command',
				'arguments' => json_encode(['command' => 'ls -la', 'workdir' => '/Users/JT/.dotfiles']),
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'c1', 'output' => "total 48\ndrwxr-xr-x  bin",
			]],
		]);

		$tools = $this->reader()->genuineTurns($path)[1]['tools'];

		$this->assertSame('exec_command: ls -la', $tools[0]['label']);
		$this->assertStringContainsString('total 48', $tools[0]['output']);
	}

	/**
	 * custom_tool_call carries a RAW string in `input`, not JSON — apply_patch's input is a
	 * patch body. Decoding it as JSON yields null and loses the call entirely.
	 */
	public function testHandlesCustomToolCallWithARawStringInput(): void
	{
		$patch = "*** Begin Patch\n*** Update File: src/Graveyard.php\n@@\n-old\n+new\n*** End Patch";
		$path  = $this->rollout([
			$this->meta(),
			$this->msg('user', 'patch it'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'custom_tool_call', 'call_id' => 'c9', 'name' => 'apply_patch', 'input' => $patch,
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'custom_tool_call_output', 'call_id' => 'c9',
				'output' => [['type' => 'input_text', 'text' => 'Success. Updated src/Graveyard.php']],
			]],
		]);

		$tools = $this->reader()->genuineTurns($path)[1]['tools'];

		$this->assertStringStartsWith('apply_patch', $tools[0]['label']);
		$this->assertStringContainsString('src/Graveyard.php', $tools[0]['label']);
		$this->assertStringContainsString('Success.', $tools[0]['output']);
	}

	/**
	 * The shell tool has been renamed twice in ten months (shell_command → exec_command →
	 * exec) and moved record type on the last hop. Dispatch is on payload.name with a
	 * default branch, so a name nobody has seen yet still renders.
	 */
	public function testRendersEveryKnownShellToolNameAndAnUnknownOne(): void
	{
		$call = fn(string $type, string $name, $args, string $id) => [
			'timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item',
			'payload' => array_merge(['type' => $type, 'call_id' => $id, 'name' => $name],
				$type === 'function_call' ? ['arguments' => json_encode($args)] : ['input' => is_string($args) ? $args : json_encode($args)]),
		];

		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'go'),
			$call('function_call', 'shell_command', ['command' => 'git status'], 'a'),
			$call('function_call', 'exec_command', ['command' => 'ls'], 'b'),
			$call('custom_tool_call', 'exec', 'tools.exec_command({cmd: "pwd"})', 'c'),
			$call('function_call', 'some_future_tool', ['whatever' => 'x'], 'd'),
		]);

		$labels = array_column($this->reader()->genuineTurns($path)[1]['tools'], 'label');

		$this->assertSame('shell_command: git status', $labels[0]);
		$this->assertSame('exec_command: ls', $labels[1]);
		$this->assertStringStartsWith('exec', $labels[2]);
		$this->assertStringStartsWith('some_future_tool', $labels[3], 'an unrecognised tool still renders by name');
	}

	/**
	 * IMPORTED dialect: a Codex Desktop session's tool traffic is not records at all, it is
	 * [external_agent_tool_call: X] markers inside the message text. The one codex session
	 * already buried in the live store is exactly this shape.
	 */
	public function testLiftsExternalToolCallMarkersOutOfImportedMessageText(): void
	{
		$text = "Checking the tree.\n[external_agent_tool_call: Bash]\ngit status --short\n[/external_agent_tool_call]\nAll clean.";
		$path = $this->rollout([
			$this->meta(['originator' => 'Codex Desktop', 'source' => 'vscode']),
			$this->msg('user', 'status?'),
			$this->msg('assistant', $text),
		]);

		$turn = $this->reader()->genuineTurns($path)[1];

		$this->assertStringNotContainsString('external_agent_tool_call', $turn['text'], 'the marker is structure, not prose');
		$this->assertStringContainsString('Checking the tree.', $turn['text']);
		$this->assertNotEmpty($turn['tools']);
		$this->assertStringStartsWith('Bash', $turn['tools'][0]['label']);
	}

	/**
	 * The other half of the imported dialect, and the one that dominates the real archive:
	 * the tool RESULT comes back as its own assistant message wrapped in
	 * [external_agent_tool_result]. Rendered as prose it becomes a wall of command output
	 * attributed to Codex — in the live store's one codex session that is most of the file.
	 * It belongs to the call above it as output, and produces no turn of its own.
	 */
	public function testExternalToolResultsAttachToTheCallInsteadOfBecomingSpeech(): void
	{
		$call   = "Checking the tree.\n[external_agent_tool_call: Bash]\ndescription: Look at git\ncommand: git status --short\n[/external_agent_tool_call]";
		$result = "[external_agent_tool_result]\n M src/Graveyard.php\n[/external_agent_tool_result]";

		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(['originator' => 'Codex Desktop', 'source' => 'vscode']),
			$this->msg('user', 'status?'),
			$this->msg('assistant', $call),
			$this->msg('assistant', $result),
		]));

		$this->assertCount(2, $turns, 'the result message must not become a third turn');

		$tool = $turns[1]['tools'][0];
		// The label names the COMMAND, not the description line that happens to come first.
		$this->assertSame('Bash: git status --short', $tool['label']);
		$this->assertStringContainsString('M src/Graveyard.php', $tool['output']);
		$this->assertStringNotContainsString('external_agent_tool_result', $tool['output']);

		$md = $this->reader()->toMarkdownArchive($this->rollout([
			$this->meta(['originator' => 'Codex Desktop']),
			$this->msg('user', 'status?'),
			$this->msg('assistant', $call),
			$this->msg('assistant', $result),
		], 'md.jsonl'));

		$this->assertStringNotContainsString('external_agent_tool_result', $md);
		$this->assertMatchesRegularExpression('/^ {6}M src\/Graveyard\.php$/m', $md);
	}

	/**
	 * An imported call body is "key: value" lines, and the key is scaffolding. Measured on
	 * the real archive: a Read call labelled itself "Read: file: /path", stuttering the key
	 * back at you.
	 */
	public function testAnImportedCallLabelDropsItsKeyPrefix(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(['originator' => 'Codex Desktop']),
			$this->msg('user', 'read it'),
			$this->msg('assistant', "[external_agent_tool_call: Read]\nfile: /Users/JT/.dotfiles/AGENTS.md\n[/external_agent_tool_call]"),
		]));

		$this->assertSame('Read: /Users/JT/.dotfiles/AGENTS.md', $turns[1]['tools'][0]['label']);
	}

	/**
	 * A slash-command invocation IS the prompt, and the archive has to keep it: Claude's
	 * emitter deliberately renders those turns as "/name args" rather than dropping them.
	 * Measured on the real archive — the session's opening `/system-watchdog` vanished
	 * entirely, so the transcript began mid-conversation with a Codex turn.
	 */
	public function testASlashCommandInvocationIsKeptAsThePrompt(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', '<command-message>system-watchdog is running…</command-message><command-name>/system-watchdog</command-name>'),
			$this->msg('assistant', 'Checking the journal.'),
		]);

		$turns = $this->reader()->genuineTurns($path);

		$this->assertSame('user', $turns[0]['role'], 'the transcript must not begin mid-conversation');
		$this->assertSame('/system-watchdog', $turns[0]['text']);
		$this->assertStringContainsString('**You:** /system-watchdog', $this->reader()->toMarkdownArchive($path));
	}

	public function testASlashCommandKeepsItsArguments(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('user', '<command-name>/review</command-name><command-args>--full src/</command-args>'),
		]));

		$this->assertSame('/review --full src/', $turns[0]['text']);
	}

	/**
	 * exec_command wraps its result in an accounting preamble (chunk id, wall time, exit
	 * code, token count) before the actual output. Verbatim, every tool result in a native
	 * session opens with four lines of machinery nobody wants to read.
	 */
	public function testUnwrapsTheExecCommandOutputEnvelope(): void
	{
		$env = "Chunk ID: 92fa81\nWall time: 0.0525 seconds\nProcess exited with code 0\nOriginal token count: 962\nOutput:\ntotal 48\nsrc";
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('user', 'go'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'e1', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'ls']),
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'e1', 'output' => $env,
			]],
		]));

		$out = $turns[1]['tools'][0]['output'];
		$this->assertSame("total 48\nsrc", $out);
		$this->assertStringNotContainsString('Chunk ID', $out);
		$this->assertStringNotContainsString('Wall time', $out);
	}

	/** A non-zero exit is the one part of that preamble worth keeping. */
	public function testKeepsANonZeroExitCodeFromTheEnvelope(): void
	{
		$env = "Chunk ID: aa\nWall time: 0.1 seconds\nProcess exited with code 2\nOriginal token count: 5\nOutput:\nno such file";
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(),
			$this->msg('user', 'go'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'e2', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'cat nope']),
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'e2', 'output' => $env,
			]],
		]));

		$this->assertSame("⚠ exit 2\nno such file", $turns[1]['tools'][0]['output']);
	}

	/**
	 * The envelope's middle lines are not a fixed set. A still-running command reports
	 * "Process running with session ID" where a finished one reports "Process exited with
	 * code", and matching a hardcoded list left the machinery in place for 38 sessions.
	 * Matched by shape instead: Chunk ID, a run of "Key: value" lines, then a bare "Output:".
	 */
	public function testUnwrapsEnvelopeVariantsItHasNeverSeen(): void
	{
		$r = $this->reader();

		$running = "Chunk ID: fae624\nWall time: 10.0024 seconds\nProcess running with session ID 95238\nOriginal token count: 0\nOutput:\n";
		$this->assertSame('', $r->unwrapExecEnvelope($running));

		$future = "Chunk ID: abc123\nWall time: 1 second\nSome Future Field: whatever\nOutput:\nreal output\n";
		$this->assertSame('real output', $r->unwrapExecEnvelope($future));

		// The command is sometimes echoed ahead of its own envelope — that text is worth
		// keeping, so the strip is anchored to the block rather than the start of the output.
		$echoed = "rg --files\nChunk ID: d1\nWall time: 0 seconds\nOutput:\nsrc/x.php";
		$this->assertSame("rg --files\nsrc/x.php", $r->unwrapExecEnvelope($echoed));

		// A chunked read emits several envelopes back to back.
		$chunked = "Chunk ID: a\nOutput:\nfirst\nChunk ID: b\nOutput:\nsecond";
		$this->assertSame("first\nsecond", $r->unwrapExecEnvelope($chunked));
	}

	/** An empty envelope leaves an empty result, not four lines of machinery. */
	public function testAnEmptyExecEnvelopeYieldsNoOutputBlock(): void
	{
		$md = $this->reader()->toMarkdownArchive($this->rollout([
			$this->meta(),
			$this->msg('user', 'go'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'e3', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'true']),
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'e3',
				'output' => "Chunk ID: bb\nWall time: 0.01 seconds\nProcess exited with code 0\nOriginal token count: 0\nOutput:\n",
			]],
		]));

		$this->assertStringContainsString('↳ `exec_command: true`', $md);
		$this->assertStringNotContainsString('Chunk ID', $md);
	}

	/**
	 * Record types measured in the corpus that are NOT conversation and NOT drift. Counting
	 * them as unknown turns the drift signal into noise, which is how real drift gets
	 * missed. item_completed matters most: it is codex's paginated history mode, already
	 * appearing in the wild ahead of the migration.
	 */
	public function testKnownNonConversationRecordsAreNotCountedAsDrift(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'hi'),
			['type' => 'event_msg', 'payload' => ['type' => 'exec_command_end', 'exit_code' => 0]],
			['type' => 'event_msg', 'payload' => ['type' => 'item_completed', 'item' => []]],
			['type' => 'event_msg', 'payload' => ['type' => 'thread_name_updated', 'name' => 'x']],
			['type' => 'response_item', 'payload' => ['type' => 'ghost_snapshot', 'x' => 1]],
			['type' => 'response_item', 'payload' => ['type' => 'agent_message', 'author' => 'sub', 'content' => []]],
		]);

		$this->assertSame([], $this->reader()->unknownCounts($path));
		$this->assertSame(['hi'], array_column($this->reader()->genuineTurns($path), 'text'));
	}

	/** A result with no call above it is still output, not speech — it must not be dropped silently. */
	public function testAnOrphanedExternalToolResultDoesNotBecomeATurn(): void
	{
		$turns = $this->reader()->genuineTurns($this->rollout([
			$this->meta(['originator' => 'Codex Desktop']),
			$this->msg('user', 'go'),
			$this->msg('assistant', "[external_agent_tool_result]\nstray output\n[/external_agent_tool_result]"),
		]));

		$this->assertSame(['go'], array_column($turns, 'text'));
	}

	// =====================================================================
	// Robustness — a reader over an undocumented, drifting format
	// =====================================================================

	public function testUnknownRecordTypesAreCountedNotFatal(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'hi'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'brand_new_record', 'payload' => ['x' => 1]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => ['type' => 'brand_new_item', 'x' => 1]],
			$this->msg('assistant', 'still here'),
		]);

		$r = $this->reader();
		$turns = $r->genuineTurns($path);

		$this->assertSame(['hi', 'still here'], array_column($turns, 'text'));
		$this->assertSame(1, $r->unknownCounts($path)['brand_new_record'] ?? 0);
		$this->assertSame(1, $r->unknownCounts($path)['response_item/brand_new_item'] ?? 0);
	}

	public function testSurvivesMalformedAndTruncatedLines(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'hi'),
			'',
			'{ this is not json',
			'{"timestamp":"2026-07-29T09:02:00.000Z","type":"response_item","payload":{"type":"mess',
			$this->msg('assistant', 'survived'),
		]);

		$this->assertSame(['hi', 'survived'], array_column($this->reader()->genuineTurns($path), 'text'));
	}

	public function testAMissingFileYieldsNothingRatherThanThrowing(): void
	{
		$this->assertSame([], $this->reader()->genuineTurns($this->dir . '/nope.jsonl'));
		$this->assertSame('', $this->reader()->toMarkdownArchive($this->dir . '/nope.jsonl'));
	}

	/** deriveSummary needs the first real prompt and must not read a 60 MB file to get it. */
	public function testFirstUserTextSkipsSyntheticPreamble(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('developer', 'You are Codex.'),
			$this->msg('user', '<environment_context><cwd>/x</cwd></environment_context>'),
			$this->msg('user', 'fix the completion loader'),
			$this->msg('assistant', 'on it'),
		]);

		$this->assertSame('fix the completion loader', $this->reader()->firstUserText($path));
	}

	/**
	 * Codex delivers project instructions as a USER message opening
	 * "# AGENTS.md instructions" + an <INSTRUCTIONS> block. Measured in production twice:
	 * both a real bury and an e2e bury titled their headstone
	 * "# AGENTS.md instructions ## Compound Codex Tool Mapping…" instead of the prompt.
	 * It is injected context, not something anyone said.
	 */
	public function testSkipsTheInjectedAgentsMdInstructionsBlock(): void
	{
		$injected = "# AGENTS.md instructions\n\n<INSTRUCTIONS>\n<!-- BEGIN COMPOUND CODEX TOOL MAP -->\n## Compound Codex Tool Mapping\n" . str_repeat('x', 400) . "\n</INSTRUCTIONS>";
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', $injected),
			$this->msg('user', 'reply with exactly: e2e marker'),
			$this->msg('assistant', 'e2e marker'),
		]);

		$this->assertSame('reply with exactly: e2e marker', $this->reader()->firstUserText($path));

		$texts = array_column($this->reader()->genuineTurns($path), 'text');
		$this->assertNotContains($injected, $texts, 'an injected instructions block is not a turn');
		$this->assertSame(['reply with exactly: e2e marker', 'e2e marker'], $texts);
	}

	/** The bare <INSTRUCTIONS> wrapper, without the AGENTS.md heading, is the same thing. */
	public function testSkipsABareInstructionsWrapper(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', "<INSTRUCTIONS>\nproject rules\n</INSTRUCTIONS>"),
			$this->msg('user', 'the real ask'),
		]);

		$this->assertSame('the real ask', $this->reader()->firstUserText($path));
	}

	/**
	 * firstUserText returns the prompt RAW, wrappers intact, because Graveyard's existing
	 * summarizeUserText() is what turns a slash-command invocation into "/name args" — and
	 * that is exactly the title the one already-buried codex session should have had
	 * instead of its directory name. Stripping the tags here would defeat it.
	 */
	public function testFirstUserTextKeepsSlashCommandWrappersForTheSummarizer(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', '<command-message>system-watchdog is running…</command-message><command-name>/system-watchdog</command-name>'),
		]);

		$raw = $this->reader()->firstUserText($path);

		$this->assertStringContainsString('<command-name>/system-watchdog</command-name>', $raw);
		$this->assertSame('/system-watchdog', $this->gy->summarizeUserText($raw));
	}

	// =====================================================================
	// The markdown archive — the format every existing reader consumes
	// =====================================================================

	public function testEmitsTheMarkdownArchiveGrammar(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'add a sync subcommand', '2026-07-29T09:01:00.000Z'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'c1', 'name' => 'exec_command',
				'arguments' => json_encode(['command' => 'ls -la']),
			]],
			['timestamp' => '2026-07-29T09:02:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'c1', 'output' => 'total 48',
			]],
			$this->msg('assistant', 'Done — added sync.', '2026-07-29T09:03:00.000Z'),
		]);

		$md = $this->reader()->toMarkdownArchive($path);

		$this->assertStringContainsString('- session `019fa586-a9b7-7df0-a430-49907c5193f6`', $md);
		$this->assertStringContainsString('- cwd `/Users/JT/.dotfiles`', $md);
		$this->assertMatchesRegularExpression('/^- 2026-07-29T09:00:00\.000Z → 2026-07-29T09:03:00\.000Z$/m', $md);
		$this->assertStringContainsString('**You:** add a sync subcommand', $md);
		$this->assertStringContainsString('**Codex:** ', $md);
		$this->assertMatchesRegularExpression('/^ {2}↳ `exec_command: ls -la`$/m', $md);
		$this->assertMatchesRegularExpression('/^ {6}total 48$/m', $md);
	}

	/**
	 * Two records mark a compaction and they carry different amounts of information:
	 * `compacted` has a message (and a replacement_history), while the corpus's
	 * `event_msg/context_compacted` records are bare — `{"type":"context_compacted"}` and
	 * nothing else. The boundary has to render either way; only the summary is optional.
	 */
	public function testEmitsACompactionBoundaryWithOrWithoutASummary(): void
	{
		$withSummary = $this->reader()->toMarkdownArchive($this->rollout([
			$this->meta(),
			$this->msg('user', 'first'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'compacted', 'payload' => [
				'message' => 'summary of the earlier work', 'replacement_history' => [],
			]],
			$this->msg('user', 'second'),
		], 'a.jsonl'));

		$this->assertStringContainsString("---\n\n### ⟲ Context compacted\n", $withSummary);
		$this->assertStringContainsString('summary of the earlier work', $withSummary);

		$bare = $this->reader()->toMarkdownArchive($this->rollout([
			$this->meta(),
			$this->msg('user', 'first'),
			['timestamp' => '2026-07-29T09:02:00.000Z', 'type' => 'event_msg', 'payload' => ['type' => 'context_compacted']],
			$this->msg('user', 'second'),
		], 'b.jsonl'));

		$this->assertStringContainsString('⟲ Context compacted', $bare);
		$this->assertStringContainsString('**You:** second', $bare);
	}

	/**
	 * The trap that eats a turn. Structural markers count at COLUMN 0 only, so a codex
	 * message — which is markdown-heavy and full of `##` headings and `---` rules — cuts
	 * its own turn in half and leaves the following tool calls leaking as literal text.
	 * Turn bodies are written indented so nothing inside a turn can be read as structure.
	 */
	public function testColumnZeroMarkdownInsideATurnCannotEndIt(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'explain'),
			$this->msg('assistant', "Intro line.\n\n## A Heading\n\n---\n\nMore prose."),
			['timestamp' => '2026-07-29T09:03:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'z', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'ls']),
			]],
		]);

		$md = $this->reader()->toMarkdownArchive($path);

		$this->assertDoesNotMatchRegularExpression('/^## /m', $md, 'a column-0 heading would cut the turn');
		$this->assertDoesNotMatchRegularExpression('/^---$/m', $md, 'a column-0 rule would cut the turn');

		// And the end-to-end proof: the tool call still renders as a call, not as prose.
		$tui = (new \JT\Helpers\TuiTranscript())->fromMarkdown($md);
		$this->assertStringContainsString('⏺ exec_command(ls)', $tui);
		$this->assertStringNotContainsString('↳', $tui, 'a leaked label means the turn was cut');
	}

	/**
	 * The real corpus failure, and the subtler half of the column-0 problem: a `---` or
	 * `## heading` inside a FENCED block still cuts the turn, because the loop that finds a
	 * turn's end tests each line against startsTurn() and knows nothing about fences. The
	 * labels after the cut were then re-flowed as top-level prose — which is how they were
	 * identified, since prose gets its backticks stripped and verbatim output does not.
	 * Measured: 76 leaked labels across 230 sessions, one line of indent away from zero.
	 */
	public function testColumnZeroMarkdownInsideAFencedBlockCannotEndTheTurn(): void
	{
		$body = "Run this:\n\n```sh\n# a comment heading\n---\nsudo kill 1\n```\n\nThen keep going.";
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'show me'),
			$this->msg('assistant', $body),
			['timestamp' => '2026-07-29T09:03:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'g1', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'cmux tab list']),
			]],
		]);

		$md       = $this->reader()->toMarkdownArchive($path);
		$rendered = (new \JT\Helpers\TuiTranscript())->fromMarkdown($md);

		// Nothing inside a turn body may sit at column 0 — fenced content included. The
		// header's own `# title` is exempt: that IS structure, and the renderer consumes it
		// as the banner.
		$inTurns = false;
		foreach (explode("\n", $md) as $ln) {
			if (preg_match('/^\*\*(You|Codex):\*\*/', $ln)) { $inTurns = true; continue; }
			if ($inTurns && preg_match('/^(---$|#{1,6}\s)/', $ln)) {
				$this->fail("a column-0 structural marker survived into a turn body: {$ln}");
			}
		}
		$this->assertStringContainsString('⏺ exec_command(cmux tab list)', $rendered);
		$this->assertDoesNotMatchRegularExpression('/^[ \t]*↳/m', $rendered);
		// The fenced block still reads as a block, with its relative alignment intact.
		$this->assertStringContainsString('sudo kill 1', $rendered);
	}

	/**
	 * An unclosed fence in a turn body keeps the renderer in verbatim mode, so the turn's
	 * own tool labels come out as literal "↳ `…`" text. Measured on the corpus before the
	 * fix: 76 leaked labels across 230 sessions. Codex opens fenced blocks constantly and a
	 * truncated live message never closes its last one.
	 */
	public function testAnUnclosedFenceInATurnCannotSwallowItsToolLabels(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'show me'),
			$this->msg('assistant', "Run this:\n\n```bash\nsudo kill 21050\n\nand then keep going"),
			['timestamp' => '2026-07-29T09:03:00.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'call_id' => 'f1', 'name' => 'exec_command', 'arguments' => json_encode(['command' => 'cmux tab list']),
			]],
			['timestamp' => '2026-07-29T09:03:01.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'f1', 'output' => 'Error: Unknown command: tab',
			]],
		]);

		$rendered = (new \JT\Helpers\TuiTranscript())->fromMarkdown($this->reader()->toMarkdownArchive($path));

		$this->assertStringContainsString('⏺ exec_command(cmux tab list)', $rendered);
		$this->assertDoesNotMatchRegularExpression('/^[ \t]*↳/m', $rendered, 'the unclosed fence swallowed the label');
	}

	/** The whole point: the emitted archive renders through the existing page-modal path. */
	public function testTheArchiveRendersThroughTuiTranscript(): void
	{
		$path = $this->rollout([
			$this->meta(),
			$this->msg('user', 'do the thing'),
			$this->msg('assistant', 'Done.'),
		]);

		$tui = (new \JT\Helpers\TuiTranscript())->fromMarkdown($this->reader()->toMarkdownArchive($path));

		$this->assertStringContainsString('❯ do the thing', $tui);
		$this->assertStringContainsString('⏺ Done.', $tui);
		$this->assertStringNotContainsString('**', $tui);
	}

	/** Rollouts reach tens of MB; nothing may slurp one into memory. */
	public function testReadsAHugeRolloutWithoutHoldingItInMemory(): void
	{
		$records = [$this->meta()];
		for ($i = 0; $i < 8000; $i++) {
			$records[] = $this->msg('user', "prompt {$i} " . str_repeat('x', 500));
			$records[] = $this->msg('assistant', "reply {$i} " . str_repeat('y', 500));
		}
		$path = $this->rollout($records, 'huge.jsonl');
		$this->assertGreaterThan(8 * 1024 * 1024, filesize($path));

		$before = memory_get_usage(true);
		$first  = $this->reader()->firstUserText($path);
		$used   = memory_get_usage(true) - $before;

		$this->assertStringStartsWith('prompt 0', $first);
		$this->assertLessThan(2 * 1024 * 1024, $used, 'firstUserText must stream and stop, not slurp');
	}
}
