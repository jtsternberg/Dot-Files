<?php
namespace JT;

trait RepoConfigTrait {
	protected $repos = [];

	protected function getConfigFilename() {
		return '.ghrepos.jsonc';
	}

	protected function loadRepos() {
		$configPath = $this->findConfigFile($this->cli->wd ?? getcwd());
		if (empty($configPath)) {
			$this->cli->err('Error: No .ghrepos configuration found.');
			$this->cli->msg("\nRun '" . ($this->cli->scriptName ?? 'script') . " config' to create and edit configuration.\n", 'yellow');
			exit(1);
		}

		$json = file_get_contents($configPath);
		$config = (new \Ahc\Json\Comment)->decode($json, true);

		if (!$config || !is_array($config)) {
			$this->cli->err('Error: Invalid .ghrepos configuration file');
			exit(1);
		}

		$this->repos = $config;
	}

	public function editConfig() {
		$configPath = $this->findConfigFile(getcwd());

		if (empty($configPath)) {
			$template = <<<'JSON'
[
	// Add your repository URLs below
	"git@github.com:org/repo.git",

	/* You can use multi-line
		comments as well */
	"git@github.com:org/another-repo.git",

	// Wiki repositories
	// "git@github.com:org/repo.wiki.git"
]
JSON;
			$configPath = $this->getConfigFilename();

			$this->cli->writeToFile($configPath, $template, [
				'failExit' => true,
				'silent' => true,
			]);

			$this->cli->msg("Created new config file: $configPath\n", 'green');
		}

		passthru(getenv('EDITOR') . ' ' . escapeshellarg($configPath));
	}

	protected function findConfigFile($startDir, $maxLevels = 5) {
		$currentDir = rtrim($startDir, '/');
		$configPath = '';
		$levelsUp = 0;

		while ($levelsUp < $maxLevels) {
			$testPath = $currentDir . '/' . $this->getConfigFilename();
			if (file_exists($testPath)) {
				$configPath = $testPath;
				break;
			}

			$parentDir = dirname($currentDir);
			if ($parentDir === $currentDir) {
				break;
			}

			$currentDir = $parentDir;
			$levelsUp++;
		}

		return $configPath;
	}
}


