<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;
use JT\Transport\TransportRegistry;

final class GraveyardCompletionTest extends TestCase
{
	public function test_cmux_completion_reads_only_the_tree(): void
	{
		$cmux = new class($this->cli) extends \JT\Helpers\Cmux {
			public function tree(): array {
				return ['windows' => [['workspaces' => [
					['title' => 'Alpha', 'panes' => [['surfaces' => [['title' => 'Review code'], ['title' => 'Shell']]]]],
					['title' => 'Empty workspace', 'panes' => []],
				]]]];
			}
			public function debugTerminals(): string { throw new \LogicException('No process discovery during completion'); }
		};
		$t = new \JT\Transport\CmuxTransport($this->cli, $cmux);
		$this->assertSame(['Alpha', 'Empty workspace'], $t->completionNames(true));
		$this->assertSame(['Review code', 'Shell'], $t->completionNames(false));
	}

	public function test_herdr_completion_reads_only_the_snapshot(): void
	{
		$herdr = new class($this->cli) extends \JT\Helpers\Herdr {
			public function snapshot(): array {
				return ['workspaces' => [['workspace_id' => 'wA', 'label' => 'Alpha']],
					'panes' => [['pane_id' => 'wA:p1', 'workspace_id' => 'wA', 'terminal_title_stripped' => 'Shell']]];
			}
		};
		$t = new \JT\Transport\HerdrTransport($this->cli, $herdr);
		$this->assertSame(['Alpha'], $t->completionNames(true));
		$this->assertSame(['Shell'], $t->completionNames(false));
	}
	public function test_targets_follow_the_transport_union_and_preserve_names(): void
	{
		$a = new FakeTransport('cmux', [
			FakeTransport::row('cmux', 'a', ['tab_title' => 'Review: CLI fixes']),
			FakeTransport::row('cmux', 'b', ['tab_title' => 'Review: CLI fixes']),
			FakeTransport::row('cmux', 'c', ['tab_title' => '']),
		], surfaces: [
			['workspace_title' => 'Project Alpha'],
			['workspace_title' => 'Project Alpha'],
			['workspace_title' => 'Shells only'],
		]);
		$b = new FakeTransport('herdr', [FakeTransport::row('herdr', 'd', ['tab_title' => 'Ship $release'])],
			surfaces: [['workspace_title' => 'Remote work']]);
		$registry = new TransportRegistry($this->cli, [$a, $b]);
		$gy = new Graveyard($this->cli, $a, null, $registry);
		$this->assertSame(['Review: CLI fixes', 'Ship $release'], $gy->completionTargets());
		$this->assertSame(['Project Alpha', 'Shells only', 'Remote work'], $gy->completionTargets(true));
		$this->assertSame([], $a->calls, 'Completion must never drive sessions.');
		$this->assertSame([], $b->calls);
	}

	public function test_line_protocol_omits_multiline_names(): void
	{
		$t = new FakeTransport('cmux', [
			FakeTransport::row('cmux', 'a', ['tab_title' => "two\nlines"]),
			FakeTransport::row('cmux', 'b', ['tab_title' => "carriage\rreturn"]),
			FakeTransport::row('cmux', 'c', ['tab_title' => 'safe name']),
		]);
		$gy = new Graveyard($this->cli, $t);
		$this->assertSame(['safe name'], $gy->completionTargets());
	}

	public function test_zsh_routes_targets_and_keeps_spaces_and_metacharacters(): void
	{
		$plugin = dirname(__DIR__, 2) . '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$dir = $this->graveyardRoot . '/bin';
		mkdir($dir);
		file_put_contents($dir . '/graveyard', "#!/bin/sh\nif [ \"\$*\" = 'completion-targets --workspace' ]; then\n printf '%s\\n' 'Project Alpha' 'Remote: work'\nelse\n printf '%s\\n' 'Review: CLI fixes' 'Ship \$release'\nfi\n");
		chmod($dir . '/graveyard', 0755);
		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[graveyard]} == _graveyard ]] || exit 1
_describe() { print -r -- "${(j:|:)targets}"; }
_graveyard_bury_targets tabs
_graveyard_bury_targets workspaces
# Drive the command branch with _arguments stubbed; verify actual action wiring.
_arguments() {
 if [[ $1 == -C ]]; then
  # *:: narrows words/CURRENT before handing control to the state branch.
  words=("${words[@]:1}")
  (( CURRENT-- ))
  state=arguments
  return
 fi
 print -rl -- "$@"
}
words=(graveyard bury '')
CURRENT=3
_graveyard
ZSH;
		$path = $this->graveyardRoot . '/completion.zsh';
		file_put_contents($path, $script);
		$cmd = 'PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' zsh -f ' . escapeshellarg($path) . ' ' . escapeshellarg($plugin);
		exec($cmd . ' 2>&1', $output, $code);
		$this->assertSame(0, $code, implode("\n", $output));
		$this->assertSame('Review\\: CLI fixes|Ship $release', $output[0]);
		$this->assertSame('Project Alpha|Remote\\: work', $output[1]);
		$this->assertStringContainsString(':{_graveyard_bury_targets workspaces}', implode("\n", $output));
		$this->assertStringContainsString(':{_graveyard_bury_targets tabs}', implode("\n", $output));
	}
}
