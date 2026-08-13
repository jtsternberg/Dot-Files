<?php
namespace JT;

/**
 * GitHub User Activity Logger.
 *
 * Extracted out of bin/githubuserlog so its pure logic (repo-filter pattern
 * building, repo filtering, `gh` output parsing, the `gh` command-argument
 * builders, and the numeric-arg guard) can be unit-tested. The gh-shelling
 * methods (execGitHub) plus mkdir/writeToFile remain the untested I/O seam,
 * the same split used by GitUserLogger's git backticks and Godo's dirmap
 * shell-out.
 *
 * @since 1.0.0
 */
class GitHubActivityLogger {
	private $cli;
	private $username;
	private $org;
	private $days = 7;
	private $since;
	private $activity = [];
	private $baseDir = '';
	private $fileName = '';
	private $activityDir = '';
	private $repoFilter = '';
	private $repoSearch = '';
	private $repoLimit = 501;

	public function __construct(
		$cli,
		$username,
		$org,
		$days = 7,
		$wikiPath = '',
		$repoFilter = '',
		$repoSearch = '',
		$repoLimit = 501
	) {
		// Check gh CLI is installed
		if (shell_exec('which gh') === null) {
			$cli->err("GitHub CLI (gh) is not installed. Please install it first: https://cli.github.com/\n");
			exit(1);
		}
		$this->cli = $cli;
		$this->username = $username;
		$this->org = $org;
		$this->days = $days;
		$this->repoFilter = $repoFilter;
		$this->repoSearch = $repoSearch;
		$this->repoLimit = $repoLimit;
		$this->since = date('Y-m-d', strtotime("-$days days"));

		$this->baseDir = $wikiPath
			? $cli->convertPathToAbsolute($wikiPath)
			: $cli->convertPathToAbsolute("~/Downloads/github-activity-$username");

		$this->fileName = "activity-last-{$this->days}-days";
		$this->activityDir = "{$this->baseDir}/{$this->fileName}";

		$this->createDirectories();
	}

	/**
	 * Whether a value is a positive number (the guard bin/githubuserlog applies
	 * to --days and --repoLimit).
	 *
	 * @param  mixed $value
	 * @return bool
	 */
	public static function isPositiveNumber($value) {
		return is_numeric($value) && $value > 0;
	}

	/**
	 * Turn a user-supplied repo filter into a regex body. A filter that already
	 * contains regex metacharacters is used verbatim; a plain string is
	 * preg_quote'd so characters like `-` can't misbehave.
	 *
	 * @param  string $filter
	 * @return string
	 */
	public static function buildRepoPattern($filter) {
		return preg_match('/[\\^$.*+?()[\]{}|]/', $filter)
			? $filter
			: preg_quote($filter, '/');
	}

	/**
	 * Filter a list of repo names by a user-supplied filter, case-insensitively.
	 * Preserves keys (array_filter), same as the original.
	 *
	 * @param  array  $repos
	 * @param  string $filter
	 * @return array
	 */
	public static function filterRepos(array $repos, $filter) {
		$pattern = self::buildRepoPattern($filter);

		return array_filter($repos, function($repo) use ($pattern) {
			return preg_match("/{$pattern}/i", $repo);
		});
	}

	/**
	 * Parse `gh`'s newline-delimited repo-name output into a list, dropping
	 * blank lines. Preserves keys (array_filter), same as the original.
	 *
	 * @param  string $output
	 * @return array
	 */
	public static function parseRepoNames($output) {
		return array_filter(explode("\n", $output));
	}

	/**
	 * Build the `gh` argument list to list an org's repos. With a limit, uses
	 * `gh repo list`; without one, falls back to the paginating `gh api` (the
	 * gh CLI has no pagination on `repo list`).
	 *
	 * @param  string   $org
	 * @param  int|false $repoLimit
	 * @return array
	 */
	public static function filteredReposCommand($org, $repoLimit) {
		$cmd = [];
		if ( $repoLimit ) {
			$cmd[] = "gh repo list {$org}";
			$cmd[] = '--json=name';
			$cmd[] = "--limit {$repoLimit}";
		} else {
			// There's no pagination in the gh cli, so we need to use gh api
			$cmd[] = 'gh api';
			$cmd[] = "-X GET '/orgs/{$org}/repos?per_page=500'";
			$cmd[] = "-H 'Accept: application/vnd.github+json'";
			$cmd[] = '--paginate';
		}

		return $cmd;
	}

	/**
	 * Build the `gh search repos` argument list.
	 *
	 * @param  string    $org
	 * @param  string    $repoSearch
	 * @param  int|false $repoLimit
	 * @return array
	 */
	public static function searchReposCommand($org, $repoSearch, $repoLimit) {
		$cmd = ["gh search repos"];

		// Add org filter
		$cmd[] = "--owner={$org}";
		$escaped = escapeshellarg($repoSearch);
		$cmd[] = "in:name {$escaped}";

		if ($repoLimit) {
			$cmd[] = "--limit=" . $repoLimit;
		}

		// Get just the repo names
		$cmd[] = '--json name';

		return $cmd;
	}

	private function createDirectories() {
		foreach ([$this->baseDir, $this->activityDir] as $dir) {
			if (!is_dir($dir)) {
				if (@mkdir($dir, 0777, true) === false) {
					$error = error_get_last();
					$errorMessage = $error['message'] ?? 'Unknown error';

					throw new \Exception(
						sprintf(
							"Failed to create directory %s: %s",
							$dir,
							$errorMessage
						),
						__LINE__
					);
				}
			}
		}
	}

	private function execGitHub($cmd) {
		$output = shell_exec($cmd);
		if ($output === null) {
			$this->cli->err("GitHub CLI command failed: $cmd");
			exit(1);
		}
		return $output;
	}

	private function jsonDecode($json, $context = '') {
		$data = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->cli->err("JSON decode error in $context: " . json_last_error_msg());
			exit(1);
		}
		return $data ?: [];
	}

	public function generate() {
		$repos = $this->getRepos();
		$total = count($repos);

		if ($total > 100 && empty($this->repoFilter)) {
			$this->cli->err("Organization has more than 100 repositories. Please provide a --repoFilter parameter.\n");
			exit(1);
		}

		if (!empty($this->repoFilter)) {
			$repos = self::filterRepos($repos, $this->repoFilter);
			$total = count($repos);
		}

		$this->cli->msg("Found $total " . ($this->repoFilter ? "filtered " : "") . "repositories\n", 'green');

		foreach ($repos as $i => $repo) {
			$repoName = $repo;
			$current = $i + 1;
			$this->cli->msg("[$current/$total] Processing $repoName...", 'yellow');

			$this->processRepo($repoName);

			$this->cli->msg(" done\n", 'green');
		}

		if (empty($this->activity)) {
			$this->cli->msg("\nNo activity found for the given time period.\n", 'yellow');
			exit(0);
		}

		$this->cli->msg("Writing monthly files...", 'yellow');
		$this->writeMonthlyFiles();
		$this->cli->msg(" done\n", 'green');

		$this->cli->msg("Writing index file...", 'yellow');
		$this->writeIndexFile();
		$this->cli->msg(" done\n", 'green');
	}

	private function getRepos() {
		$limited = $this->repoLimit !== 501;
		$limit = $limited ? " (limit {$this->repoLimit})" : '';
		$action = $this->repoSearch ? 'Searching' : 'Fetching';
		$this->cli->msg("{$action} repositories{$limit}...", 'yellow');

		$limit = $limited ? $this->repoLimit : false;
		$cmd = ! empty( $this->repoSearch )
			? self::searchReposCommand($this->org, $this->repoSearch, $limit)
			: self::filteredReposCommand($this->org, $limit);

		$cmd[] = "--jq '.[].name'";

		$json = $this->execGitHub(implode(' ', $cmd));

		return self::parseRepoNames($json);
	}

	private function processRepo($repoName) {
		$fullRepo = "{$this->org}/$repoName";

		// Get pull requests
		$this->cli->msg("\n  • Fetching pull requests...", 'cyan', false);
		$prsJson = $this->execGitHub("gh pr list --repo $fullRepo --author {$this->username} --state all --json number,title,url,createdAt,body,author,state,comments --search 'created:>={$this->since}'");
		$prs = $this->jsonDecode($prsJson, "pull requests for $repoName");
		$this->cli->msg(" found " . count($prs), 'green');

		if (!empty($prs)) {
			foreach ($prs as $pr) {
				// Fetch PR comments
				$this->cli->msg("    ↳ Fetching comments for PR #{$pr['number']}...", 'cyan', false);
				$commentsJson = $this->execGitHub("gh pr view {$pr['number']} --repo $fullRepo --json comments");
				$comments = $this->jsonDecode($commentsJson, "PR #{$pr['number']} comments")['comments'] ?? [];
				$this->cli->msg(" found " . count($comments), 'green');

				$pr['comments'] = $comments;
				$month = date('Y-m', strtotime($pr['createdAt']));
				$this->activity[$month]['pull_requests'][$repoName][] = $pr;
			}
		}

		// Get issues
		$this->cli->msg("  • Fetching issues...", 'cyan', false);
		$issuesJson = $this->execGitHub("gh issue list --repo $fullRepo --author {$this->username} --state all --json number,title,url,createdAt,body,author,state --search 'created:>={$this->since}'");
		$issues = $this->jsonDecode($issuesJson, "issues for $repoName");
		$this->cli->msg(" found " . count($issues), 'green');

		if (!empty($issues)) {
			foreach ($issues as $issue) {
				$month = date('Y-m', strtotime($issue['createdAt']));
				$this->activity[$month]['issues'][$repoName][] = $issue;
			}
		}
	}

	private function writeMonthlyFiles() {
		foreach ($this->activity as $month => $monthActivity) {
			$output = "# GitHub Activity - " . date('F Y', strtotime("$month-01")) . "\n\n";

			if (!empty($monthActivity['pull_requests'])) {
				$output .= "## Pull Requests\n\n";
				foreach ($monthActivity['pull_requests'] as $repo => $prs) {
					$output .= "### $repo\n\n";
					foreach ($prs as $pr) {
						$output .= "#### PR #{$pr['number']}: `{$pr['title']}`\n"
							. "**Status:** {$pr['state']}\n"
							. "**Author:** {$pr['author']['login']}\n"
							. "**Created:** " . date('Y-m-d', strtotime($pr['createdAt'])) . "\n"
							. "**URL:** {$pr['url']}\n\n"
							. "{$pr['body']}\n\n";

						if (!empty($pr['comments'])) {
							$output .= "##### PR #{$pr['number']} — Comments\n\n";
							foreach ($pr['comments'] as $comment) {
								$output .= "**{$comment['author']['login']}** on "
									. date('Y-m-d', strtotime($comment['createdAt']))
									. ":\n\n{$comment['body']}\n\n---\n\n";
							}
						}
					}
				}
			}

			if (!empty($monthActivity['issues'])) {
				$output .= "## Issues\n\n";
				foreach ($monthActivity['issues'] as $repo => $issues) {
					$output .= "### $repo\n\n";
					foreach ($issues as $issue) {
						$output .= "#### Issue #{$issue['number']}: `{$issue['title']}`\n"
							. "**Status:** {$issue['state']}\n"
							. "**Author:** {$issue['author']['login']}\n"
							. "**Created:** " . date('Y-m-d', strtotime($issue['createdAt'])) . "\n"
							. "**URL:** {$issue['url']}\n\n"
							. "{$issue['body']}\n\n";
					}
				}
			}

			$monthFile = "{$this->activityDir}/$month.md";
			$this->cli->writeToFile($monthFile, $output, [
				'relative' => false,
				'failExit' => true,
				'silent' => true,
			]);
		}
	}

	private function writeIndexFile() {
		$output = "# GitHub Activity for {$this->username} since {$this->since}\n\n";

		foreach ($this->activity as $month => $monthActivity) {
			if (!empty($monthActivity['pull_requests'])) {
				$output .= "## Pull Requests\n\n";
				foreach ($monthActivity['pull_requests'] as $repo => $prs) {
					$output .= "### $repo\n\n";
					foreach ($prs as $pr) {
						$output .= "- [[activity/$month.md#PR {$pr['number']} `{$pr['title']}`|`{$pr['title']}`]] ([GitHub]({$pr['url']})) - "
							. date('Y-m-d', strtotime($pr['createdAt'])) . "\n";
					}
					$output .= "\n";
				}
			}

			if (!empty($monthActivity['issues'])) {
				$output .= "## Issues\n\n";
				foreach ($monthActivity['issues'] as $repo => $issues) {
					$output .= "### $repo\n\n";
					foreach ($issues as $issue) {
						$output .= "- [[activity/$month.md#Issue {$issue['number']} `{$issue['title']}`|`{$issue['title']}`]] ([GitHub]({$issue['url']})) - "
							. date('Y-m-d', strtotime($issue['createdAt'])) . "\n";
					}
					$output .= "\n";
				}
			}
		}

		$indexFile = "{$this->baseDir}/{$this->fileName}.md";
		$this->cli->writeToFile($indexFile, $output, [
			'relative' => false,
			'failExit' => true,
			'silent' => true,
		]);

		$this->cli->msg("\nLog file saved to: $indexFile\n", 'green');
		$this->cli->msg("Activity files saved to: {$this->activityDir}\n", 'green');
	}
}
