<?php

namespace JT\CLI\Commands;

use Symfony\Component\Yaml\Yaml;

class PublishToSiteCommand extends SiteCommand {
	protected $title;
	protected $contentFile = '/tmp/draftpost.md';
	protected $status = 'draft';
	protected $statusExplicitlySet = false;
	protected $postContent = '';
	protected $disableMarkdown = false;
	protected $extractTitle = false;
	protected $postId = null;
	protected $postSlug = null;
	protected $frontmatter = [];

	public function __construct( $cli ) {
		parent::__construct( $cli );

		$this->disableMarkdown = $cli->getFlag( 'disableMarkdown' ) === true;
		$this->extractTitle = ! $this->disableMarkdown;
		if ( $cli->hasFlag( 'extractTitle' ) ) {
			$flagValue = $cli->getFlag( 'extractTitle' );
			// If flag is passed without value (''), treat as true
			// If flag has explicit value, use filter_var to handle 'false', '0', etc.
			$this->extractTitle = ( $flagValue === '' ) || filter_var( $flagValue, FILTER_VALIDATE_BOOLEAN );
		}
		$this->title = $cli->getFlag( 'title' );

		// Title is required unless extractTitle is enabled
		if ( ! $this->title && ! $this->extractTitle ) {
			throw new \Exception( "Error: Title is required. Please pass it as --title=TITLE or use --extractTitle." );
		}

		$this->contentFile = $cli->getFlag( 'contentFile' ) ?: $this->contentFile;
		if ( $cli->getFlag( 'status' ) ) {
			$this->status = $cli->getFlag( 'status' );
			$this->statusExplicitlySet = true;
		}

		// Handle postId - can be numeric ID or slug
		$postIdentifier = $cli->getFlag( 'postId' ) ?: null;
		if ( $postIdentifier ) {
			if ( is_numeric( $postIdentifier ) ) {
				$this->postId = (int) $postIdentifier;
			} else {
				$this->postSlug = $postIdentifier;
			}
		}
	}

	/**
	 * Resolve a post slug to its ID.
	 *
	 * @param string $slug
	 * @return int Post ID
	 * @throws \Exception If post not found
	 */
	protected function resolveSlugToId( string $slug ): int {
		$baseUrl = parent::resolveRestUrl();
		$url = rtrim( $baseUrl, '/' ) . '?slug=' . urlencode( $slug ) . '&status=any';

		$posts = $this->executeRequest( $url, 'GET' );

		if ( empty( $posts ) ) {
			throw new \Exception( "Error: No post found with slug '{$slug}'" );
		}

		return (int) $posts[0]['id'];
	}

	/**
	 * Check if we're updating an existing post.
	 */
	protected function isUpdate(): bool {
		return ! empty( $this->postId ) || ! empty( $this->postSlug );
	}

	/**
	 * Get the resolved REST URL, appending postId if updating.
	 * Preserves any query string parameters.
	 */
	protected function resolveRestUrl(): string {
		$url = parent::resolveRestUrl();

		if ( $this->isUpdate() ) {
			$parsed = parse_url( $url );
			$baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
			if ( ! empty( $parsed['port'] ) ) {
				$baseUrl .= ':' . $parsed['port'];
			}
			$path = rtrim( $parsed['path'] ?? '', '/' ) . '/' . $this->postId;
			$url = $baseUrl . $path;
			if ( ! empty( $parsed['query'] ) ) {
				$url .= '?' . $parsed['query'];
			}
		}

		return $url;
	}

	/**
	 * Extract title from first line if it's a markdown H1 heading.
	 * Only processes when extractTitle is enabled and markdown is enabled.
	 *
	 * @return bool True if title was extracted, false otherwise
	 */
	protected function maybeExtractTitle(): bool {
		if ( ! $this->extractTitle ) {
			return false;
		}

		if ( empty( $this->postContent ) ) {
			return false;
		}

		$firstLine = $this->extractFirstLineFromContent();

		// Check if first line is a markdown H1 heading (# Title)
		if ( preg_match( '/^#\s+(.+)$/', $firstLine, $matches ) ) {
			$this->title = trim( $matches[1] );

			// Remove the first line from content
			return $this->removeFirstLineFromContent();
		}

		return false;
	}

	public function extractFirstLineFromContent() {
		if ( empty( $this->postContent ) ) {
			return false;
		}

		$lines = explode( "\n", $this->postContent );
		$firstLine = trim( $lines[0] );

		return $firstLine;
	}

	/**
	 * Remove the first line from the content.
	 *
	 * @return bool True if the first line was removed, false otherwise
	 */
	public function removeFirstLineFromContent() {
		if ( empty( $this->postContent ) ) {
			return false;
		}

		$lines = explode( "\n", $this->postContent );
		array_shift( $lines );
		$this->postContent = implode( "\n", $lines );

		// Remove any leading or trailing newlines.
		$this->postContent = trim( $this->postContent );

		return true;
	}

	/**
	 * Strip YAML frontmatter from content if present.
	 * Frontmatter is delimited by --- at the start and end.
	 * Parsed frontmatter is stored in $this->frontmatter.
	 *
	 * @return bool True if frontmatter was stripped, false otherwise
	 */
	protected function stripFrontmatter(): bool {
		if ( empty( $this->postContent ) ) {
			return false;
		}

		// Check if content starts with frontmatter delimiter
		if ( ! preg_match( '/^---\s*\n/', $this->postContent ) ) {
			return false;
		}

		// Find the closing delimiter and extract frontmatter content
		if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n?/s', $this->postContent, $matches ) ) {
			// Parse simple YAML key: value pairs
			$this->frontmatter = $this->parseYaml( $matches[1] );

			$this->postContent = substr( $this->postContent, strlen( $matches[0] ) );
			$this->postContent = ltrim( $this->postContent );
			return true;
		}

		return false;
	}

	/**
	 * Parse YAML content.
	 *
	 * @param string $yaml Raw YAML content
	 * @return array Parsed data
	 */
	protected function parseYaml( string $yaml ): array {
		return Yaml::parse( $yaml ) ?: [];
	}

	/**
	 * Use frontmatter 'id' as fallback for postId if not already set.
	 *
	 * @return bool True if postId was set from frontmatter, false otherwise
	 */
	protected function maybeSetPostIdFromFrontmatter(): bool {
		if ( ! empty( $this->postId ) || ! empty( $this->postSlug ) ) {
			return false;
		}

		if ( ! empty( $this->frontmatter['id'] ) ) {
			$id = $this->frontmatter['id'];
			if ( is_numeric( $id ) ) {
				$this->postId = (int) $id;
			} else {
				$this->postSlug = $id;
			}
			return true;
		}

		return false;
	}

	/**
	 * Process content before conversion. Override in child classes for specific behavior.
	 */
	protected function processContent(): void {
		// Base implementation does nothing
	}

	protected function convertMarkdownToHtml(): string {
		if ( ! file_exists( $this->contentFile ) ) {
			throw new \Exception( "Error: Content file not found: {$this->contentFile}" );
		}

		// Check if marked is installed
		$markedCheck = `which marked 2>/dev/null`;
		if ( empty( trim( $markedCheck ) ) ) {
			throw new \Exception( "Error: 'marked' command not found. Run 'npm install -g marked' to install it." );
		}

		// Write modified content to temporary file
		$tmpFile = tempnam( sys_get_temp_dir(), 'draftpost_md_' );
		file_put_contents( $tmpFile, $this->postContent );

		// Convert markdown to HTML
		// Convert markdown to HTML using file output to avoid buffer truncation
		$escapedFile = escapeshellarg( $tmpFile );
		shell_exec( "marked -i $escapedFile -o $escapedFile" );
		$html = file_get_contents( $tmpFile );

		// Clean up temporary file
		unlink( $tmpFile );

		if ( empty( $html ) ) {
			throw new \Exception( "Error: Failed to convert markdown to HTML" );
		}

		return json_encode( $html, JSON_UNESCAPED_SLASHES );
	}

	protected function prepareContent(): string {
		if ( $this->disableMarkdown ) {
			// Expect raw HTML, just JSON-encode it
			return json_encode( $this->postContent, JSON_UNESCAPED_SLASHES );
		}

		return $this->convertMarkdownToHtml();
	}

	protected function publishToSite( string $title, string $content, array $additionalPayload = [] ): array {
		$url = $this->resolveRestUrl();

		$payload = array_merge( [
			'title' => $title,
			'content' => json_decode( $content ),
		], $additionalPayload );

		// Only include status for new posts or when explicitly set
		if ( ! $this->isUpdate() || $this->statusExplicitlySet ) {
			$payload['status'] = $this->status;
		}

		return $this->executeRequest( $url, 'POST', $payload );
	}

	public function run(): void {
		$this->cli->msg( "Publishing post to site...\n", 'yellow' );

		// Load environment variables
		$this->loadEnv();

		// Set credentials from ENV
		$this->setCredentials();

		// Resolve slug to ID if needed
		if ( ! empty( $this->postSlug ) ) {
			$this->cli->msg( "Looking up post by slug: {$this->postSlug}...\n" );
			$this->postId = $this->resolveSlugToId( $this->postSlug );
			$this->postSlug = null; // Clear slug now that we have ID
		}

		$this->postContent = trim( file_get_contents( $this->contentFile ) );

		// Strip YAML frontmatter if present
		$this->stripFrontmatter();

		// Use frontmatter id as fallback for postId
		$this->maybeSetPostIdFromFrontmatter();

		// Extract title from first line if enabled
		$this->maybeExtractTitle();

		// Title is required unless extractTitle is enabled
		if ( ! $this->title ) {
			throw new \Exception( "Error: Title is required. Please pass it as --title=TITLE." );
		}

		// Allow child classes to process content
		$this->processContent();

		// Ensure we have a title after extraction/processing
		if ( empty( $this->title ) ) {
			throw new \Exception( "Error: No title found. Unable to extract title from content." );
		}

		// Display title
		$this->cli->msg( "Title: {$this->title}\n", 'green' );

		// Prepare content (markdown to HTML or raw HTML)
		if ( $this->disableMarkdown ) {
			$this->cli->msg( "Using raw HTML content...\n" );
		} else {
			$this->cli->msg( "Converting markdown to HTML...\n" );
		}
		$content = $this->prepareContent();

		// Publish to site
		$this->cli->msg( "Publishing to site..." );
		$result = $this->publishToSite( $this->title, $content );

		// Extract edit URL
		$editUrl = $result['link'] ?? '';
		if ( empty( $editUrl ) ) {
			throw new \Exception( "Error: Could not extract edit URL from response" );
		}

		// Get hostname/protocol from edit URL
		$parsedUrl = parse_url( $editUrl );
		$hostname = $parsedUrl['host'];
		$protocol = $parsedUrl['scheme'];
		$editUrl = $protocol . '://' . $hostname . '/wp-admin/post.php?post=' . $result['id'] . '&action=edit';

		$action = $this->isUpdate() ? 'updated' : 'created';
		$this->cli->msg( "\n✓ Post $action successfully!\n", 'green' );
		$this->cli->msg( "Edit URL: $editUrl\n\n", 'cyan' );
	}
}
