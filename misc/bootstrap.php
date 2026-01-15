<?php

/**
 * Bootstrap helper for JT CLI scripts.
 * Autoloaded via composer.json "files" autoload.
 */

if ( ! defined( 'JT_DOTFILES_DIR' ) ) {
	define( 'JT_DOTFILES_DIR', dirname( __DIR__ ) );
}

if ( ! function_exists( 'getCli' ) ) {
	/**
	 * Get the JT CLI Helpers instance.
	 *
	 * @param array $argv The arguments to pass to the CLI
	 * @return \JT\CLI\Helpers The CLI instance
	 */
	function getCli( $argv = [] ) {
		return require JT_DOTFILES_DIR . '/misc/helpers.php';
	}
}
