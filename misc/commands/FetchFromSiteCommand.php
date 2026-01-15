<?php

namespace JT\CLI\Commands;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Base class for fetching posts from WordPress sites via REST API.
 *
 * Handles post retrieval, HTML to markdown conversion, and file output.
 */
class FetchFromSiteCommand extends SiteCommand {
	protected $postId;
	protected $postSlug;
	protected $outputFile = '/tmp/fetchedpost.md';
	protected $convertToMarkdown = true;
	protected $openAfterFetch = false;

	public function __construct( $cli ) {
		parent::__construct( $cli );

		// Get post ID/slug from flag or positional argument
		$postIdentifier = $cli->getFlag( 'postId' ) ?: $cli->getArg( 1 );

		if ( empty( $postIdentifier ) ) {
			throw new \Exception( "Error: Post ID or slug is required. Pass as --postId=ID or as first argument." );
		}

		// If numeric, treat as ID; otherwise treat as slug
		if ( is_numeric( $postIdentifier ) ) {
			$this->postId = (int) $postIdentifier;
		} else {
			$this->postSlug = $postIdentifier;
		}

		$this->outputFile = $cli->getFlag( 'outputFile' ) ?: $this->outputFile;
		$this->convertToMarkdown = $cli->getFlag( 'rawHtml' ) !== true;
		$this->openAfterFetch = $cli->hasFlag( 'open' );
	}

	/**
	 * Resolve a post slug to its ID.
	 *
	 * @param string $slug
	 * @return int Post ID
	 * @throws \Exception If post not found
	 */
	protected function resolveSlugToId( string $slug ): int {
		$baseUrl = $this->resolveRestUrl();
		$url = rtrim( $baseUrl, '/' );
		if ( ! preg_match( '/\/posts\/?$/', $url ) ) {
			$url .= '/posts';
		}
		$url .= '?slug=' . urlencode( $slug ) . '&status=any';

		$posts = $this->executeRequest( $url, 'GET', null, 200 );

		if ( empty( $posts ) ) {
			throw new \Exception( "Error: No post found with slug '{$slug}'" );
		}

		return (int) $posts[0]['id'];
	}

	/**
	 * Fetch a post by ID from the WordPress REST API.
	 *
	 * @param int $postId
	 * @return array Post data from API
	 */
	protected function fetchPost( int $postId ): array {
		$baseUrl = $this->resolveRestUrl();

		// Ensure URL ends with posts endpoint and add post ID
		$url = rtrim( $baseUrl, '/' );
		if ( ! preg_match( '/\/posts\/?$/', $url ) ) {
			$url .= '/posts';
		}
		$url .= '/' . $postId;

		return $this->executeRequest( $url, 'GET', null, 200 );
	}

	/**
	 * Convert HTML content to Markdown.
	 *
	 * @param string $html
	 * @return string
	 */
	protected function convertHtmlToMarkdown( string $html ): string {
		$converter = new HtmlConverter( [
			'strip_tags' => true,
			'hard_break' => true,
		] );

		return $converter->convert( $html );
	}

	/**
	 * Process fetched content. Override in child classes for custom processing.
	 *
	 * @param array $post Full post data from API
	 * @return string Processed content
	 */
	protected function processContent( array $post ): string {
		$content = $post['content']['rendered'] ?? '';

		if ( $this->convertToMarkdown ) {
			$content = $this->convertHtmlToMarkdown( $content );
		}

		return $content;
	}

	/**
	 * Build output content including title.
	 *
	 * @param array $post
	 * @param string $content Processed content
	 * @return string Full output
	 */
	protected function buildOutput( array $post, string $content ): string {
		$title = $post['title']['rendered'] ?? '';

		if ( $this->convertToMarkdown && ! empty( $title ) ) {
			// Add title as H1 heading for markdown output
			return "# {$title}\n\n{$content}";
		}

		return $content;
	}

	/**
	 * Open the output file in the default editor.
	 */
	protected function openFile(): void {
		$escapedPath = escapeshellarg( $this->outputFile );
		exec( "open {$escapedPath}" );
	}

	public function run(): void {
		$this->cli->msg( "Fetching post from site...\n", 'yellow' );

		// Load environment variables
		$this->loadEnv();

		// Set credentials from ENV
		$this->setCredentials();

		// Resolve slug to ID if needed
		if ( ! empty( $this->postSlug ) ) {
			$this->cli->msg( "Looking up post by slug: {$this->postSlug}...\n" );
			$this->postId = $this->resolveSlugToId( $this->postSlug );
		}

		// Fetch the post
		$this->cli->msg( "Fetching post ID: {$this->postId}...\n" );
		$post = $this->fetchPost( $this->postId );

		$title = $post['title']['rendered'] ?? 'Untitled';
		$this->cli->msg( "Title: {$title}\n", 'green' );

		// Process content
		if ( $this->convertToMarkdown ) {
			$this->cli->msg( "Converting HTML to Markdown...\n" );
		}
		$content = $this->processContent( $post );

		// Build final output
		$output = $this->buildOutput( $post, $content );

		// Write to file
		file_put_contents( $this->outputFile, $output );

		$this->cli->msg( "\n✓ Post fetched successfully!\n", 'green' );
		$this->cli->msg( "Output file: {$this->outputFile}\n\n", 'cyan' );

		// Open file if requested
		if ( $this->openAfterFetch ) {
			$this->openFile();
		}
	}
}
