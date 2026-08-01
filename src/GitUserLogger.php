<?php
namespace JT;

/**
 * GitUserLogger — the domain logic behind `bin/gituserlog`.
 *
 * Clones/updates every repo listed in a `.ghrepos` config (via RepoConfigTrait)
 * and prints a git log filtered to one author over a window of days, or lists
 * every author seen across all repos when no author is given.
 *
 * The git-shelling methods (updateRepo clone/fetch, showUserLog, getRepoAuthors)
 * are the untestable I/O seam. The pure helpers they lean on — repoNameFromUrl,
 * parseAuthors, dedupeAuthors, isValidDays — are unit-tested in
 * tests/Git/GitUserLoggerTest.php.
 */
class GitUserLogger {
	use RepoConfigTrait;
	private $author;
	private $days = 7;
	private $logsdir;

	public function __construct(
		private $cli
	) {
	}

	public function init($author, $days = 7) {
		$this->author = $author;
		$this->days = $days;

		// Create a unique temp directory for this script
		$this->logsdir = sys_get_temp_dir() . '/gituserlog';

		// Ensure temp directory exists
		if (!is_dir($this->logsdir)) {
			mkdir($this->logsdir, 0777, true);
		}

		$this->loadRepos();

		return $this;
	}

    // loadRepos provided by RepoConfigTrait

	public function generate() {
		chdir($this->logsdir);

		if (empty($this->author)) {
			$this->cli->msg("\nFetching all authors...", 'yellow');
			$allAuthors = $this->getAllAuthors();
			$this->cli->msg("\n" . count($allAuthors) . " authors found", 'green');
			exit(implode("\n", $allAuthors) . "\n\n");
		}

		$porcelain = $this->cli->hasFlags('porcelain') ? ' --no-color' : '';

		$total = count($this->repos);
		$this->cli->msg("\nFetching commits for {$this->author} in last {$this->days} days...\n", 'yellow');

		foreach ($this->repos as $i => $repo) {
			$current = $i + 1;
			$this->cli->msg("[$current/$total] Processing ", 'yellow', false);
			if ($porcelain) {
				echo "[$current/$total] $repo\n";
			}
			try {
				$contents = $this->showUserLog($repo);
				if (empty($contents)) {
					$this->cli->msg(" done", 'green', false);
					$this->cli->msg(" (no commits found)");
					if ($porcelain) {
						echo "(no commits found for this period)\n\n";
					}
				} else {
					$this->cli->msg(" done\n", 'green');
					echo $contents;
				}
			} catch (EmptyRepositoryException $e) {
				$this->cli->msg(" done", 'green', false);
				$this->cli->msg(" ({$e->getMessage()})");
				if ($porcelain) {
					echo "({$e->getMessage()})\n\n";
				}
			}

		}
		echo "\n";
	}

	/**
	 * Extract the repository name from a git clone URL.
	 *
	 * Pure. Preserves the original greedy `~\/(.+)\.git~` match: for an SSH URL
	 * (git@host:org/repo.git) this yields `repo`; for an https URL with multiple
	 * slashes the greedy `.+` captures the whole path (a known quirk, kept for
	 * behavior parity). Returns '' when nothing matches.
	 */
	public function repoNameFromUrl(string $repo): string {
		preg_match_all('~\/(.+)\.git~', $repo, $matches);

		return $matches[1][0] ?? '';
	}

	/**
	 * Parse `git log --format=%aN | sort -u` output into a clean author list.
	 * Pure: split on newlines, trim, drop empty lines (e.g. the trailing newline).
	 */
	public function parseAuthors(string $gitOutput): array {
		if ('' === $gitOutput) {
			return [];
		}

		$lines = array_map('trim', explode("\n", $gitOutput));

		return array_values(array_filter($lines, static function ($line) {
			return '' !== $line;
		}));
	}

	/**
	 * Merge many per-repo author lists into one unique, non-empty, re-indexed list.
	 * Pure: mirrors the original array_unique(array_filter(array_merge(...))).
	 */
	public function dedupeAuthors(array $lists): array {
		$merged = $lists ? array_merge([], ...$lists) : [];

		return array_values(array_unique(array_filter($merged)));
	}

	/**
	 * Validate the requested days window. Pure predicate mirroring the original
	 * bin guard `$days <= 0 || !is_numeric($days)`.
	 */
	public static function isValidDays($days): bool {
		return !($days <= 0 || !is_numeric($days));
	}

	private function getAllAuthors() {
		$lists = [];
		$total = count($this->repos);
		foreach ($this->repos as $i => $repo) {
			$current = $i + 1;
			$this->cli->msg("\n[$current/$total] Checking {$repo}...", 'yellow', false);
			$authors = $this->getRepoAuthors($repo);
			$this->cli->msg(" found " . count($authors) . " authors", 'green');
			$lists[] = $authors;
			chdir($this->logsdir);
		}

		return $this->dedupeAuthors($lists);
	}

	private function getRepoAuthors($repo) {
		$prev = !empty($this->cli->flags['silent']) ? $this->cli->flags['silent'] : false;
		$this->cli->flags['silent'] = '--silent';
		$repodir = $this->updateRepo($repo);

		if (!is_dir($repodir)) {
			return [];
		}

		chdir($repodir);
		$output = `git log --format='%aN' | sort -u 2>/dev/null`;

		if ($prev) {
			$this->cli->flags['silent'] = $prev;
		} else {
			unset($this->cli->flags['silent']);
		}

		return $this->parseAuthors($output ?? '');
	}

	private function showUserLog($repo) {
		$reponame = $this->updateRepo($repo);
		$porcelain = $this->cli->hasFlags('porcelain') ? ' --no-color' : '';
		$output = `git log --pretty=format:"%C(yellow)%h [%ai]%Cred%d %Creset%s%Cblue [%cn]%Creset" --decorate --date=short --reverse --all --since="{$this->days} days ago" --author="{$this->author}"$porcelain`;

		if (!empty($output)) {
			return $output . "\n\n";
		}
		chdir($this->logsdir);

		return '';
	}

	private function updateRepo($repo, $branch = 'master') {
		$reponame = $this->repoNameFromUrl($repo);

		if (empty($reponame)) {
			die(print_r(compact('repo'), true));
		}

		$repodir = $this->logsdir . '/' . $reponame;
		[$path, $reponame] = explode($this->logsdir . '/', $repodir);

		// macOS's temp-file reaper deletes unaccessed files (HEAD, config) but
		// leaves directories, so a cached clone can pass is_dir() yet be broken.
		if (is_dir($repodir)) {
			$gitDirCheck = trim(`git -C "$repodir" rev-parse --git-dir 2>/dev/null` ?? '');
			if ('' === $gitDirCheck) {
				$this->cli->msg("{$reponame} cache broken, re-cloning...", 'yellow', false);
				`rm -rf "$repodir"`;
			}
		}

		if (is_dir($repodir)) {
			chdir($repodir);
			$this->cli->msg("{$reponame} updating...", 'cyan', false);
			`git fetch --all --quiet 2>/dev/null`;
		} else {
			$this->cli->msg("{$reponame} cloning...", 'cyan', false);
			$output = `git clone $repo $repodir --quiet 2>&1`;
			if (!empty($output)) {
				if ( false !== strpos( $output, 'You appear to have cloned an empty repository' ) ) {
					throw new EmptyRepositoryException( trim( $output ), __LINE__ );
				}
				throw new \Exception(
					sprintf(
						"Error: Failed to clone %s into %s.\nClone output: %s",
						$repo,
						$repodir,
						$output
					),
					__LINE__
				);
			}

			chdir($repodir);
		}

		`git checkout $branch --quiet 2>/dev/null`;

		return $repodir;
	}


    // editConfig provided by RepoConfigTrait

    // findConfigFile provided by RepoConfigTrait
}
