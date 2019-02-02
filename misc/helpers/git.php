<?php
/**
 * My CLI Git helpers.
 *
 * @since 1.0.1
 */

namespace JT\CLI\Helpers;
use JT\CLI\Exception as Exception;

/**
 * My CLI Git helpers.
 *
 * @since 1.0.1
 */
class Git {

	/**
	 * Fetch the last commit message.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function lastCommitMessage() {
		return trim( `git deflog --pretty=format:'%s [%h, %cN, %ad]' -1` );
	}

	/**
	 * Get the listing of all the tags.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function allTags() {
		return `git tag --sort=creatordate -n`;
	}

	/**
	 * Get the last/current tag.
	 *
	 * @since  1.0.1
	 *
	 * @return string
	 */
	public function currentTag() {
		return trim( `git describe --tags --abbrev=0` );
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

		$parts = explode( '.', $lasttag );
		$keys = array_keys( $parts );

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

		if ( ! isset( $parts[ $keys[ $type ] ] ) ) {
			throw new Exception( "The last tag ($lasttag) is missing the $type section.", 2 );
		}

		$index = $keys[ $type ];
		// Increase the requested version.
		$parts[ $index ]++;
		// Then loop through the rest of the version parts and zero them out.
		while ( isset( $parts[ ++$index ] ) ) {
			$parts[ $index ] = 0;
		}

		$nextTag = implode( '.', $parts );
		return $nextTag;
	}
}

return new Git;