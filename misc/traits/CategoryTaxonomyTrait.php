<?php

namespace JT\CLI\Traits;

/**
 * Trait for fetching and displaying WordPress category taxonomy terms.
 *
 * Classes using this trait must have:
 * - $cli property (CLI helpers instance)
 * - $flushCache property (bool)
 * - $allTermsCache property (array|null)
 * - buildBasicAuthHeader() method
 * - resolveRestUrl() method
 */
trait CategoryTaxonomyTrait {

	/**
	 * Get cache file path for terms.
	 *
	 * @param string $key Cache key
	 * @return string Cache file path
	 */
	protected function getCacheFilePath( $key ): string {
		return sys_get_temp_dir() . "/jts_category_cache_{$key}.json";
	}

	/**
	 * Get cached terms if still valid (within 30 minutes).
	 *
	 * @param string $key Cache key
	 * @return array|null Returns cached terms or null if cache is invalid/missing/flushed
	 */
	protected function getCachedTerms( $key ): ?array {
		if ( $this->flushCache ) {
			return null;
		}

		$cacheFile = $this->getCacheFilePath( $key );

		if ( ! file_exists( $cacheFile ) ) {
			return null;
		}

		$minutes = 30;
		$exp = $minutes * 60;

		if ( time() - filemtime( $cacheFile ) > $exp ) {
			unlink( $cacheFile );
			return null;
		}

		$cached = json_decode( file_get_contents( $cacheFile ), true );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Cache terms to file.
	 *
	 * @param string $key Cache key
	 * @param array $terms Terms to cache
	 */
	protected function cacheTerms( $key, array $terms ): void {
		$cacheFile = $this->getCacheFilePath( $key );
		file_put_contents( $cacheFile, json_encode( $terms ) );
	}

	/**
	 * Get the categories REST URL.
	 *
	 * @return string
	 */
	protected function getCategoriesUrl(): string {
		$baseUrl = $this->resolveRestUrl();
		$baseUrl = rtrim( $baseUrl, '/' );
		// Remove /posts if present to get base API URL
		$baseUrl = preg_replace( '/\/posts\/?$/', '', $baseUrl );
		return $baseUrl . '/categories';
	}

	/**
	 * Fetch category taxonomy terms with pagination support.
	 * Results are cached.
	 *
	 * @return array All terms
	 */
	protected function fetchCategoryTerms(): array {
		$cacheKey = 'all';
		$cached = $this->getCachedTerms( $cacheKey );
		if ( $cached !== null ) {
			return $cached;
		}

		$allTerms = [];
		$page = 1;
		$credentials = $this->buildBasicAuthHeader();

		do {
			$url = $this->getCategoriesUrl() . "?per_page=100&page={$page}";

			$ch = curl_init( $url );
			curl_setopt_array( $ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_HTTPHEADER => [
					"Authorization: Basic $credentials",
					'Content-Type: application/json',
				],
			] );

			$response = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$headerSize = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );

			if ( $httpCode !== 200 ) {
				throw new \Exception( "Error: Failed to fetch categories. HTTP $httpCode" );
			}

			$headers = substr( $response, 0, $headerSize );
			$body = substr( $response, $headerSize );

			$terms = json_decode( $body, true );
			$allTerms = array_merge( $allTerms, $terms );

			$totalPages = 1;
			if ( preg_match( '/X-WP-TotalPages:\s*(\d+)/i', $headers, $matches ) ) {
				$totalPages = (int) $matches[1];
			}

			$page++;
		} while ( $page <= $totalPages );

		$this->cacheTerms( $cacheKey, $allTerms );

		return $allTerms;
	}

	/**
	 * Fetch ALL category taxonomy terms and organize by parent ID.
	 * Results are cached.
	 *
	 * @return array Terms organized by parent ID: [parentId => [terms...]]
	 */
	protected function fetchAllCategoryTerms(): array {
		if ( $this->allTermsCache !== null ) {
			return $this->allTermsCache;
		}

		$cacheKey = 'all_organized';
		$cached = $this->getCachedTerms( $cacheKey );
		if ( $cached !== null ) {
			$this->allTermsCache = $cached;
			return $cached;
		}

		$allTerms = $this->fetchCategoryTerms();

		$termsByParent = [];
		foreach ( $allTerms as $term ) {
			if ( ! isset( $term['parent'] ) ) {
				continue;
			}

			$parentId = $term['parent'];
			if ( ! isset( $termsByParent[ $parentId ] ) ) {
				$termsByParent[ $parentId ] = [];
			}
			$termsByParent[ $parentId ][] = $term;
		}

		$this->cacheTerms( $cacheKey, $termsByParent );
		$this->allTermsCache = $termsByParent;

		return $termsByParent;
	}

	/**
	 * Display a formatted list of terms with numbered selection in a table format.
	 *
	 * @param array $terms Array of term objects with 'id', 'name', 'slug', and 'count' keys
	 * @param bool $showCount Whether to display the count column (default: true)
	 * @return array Indexed array of terms (for selection by number)
	 */
	protected function displayTermList( array $terms, bool $showCount = true ): array {
		$indexed = [];

		$maxNumWidth = strlen( (string) count( $terms ) );
		$maxNameWidth = max( array_map( function( $t ) { return strlen( $t['name'] ); }, $terms ) );
		$maxSlugWidth = max( array_map( function( $t ) { return strlen( $t['slug'] ); }, $terms ) );
		$maxIdWidth = max( array_map( function( $t ) { return strlen( (string) $t['id'] ); }, $terms ) );

		$maxNameWidth = max( $maxNameWidth, strlen( 'Name' ) );
		$maxSlugWidth = max( $maxSlugWidth, strlen( 'Slug' ) );
		$maxIdWidth = max( $maxIdWidth, strlen( 'ID' ) );

		if ( $showCount ) {
			$maxCountWidth = max( array_map( function( $t ) { return strlen( (string) $t['count'] ); }, $terms ) );
			$maxCountWidth = max( $maxCountWidth, strlen( 'Count' ) );
		}

		$headerPadding = $maxNumWidth + 7;

		if ( $showCount ) {
			$this->cli->msg( sprintf(
				"\n%s%-{$maxNameWidth}s  %-{$maxSlugWidth}s  %-{$maxIdWidth}s  %-{$maxCountWidth}s\n",
				str_repeat( ' ', $headerPadding ),
				'Name',
				'Slug',
				'ID',
				'Count'
			), '', false );
		} else {
			$this->cli->msg( sprintf(
				"\n%s%-{$maxNameWidth}s  %-{$maxSlugWidth}s  %-{$maxIdWidth}s\n",
				str_repeat( ' ', $headerPadding ),
				'Name',
				'Slug',
				'ID'
			), '', false );
		}

		$firstColWidth = $maxNameWidth + $maxNumWidth + 5;
		if ( $showCount ) {
			$this->cli->msg( sprintf(
				"  %s  %s  %s  %s\n",
				str_repeat( '-', $firstColWidth ),
				str_repeat( '-', $maxSlugWidth ),
				str_repeat( '-', $maxIdWidth ),
				str_repeat( '-', $maxCountWidth )
			), '', false );
		} else {
			$this->cli->msg( sprintf(
				"  %s  %s  %s\n",
				str_repeat( '-', $firstColWidth ),
				str_repeat( '-', $maxSlugWidth ),
				str_repeat( '-', $maxIdWidth )
			), '', false );
		}

		$number = 1;
		foreach ( $terms as $term ) {
			$indexed[] = $term;
			if ( $showCount ) {
				$this->cli->msg( sprintf(
					"  - [%-{$maxNumWidth}d] %-{$maxNameWidth}s  %-{$maxSlugWidth}s  %-{$maxIdWidth}d  %-{$maxCountWidth}d\n",
					$number,
					$term['name'],
					$term['slug'],
					$term['id'],
					$term['count']
				), '', false );
			} else {
				$this->cli->msg( sprintf(
					"  - [%-{$maxNumWidth}d] %-{$maxNameWidth}s  %-{$maxSlugWidth}s  %-{$maxIdWidth}d\n",
					$number,
					$term['name'],
					$term['slug'],
					$term['id']
				), '', false );
			}
			$number++;
		}

		return $indexed;
	}

	/**
	 * Build a hierarchical tree of terms recursively using pre-fetched data.
	 *
	 * @param int $parentId Parent term ID
	 * @param array $termsByParent All terms organized by parent ID
	 * @param int $depth Current depth level for indentation
	 * @param array &$indexed Reference to indexed array for selection
	 * @param int &$counter Reference to counter for numbering
	 * @param string $prefix Prefix for tree characters
	 */
	protected function buildCategoryTree( int $parentId, array $termsByParent, int $depth = 0, array &$indexed = [], int &$counter = 1, string $prefix = '' ): void {
		$terms = $termsByParent[ $parentId ] ?? [];

		if ( empty( $terms ) ) {
			return;
		}

		$totalTerms = count( $terms );
		foreach ( $terms as $index => $term ) {
			$isLastChild = ( $index === $totalTerms - 1 );

			$branch = $isLastChild ? '└─ ' : '├─ ';
			$indent = $prefix . ( $depth > 0 ? $branch : '' );

			$maxNumWidth = strlen( (string) ( count( $indexed ) + 100 ) );

			$this->cli->msg( sprintf(
				"  - [%-{$maxNumWidth}d] %s%s (ID: %d, Count: %d)\n",
				$counter,
				$indent,
				$term['name'],
				$term['id'],
				$term['count']
			), '', false );

			$indexed[] = $term;
			$counter++;

			$childPrefix = $prefix . ( $depth > 0 ? ( $isLastChild ? '   ' : '│  ' ) : '' );

			$this->buildCategoryTree( $term['id'], $termsByParent, $depth + 1, $indexed, $counter, $childPrefix );
		}
	}

	/**
	 * Prompt user to select a category.
	 *
	 * @return array Array of term IDs to attach to the post
	 */
	protected function promptForCategories(): array {
		$this->cli->msg( "\nFetching categories...\n", 'yellow', '' );
		$termsByParent = $this->fetchAllCategoryTerms();

		// Top-level categories (parent = 0)
		$topCategories = $termsByParent[0] ?? [];

		if ( empty( $topCategories ) ) {
			$this->cli->msg( "No categories found.\n", 'red' );
			return [];
		}

		$this->cli->msg( "\nAvailable Categories:\n", 'cyan', false );

		$indexed = [];
		$counter = 1;
		$this->buildCategoryTree( 0, $termsByParent, 0, $indexed, $counter );

		$selection = $this->cli->ask( "\nSelect 1-" . count( $indexed ) . " (or press Enter to skip): " );
		if ( empty( $selection ) ) {
			$this->cli->msg( "\nSkipping category selection.\n", 'yellow' );
			return [];
		}

		$index = (int) $selection - 1;
		if ( ! isset( $indexed[ $index ] ) ) {
			$this->cli->err( "\nInvalid selection\n" );
			return [];
		}

		return [ $indexed[ $index ]['id'] ];
	}
}
