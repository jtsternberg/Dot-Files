<?php

namespace JT\CLI\Publishing;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Wraps `marked` HTML output in Gutenberg block delimiters.
 *
 * Plain HTML posted over REST opens in the editor as one Classic block. Each
 * native block must match what core's save() would produce for it, byte for
 * byte, or the editor flags it as invalid. That is why the class names
 * (`wp-block-heading`, `wp-block-list`, ...) and the newline placement below
 * are exact. Elements with no native mapping fall back to a Custom HTML block,
 * so nothing gets dropped.
 *
 * Ported from the Lindris monorepo's converter of the same name; keep the two
 * in step when core's save() markup changes.
 */
class GutenbergBlockConverter {

	public function convert( string $html ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		// Content that already carries block delimiters was hand-written as
		// blocks; wrapping it again would nest or drop them.
		if ( strpos( $html, '<!-- wp:' ) !== false ) {
			return $html;
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The encoding declaration stops DOMDocument from reading UTF-8 as Latin-1.
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return implode( "\n\n", $this->blocks( $doc->getElementsByTagName( 'body' )->item( 0 ) ) );
	}

	/**
	 * @return string[] One serialized block per top-level child of $parent.
	 */
	private function blocks( DOMNode $parent ): array {
		$blocks = [];
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof DOMComment ) {
				$blocks[] = trim( $node->data ) === 'more'
					? $this->wrap( 'more', '<!--more-->' )
					: $this->wrap( 'html', $node->ownerDocument->saveHTML( $node ) );
				continue;
			}
			if ( ! ( $node instanceof DOMElement ) ) {
				$text = trim( $node->textContent );
				if ( $node->nodeType === XML_TEXT_NODE && $text !== '' ) {
					$blocks[] = $this->wrap( 'paragraph', '<p>' . htmlspecialchars( $text, ENT_NOQUOTES ) . '</p>' );
				}
				continue;
			}
			$blocks[] = $this->block( $node );
		}
		return $blocks;
	}

	private function block( DOMElement $el ): string {
		switch ( $el->nodeName ) {
			case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
				$level = (int) substr( $el->nodeName, 1 );
				// Level 2 is the block's default, and save() omits default attributes.
				$attrs = $level === 2 ? '' : " {\"level\":$level}";
				return $this->wrap( 'heading', "<{$el->nodeName} class=\"wp-block-heading\">" . $this->inner( $el ) . "</{$el->nodeName}>", $attrs );
			case 'p':
				return $this->wrap( 'paragraph', '<p>' . $this->inner( $el ) . '</p>' );
			case 'ul': case 'ol':
				return $this->listBlock( $el );
			case 'blockquote':
				return $this->wrap( 'quote', '<blockquote class="wp-block-quote">' . implode( "\n\n", $this->blocks( $el ) ) . '</blockquote>' );
			case 'hr':
				return $this->wrap( 'separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
			case 'pre':
				return $this->codeBlock( $el );
			case 'table':
				return $this->tableBlock( $el );
			default:
				return $this->wrap( 'html', $el->ownerDocument->saveHTML( $el ) );
		}
	}

	private function listBlock( DOMElement $list ): string {
		$items = '';
		foreach ( $list->childNodes as $li ) {
			if ( ! ( $li instanceof DOMElement ) || $li->nodeName !== 'li' ) {
				continue;
			}
			$content = '';
			$nested = '';
			foreach ( $li->childNodes as $child ) {
				if ( $child instanceof DOMElement && ( $child->nodeName === 'ul' || $child->nodeName === 'ol' ) ) {
					$nested .= $this->listBlock( $child );
				} elseif ( $child instanceof DOMElement && $child->nodeName === 'p' ) {
					$content .= $this->inner( $child );
				} else {
					$content .= $li->ownerDocument->saveHTML( $child );
				}
			}
			$items .= $this->wrap( 'list-item', '<li>' . trim( $content ) . $nested . '</li>' );
		}

		$attrs = $list->nodeName === 'ol' ? ' {"ordered":true}' : '';
		return $this->wrap( 'list', "<{$list->nodeName} class=\"wp-block-list\">$items</{$list->nodeName}>", $attrs );
	}

	private function codeBlock( DOMElement $pre ): string {
		$code = $pre->getElementsByTagName( 'code' )->item( 0 ) ?? $pre;
		return $this->wrap( 'code', '<pre class="wp-block-code"><code>' . rtrim( $this->inner( $code ), "\n" ) . '</code></pre>' );
	}

	/**
	 * Rebuilt rather than copied: marked puts newlines between rows and cells and
	 * uses `align="..."`, while save() emits neither.
	 */
	private function tableBlock( DOMElement $table ): string {
		$sections = '';
		foreach ( $table->childNodes as $section ) {
			if ( ! ( $section instanceof DOMElement ) ) {
				continue;
			}
			$rows = '';
			foreach ( $section->childNodes as $tr ) {
				if ( ! ( $tr instanceof DOMElement ) ) {
					continue;
				}
				$cells = '';
				foreach ( $tr->childNodes as $cell ) {
					if ( ! ( $cell instanceof DOMElement ) ) {
						continue;
					}
					$align = $cell->getAttribute( 'align' );
					$attrs = $align !== '' ? " class=\"has-text-align-$align\" data-align=\"$align\"" : '';
					$cells .= "<{$cell->nodeName}$attrs>" . trim( $this->inner( $cell ) ) . "</{$cell->nodeName}>";
				}
				$rows .= "<tr>$cells</tr>";
			}
			$sections .= "<{$section->nodeName}>$rows</{$section->nodeName}>";
		}

		return $this->wrap( 'table', "<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\">$sections</table></figure>" );
	}

	private function inner( DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	private function wrap( string $name, string $markup, string $attrs = '' ): string {
		return "<!-- wp:$name$attrs -->\n$markup\n<!-- /wp:$name -->";
	}
}
