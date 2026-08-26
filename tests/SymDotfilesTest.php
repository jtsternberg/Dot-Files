<?php
namespace JT\Tests;

use JT\Paths;
use Symfony\Component\Yaml\Yaml;

class SymDotfilesTest extends TestCase
{
	public function testEveryRepoSkillHasPortableFrontmatter(): void
	{
		$skillFiles = glob(Paths::path('agent-skills/*/SKILL.md')) ?: [];

		$this->assertNotEmpty($skillFiles);

		foreach ($skillFiles as $skillFile) {
			$contents = (string) file_get_contents($skillFile);
			$this->assertSame(1, preg_match('/\A---\R(.*?)\R---\R/s', $contents, $matches), $skillFile);

			$frontmatter = Yaml::parse($matches[1]);
			$this->assertIsArray($frontmatter, $skillFile);
			$this->assertSame(basename(dirname($skillFile)), $frontmatter['name'] ?? null, $skillFile);
			$this->assertNotSame('', trim((string) ($frontmatter['description'] ?? '')), $skillFile);

			$markdownFiles = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(dirname($skillFile), \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($markdownFiles as $markdownFile) {
				if ($markdownFile->getExtension() !== 'md') {
					continue;
				}
				$markdown = (string) file_get_contents($markdownFile->getPathname());
				$message = $markdownFile->getPathname();
				$this->assertStringNotContainsString('${CLAUDE_PLUGIN_ROOT}', $markdown, $message);
				$this->assertStringNotContainsString('${CLAUDE_SKILL_DIR:-', $markdown, $message);
				$this->assertStringNotContainsString('${CLAUDE_SKILL_DIR}/../', $markdown, $message);
			}
		}
	}

	public function testRepoSkillsAreLinkedForClaudeAndCodex(): void
	{
		$fixture = $this->graveyardRoot . '/symdotfiles-fixture';
		$destination = $this->graveyardRoot . '/symdotfiles-home';
		$skill = $fixture . '/agent-skills/example';
		mkdir($skill, 0777, true);
		mkdir($destination, 0777, true);
		file_put_contents($skill . '/SKILL.md', "---\nname: example\ndescription: Example skill.\n---\n");

		$command = implode(' ', [
			escapeshellarg(PHP_BINARY),
			escapeshellarg(Paths::path('symdotfiles')),
			escapeshellarg('--dir=' . $fixture),
			escapeshellarg('--destination=' . $destination),
		]);
		exec($command . ' 2>&1', $output, $exitCode);

		$this->assertSame(0, $exitCode, implode("\n", $output));
		$this->assertSame($skill, readlink($destination . '/.claude/skills/example'));
		$this->assertSame($skill, readlink($destination . '/.agents/skills/example'));
	}

	public function testNonPortableRepoSkillIsNotLinkedIntoEitherHarness(): void
	{
		$fixture = $this->graveyardRoot . '/symdotfiles-incompatible-fixture';
		$destination = $this->graveyardRoot . '/symdotfiles-incompatible-home';
		$skill = $fixture . '/agent-skills/claude-only';
		mkdir($skill, 0777, true);
		mkdir($destination, 0777, true);
		file_put_contents(
			$skill . '/SKILL.md',
			"---\nname: claude-only\ndescription: Claude-only fixture.\n---\n\nRun \${CLAUDE_PLUGIN_ROOT}/scripts/tool.\n"
		);

		$command = implode(' ', [
			escapeshellarg(PHP_BINARY),
			escapeshellarg(Paths::path('symdotfiles')),
			escapeshellarg('--dir=' . $fixture),
			escapeshellarg('--destination=' . $destination),
		]);
		exec($command . ' 2>&1', $output, $exitCode);

		$this->assertSame(0, $exitCode, implode("\n", $output));
		$this->assertFileDoesNotExist($destination . '/.claude/skills/claude-only');
		$this->assertFileDoesNotExist($destination . '/.agents/skills/claude-only');
		$this->assertStringContainsString('is not dual-harness portable', implode("\n", $output));
	}
}
