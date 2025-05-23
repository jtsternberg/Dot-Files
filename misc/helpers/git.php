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
	public function __construct( Helpers $h = null ) {
		$this->setHelpers( $h );
	}

	/**
	 * Set Helpers obj.
	 *
	 * @since 1.0.1
	 *
	 * @param Helpers $h
	 */
	public function setHelpers( Helpers $h ) {
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
		return trim( `git reflog --pretty=format:'%s [%h, %cN, %ad]' -1` );
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
		$all = `git tag --sort={$sort} -n`;
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
	 * Get the current branch.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function currentBranch() {
		return trim( `git rev-parse --abbrev-ref HEAD` );
	}

	/**
	 * Get the current tracking remote.
	 *
	 * @since  1.0.2
	 *
	 * @return string
	 */
	public function currentRemote() {
		$info = trim( (string) `git branch -vv --color=never` );

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
		$rows = explode( "\n", `git tag --sort=creatordate` );
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
		$lasttag = $this->currentTag();

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

		$results = `$command`;
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

		$results = `$command`;
		$results = ! empty( $results ) ? explode( "\n", $results ) : [];
		$results = array_filter( $results );

		return $results;
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

			$remotes = explode( "\n", `git remote -v` );
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

}
