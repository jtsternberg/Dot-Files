<?php
/**
 * My CLI Git helpers.
 *
 * @since 1.0.1
 */

namespace JT\CLI\Helpers;
use JT\CLI\Exception;
use JT\CLI\Helpers;


/**
 * My CLI Git helpers.
 *
 * @since 1.0.1
 */
class Git {

	/**
	 * Helpers object
	 *
	 * @var Helpers
	 */
	protected $helpers;

	/**
	 * Constructor
	 *
	 * @since 1.0.1
	 *
	 * @param Helpers $h
	 */
	public function __construct( ?Helpers $h = null ) {
		$this->setHelpers( $h );
	}

	/**
	 * Set Helpers obj.
	 *
	 * @since 1.0.1
	 *
	 * @param Helpers $h
	 */
	public function setHelpers( ?Helpers $h = null ) {
		$this->helpers = $h;
	}

	/**
	 * Fetch the last commit message.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function lastCommitMessage() {
		return trim( (string) shell_exec( "git reflog --pretty=format:'%s [%h, %cN, %ad]' -1" ) );
	}

	/**
	 * Get the listing of all the tags.
	 *
	 * @since  1.0.1
	 *
	 * @param  boolean $reverse Whether to reverse the output (oldest at bottom).
	 * @param  boolean $number  Number of rows to get. Defaults to all.
	 *
	 * @return string
	 */
	public function listTags( $reverse = false, $number = false ) {
		$sort = $reverse ? '-creatordate' : 'creatordate';
		$all = shell_exec( "git tag --sort={$sort} -n" );
		if ( ! $number ) {
			return $all;
		}

		$rows = explode( "\n", $all );
		$count = count( $rows );
		if ( $count <= $number ) {
			return $all;
		}

		$some = array_splice( $rows, $count - intval( $number ) - 1 );
		array_unshift( $some, "...\n" );
		$some = implode( "\n", $some );

		return $some;
	}

	/**
	 * Get the main branch.
	 *
	 * @since  1.6.0
	 *
	 * @return string
	 */
	public function getMainBranch() {
		return trim( (string) shell_exec( "git branch -rl '*/HEAD' | rev | cut -d/ -f1 | rev | head -1" ) );
	}

	/**
	 * Get the current branch.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function currentBranch() {
		return trim( (string) shell_exec( "git rev-parse --abbrev-ref HEAD" ) );
	}

	/**
	 * Get the changed files between two branches.
	 *
	 * @since  1.6.0
	 *
	 * @param  string $baseBranch The base branch.
	 * @param  string $currentBranch The current branch.
	 *
	 * @return string
	 */
	public function getChangedFiles( $baseBranch = '', $currentBranch = '' ) {
		if ( empty( $baseBranch ) ) {
			$baseBranch = $this->getMainBranch();
		}

		if ( empty( $currentBranch ) ) {
			$currentBranch = $this->currentBranch();
		}

		$gitOutput = shell_exec( "git diff --name-status {$baseBranch}..{$currentBranch}" );
		return trim( $gitOutput ?: '' );
	}

	/**
	 * Get the current tracking remote.
	 *
	 * @since  1.0.2
	 *
	 * @return string
	 */
	public function currentRemote() {
		$info = trim( (string) shell_exec( "git branch -vv --color=never" ) );

		// First, split the output into individual lines.
		$lines = explode( "\n", $info );

		// Loop through the lines and find the one that starts with a '*'.
		$remote = null;
		foreach ( $lines as $line ) {
			if ( strpos( $line, '*' ) === 0 ) {
				$remote = $line;
			}
		}

		// If we can't find the remote branch, return early.
		if ( ! $remote ) {
			return '';
		}

		$parts = explode( ' [', $remote );

		if ( empty( $parts[1] ) ) {
			return '';
		}

		$parts = explode( ']', $parts[1] );
		return $parts[0];
	}

	/**
	 * Get the last/current tag.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function currentTag() {
		$rows = explode( "\n", shell_exec( "git tag --sort=creatordate" ) );
		$rows = array_filter( $rows, 'trim' );
		$last = end( $rows );

		return $last;
	}

	/**
	 * Get the next tag, based on the current tag, and the requested
	 * type (major, minor, patch, subpatch). Defaults to patch.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $type The type of version to get.
	 *
	 * @return string
	 * @throws Exception If the next tag cannot be parsed.
	 */
	public function getNextTag( $type = 'patch' ) {
		return self::incrementTag( $this->currentTag(), $type );
	}

	/**
	 * Compute the next tag from a given last tag and requested type. Pure: no
	 * git shell-out, so it is unit-testable (the shell-out lives in
	 * getNextTag()/currentTag()).
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $lasttag The current/last tag (may be empty).
	 * @param  string  $type    major, minor, patch, or subpatch. Defaults to patch.
	 *
	 * @return string
	 * @throws Exception If the type is unrecognized (code 1) or the last tag is
	 *                   missing the requested section (code 2).
	 */
	public static function incrementTag( $lasttag, $type = 'patch' ) {
		if ( empty( $lasttag ) ) {
			$parts = [ 0, 0, 0 ];
			$index = 0;
		} else {
			$parts = explode( '.', $lasttag );
		}

		// Allowed version number parts.
		$keys = array(
			'major'    => 0,
			'minor'    => 1,
			'patch'    => 2,
			'subpatch' => 3, // Should probably avoid this one.
		);

		$type = str_replace( '"', '', $type );
		$type = str_replace( "'", '', $type );
		$type = trim( $type );

		if ( empty( $type ) ) {
			$type = 'patch';
		}

		if ( ! isset( $keys[ $type ] ) ) {
			$types = implode( ', ', array_keys( $keys ) );
			$error = new Exception( "$type is not recognized. You can use one of the following: $types.", 1 );
			$error->data = $type;
			throw $error;
		}

		$index = $keys[ $type ];

		if ( empty( $lasttag ) ) {
			$parts = [ 0, 0, 0 ];
			$index = 0;
		}

		if ( ! isset( $parts[ $index ] ) ) {
			throw new Exception( "The last tag ($lasttag) is missing the $type section.", 2 );
		}

		// Increase the requested version.
		$parts[ $index ]++;
		// Then loop through the rest of the version parts and zero them out.
		while ( isset( $parts[ ++$index ] ) ) {
			$parts[ $index ] = 0;
		}

		$nextTag = implode( '.', $parts );
		return $nextTag;
	}

	/**
	 * Whether given tagname is valid SEMVER.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $tag Tag to check.
	 *
	 * @return boolean
	 */
	public function validTag( $tag ) {
		$invalid = (
			// No "v"
			0 !== strpos( $tag, 'v' )
			// Not enough decimals
			|| 2 !== substr_count( $tag, '.' )
			// Decimal at end?
			|| '.' === substr( $tag, -1 )
			// Adjacent decimals?
			|| false !== strpos( $tag, '..' )
		);
		return ! $invalid;
	}

	/**
	 * Get list of modified files.
	 *
	 * @since  1.1.9
	 *
	 * @param  string $matches Limit results with grep.
	 *
	 * @return array Results
	 */
	public function getModified( $matches = '' ) {
		$command = 'git diff-index --name-only --diff-filter=ACMR HEAD --';
		if ( ! empty( $matches ) ) {
			$command .= ' | grep ' . $matches;
		}

		$results = shell_exec( $command );
		$results = ! empty( $results ) ? explode( "\n", $results ) : [];
		$results = array_filter( $results );

		return $results;
	}

	/**
	 * Returns a list of file paths of changed files between two points.
	 *
	 * @since 1.3.0
	 *
	 * @param string $start           The start commit/tag/branch.
	 * @param string $end             The end commit/tag/branch. Optional. Defaults to HEAD.
	 * @param string $additionalFlags Additional flags to pass to git diff.
	 *
	 * @return array Results.
	 */
	public function getFilesChanged( $start = '', $end = 'HEAD', $additionalFlags = '' ) {
		$command = "git diff --name-only {$additionalFlags} {$start} {$end}";

		$results = shell_exec( $command );
		$results = ! empty( $results ) ? explode( "\n", $results ) : [];
		$results = array_filter( $results );

		return $results;
	}

	/**
	 * Pull tags from remote repository.
	 *
	 * @since  1.6.1
	 *
	 * @return boolean True if successful, false if failed
	 */
	public function pullTags() {
		exec( 'git fetch --tags 2>/dev/null', $output, $result );
		return 0 === $result;
	}

	/**
	 * Get the repository path from the remote URL.
	 *
	 * @since  {{next}}
	 *
	 * @return string Repository path in format "owner/repo"
	 */
	public function getRepoPathFromUrl() {
		$remote = $this->getRepoUrl();
		$remote = explode( ':', $remote )[1] ?? '';
		// Remove .git suffix from the remote
		$remote = preg_replace( '/\.git$/', '', $remote );

		return $remote;
	}

	/**
	 * Get the remote repository URL.
	 *
	 * @since  {{next}}
	 *
	 * @return string Remote repository URL
	 */
	public function getRepoUrl() {
		// Try to get the URL from the current branch's upstream remote
		$upstream = trim( (string) shell_exec( "git rev-parse --abbrev-ref --symbolic-full-name @{u} 2>/dev/null" ) );

		if ( $upstream && strpos( $upstream, '/' ) !== false ) {
			$remote = explode( '/', $upstream )[0];
			$url = trim( (string) shell_exec( "git remote get-url {$remote} 2>/dev/null" ) );
			if ( $url ) {
				return $url;
			}
		}

		// Fallback 1: Try origin remote
		$url = trim( (string) shell_exec( "git remote get-url origin 2>/dev/null" ) );
		if ( $url ) {
			return $url;
		}

		// Fallback 2: Get the first available remote
		$remotes = explode( "\n", trim( (string) shell_exec( "git remote 2>/dev/null" ) ) );
		if ( ! empty( $remotes[0] ) ) {
			$url = trim( (string) shell_exec( "git remote get-url {$remotes[0]} 2>/dev/null" ) );
			if ( $url ) {
				return $url;
			}
		}

		// Fallback 3: Try git config
		$url = trim( (string) shell_exec( "git config --get remote.origin.url 2>/dev/null" ) );
		if ( $url ) {
			return $url;
		}

		// If all else fails, return empty string
		return '';
	}

	/**
	 * Pre-push helper to push to an alternate repo.
	 *
	 * @since  1.0.1
	 *
	 * @param  string  $altRemote The alternate repo url.
	 *
	 * @return bool
	 */
	public function pushAlternate( $altRemote ) {

		// Check if we're pushing to the alternate repo...
		if ( ! $this->helpers->getArg( 2 ) || $altRemote !== $this->helpers->getArg( 2 ) ) {
			// If not, let's push to that repo as well.

			$remotes = explode( "\n", shell_exec( "git remote -v" ) );
			$remote = '';
			foreach ( $remotes as $line ) {
				if ( false !== strpos( $line, $altRemote ) ) {
					$parts = explode( $altRemote, $line );
					$remote = trim( $parts[0] );
					break;
				}
			}
			if ( ! $remote ) {
				return false;
			}

			$branch = $this->currentBranch();
			$this->helpers->msg( "> ALSO pushing to this alternate repo: {$remote} ({$altRemote})", 'yellow' );

			// Get the branch being pushed, then push to the alternate repo.
			$this->helpers->msg( "$ git push {$remote} {$branch}", 'green' );
			echo exec( "git push {$remote} {$branch}" );
		}

		return true;
	}

	/**
	 * Whether a directory is inside a git work tree.
	 *
	 * Unlike the tag/branch/remote helpers above, the methods from here down are
	 * directory-scoped (they operate on an arbitrary path via `git -C`, not the
	 * current working directory) and answer with booleans/exit codes rather than
	 * command text. They are the low-level primitives shared by commands that
	 * reason about individual files' git state (e.g. `xname`).
	 *
	 * @since 1.7.0
	 *
	 * @param string $dir Directory to test.
	 * @return bool
	 */
	public function isInGit( string $dir ): bool {
		[ $code, $out ] = $this->runIn( $dir, [ 'rev-parse', '--is-inside-work-tree' ] );

		return 0 === $code && 'true' === $out;
	}

	/**
	 * Absolute repo top level for a directory, or null when it is not in a repo.
	 *
	 * @since 1.7.0
	 *
	 * @param string $dir Directory inside the repo.
	 * @return string|null
	 */
	public function topLevel( string $dir ): ?string {
		[ $code, $out ] = $this->runIn( $dir, [ 'rev-parse', '--show-toplevel' ] );

		return 0 === $code && '' !== $out ? $out : null;
	}

	/**
	 * Whether a file is tracked (present in the index).
	 *
	 * @since 1.7.0
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function isTracked( string $path ): bool {
		[ $code ] = $this->runIn(
			dirname( $path ),
			[ 'ls-files', '--error-unmatch', '--', basename( $path ) ]
		);

		return 0 === $code;
	}

	/**
	 * Whether a file exists in the committed HEAD tree.
	 *
	 * A staged-but-never-committed file is tracked yet not in HEAD, so this is
	 * how "already in version control" is distinguished from "merely staged".
	 *
	 * @since 1.7.0
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function isInHead( string $path ): bool {
		$root = $this->topLevel( dirname( $path ) );
		if ( null === $root ) {
			return false;
		}

		$prefix = rtrim( $root, '/' ) . '/';
		$real   = realpath( $path );
		$abs    = false !== $real ? $real : $path;
		if ( 0 !== strpos( $abs, $prefix ) ) {
			return false;
		}

		$relative = substr( $abs, strlen( $prefix ) );
		[ $code ] = $this->runIn( dirname( $path ), [ 'cat-file', '-e', 'HEAD:' . $relative ] );

		return 0 === $code;
	}

	/**
	 * Whether a file has no pending changes (nothing in `status --porcelain`).
	 *
	 * @since 1.7.0
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function isClean( string $path ): bool {
		[ $code, $out ] = $this->runIn(
			dirname( $path ),
			[ 'status', '--porcelain', '--', basename( $path ) ]
		);

		return 0 === $code && '' === $out;
	}

	/**
	 * Rename a tracked file in place with `git mv`, preserving history.
	 *
	 * @since 1.7.0
	 *
	 * @param string $oldPath Current path.
	 * @param string $newPath Target path (same directory).
	 * @return bool Whether the target now exists.
	 */
	public function mv( string $oldPath, string $newPath ): bool {
		[ $code ] = $this->runIn(
			dirname( $oldPath ),
			[ 'mv', '--', basename( $oldPath ), basename( $newPath ) ]
		);

		return 0 === $code && file_exists( $newPath );
	}

	/**
	 * Commit only the given pathspecs under a repo, leaving other staged work
	 * for the user. Returns the resulting short SHA, or null on failure.
	 *
	 * @since 1.7.0
	 *
	 * @param string   $repoRoot  Repo top level.
	 * @param string   $message   Full commit message.
	 * @param string[] $pathspecs Paths (relative to the repo root) to commit.
	 * @return string|null
	 */
	public function commitPaths( string $repoRoot, string $message, array $pathspecs ): ?string {
		$args = [ 'commit', '-m', $message, '--' ];
		foreach ( $pathspecs as $spec ) {
			$args[] = $spec;
		}

		[ $code ] = $this->runIn( $repoRoot, $args );
		if ( 0 !== $code ) {
			return null;
		}

		[ $revCode, $sha ] = $this->runIn( $repoRoot, [ 'rev-parse', '--short', 'HEAD' ] );

		return 0 === $revCode && '' !== $sha ? $sha : null;
	}

	/**
	 * Run git in a directory, returning its exit code and trimmed stdout.
	 *
	 * @param string   $dir
	 * @param string[] $args
	 * @return array{0: int, 1: string}
	 */
	private function runIn( string $dir, array $args ): array {
		$cmd = 'git -C ' . escapeshellarg( $dir );
		foreach ( $args as $arg ) {
			$cmd .= ' ' . escapeshellarg( $arg );
		}

		$out  = [];
		$code = 0;
		exec( $cmd . ' 2>/dev/null', $out, $code );

		return [ $code, trim( implode( "\n", $out ) ) ];
	}

}
