<?php
/**
 * Runtime bootstrap for JT CLI scripts — no composer install required.
 *
 * Registers a PSR-4 autoloader for the JT\ namespace rooted at src/, loads
 * composer's autoloader too when vendor/ exists (some scripts use vendor
 * packages), defines JT_DOTFILES_DIR + getCli(), and returns the CLI
 * Helpers instance so entry scripts keep the one-line idiom:
 *
 *   $cli = require_once dirname(__DIR__) . '/src/bootstrap.php';
 */

if ( ! defined( 'JT_DOTFILES_DIR' ) ) {
	define( 'JT_DOTFILES_DIR', dirname( __DIR__ ) );
}

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'JT\\' ) !== 0 ) {
		return;
	}
	$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, 3 ) ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

$vendorAutoload = JT_DOTFILES_DIR . '/vendor/autoload.php';
if ( is_file( $vendorAutoload ) ) {
	require_once $vendorAutoload;
}

if ( ! function_exists( 'getCli' ) ) {
	function getCli( array $args = [] ) {
		return \JT\CLI\Helpers::getInstance()->setArgs( $args );
	}
}

return getCli( isset( $argv ) ? $argv : [] );
