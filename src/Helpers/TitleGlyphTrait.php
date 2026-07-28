<?php
namespace JT\Helpers;

/**
 * The one implementation of "read this cmux title the way a human does".
 *
 * Shared by JT\Helpers\Cmux and JT\Graveyard rather than living in either: the
 * graveyard's served page is rendered by bin/graveyard_router.php with
 * `new Graveyard($cli, null)` — no cmux instance exists on that path — so
 * Graveyard cannot call the method off Cmux, and every stone title needs it.
 */
trait TitleGlyphTrait {

	/**
	 * PURE. Strip Claude's/cmux's leading status glyph (e.g. ✳, ⠂) from a workspace
	 * or tab title, so a title printed for a human to find carries no live-status
	 * noise. Only a leading non-ASCII run goes, which leaves ASCII-leading titles
	 * intact — unlike Cmux::normalizeTitle(), whose comparison-oriented strip would
	 * eat the "~/" of "~/Sites/x".
	 */
	public function stripGlyph(string $title): string {
		return trim((string) preg_replace('/^[^\x00-\x7F]+\s*/u', '', trim($title)));
	}
}
