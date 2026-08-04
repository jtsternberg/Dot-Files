<?php

namespace JT;

/**
 * Single source of truth for the repo root.
 *
 * This file always sits directly in src/, so one dirname() is always correct
 * here — and nowhere else has to hand-count levels from its own location. That
 * counting is what broke SiteCommand::loadEnv(): a pure rename from
 * misc/commands/ to src/CLI/Commands/ (f37c6c9) left its
 * dirname(dirname(__DIR__)) resolving to src/ instead of the repo root, and
 * every jt-blog and hisi command died looking for src/.env. Derive paths from
 * here instead, and the next PSR-4 move can't reintroduce it.
 *
 * JT_DOTFILES_DIR (defined by src/bootstrap.php) wins when present so an entry
 * script's notion of the root stays authoritative; the dirname() fallback keeps
 * this usable when only composer's autoloader has run (e.g. the test suite).
 */
final class Paths {

	/**
	 * Absolute path to the repository root, no trailing slash.
	 *
	 * @return string
	 */
	public static function root(): string {
		return defined( 'JT_DOTFILES_DIR' ) ? JT_DOTFILES_DIR : dirname( __DIR__ );
	}

	/**
	 * Absolute path to a repo-relative file or directory.
	 *
	 * @param string $relative Path relative to the repo root, with or without a leading slash.
	 * @return string
	 */
	public static function path( string $relative = '' ): string {
		$relative = ltrim( $relative, '/' );

		return '' === $relative ? self::root() : self::root() . '/' . $relative;
	}
}
