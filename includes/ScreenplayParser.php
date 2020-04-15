<?php

class ScreenplayParser {
	/**
	 * @param Parser &$parser
	 */
	public static function init( Parser &$parser ) {
		$parser->setHook( 'screenplay', [ __CLASS__, 'render' ] );
	}

	/**
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function render( $input, array $args, Parser $parser, PPFrame $frame ) {
		// Start by removing all trailing whitespace on each line, as it makes further regex
		// processing unpleasant. Keep leading whitespace, which might sometimes be
		// intentional. The list of characters to remove is taken from trim()'s documentation,
		// without '\n'.
		$input = preg_replace( '/[ \t\r\0\x0B]+$/m', '', $input );

		$newlineMarker = wfRandomString( 16 );

		// When three or more consecutive newlines are encountered, preserve them (converting
		// them to <br>s) later. Multistep processing, ugh…
		$input = preg_replace_callback( '/\n(\n+)\n/', function ( $matches ) use ( $newlineMarker ) {
			$length = strlen( $matches[1] );
			return "\n\n" . $newlineMarker . $length . "\n\n";
		}, $input );

		// Things that would normally be wrapped in <p>s are wrapped in <div>s with various
		// classes. This unfortunately kills newlines, so we'll put extra newlines after all
		// the <div>s after.
		// (at least I think it's this)
		$blocks = explode( "\n\n", trim( $input ) );

		$blocks = array_map( function ( $block ) use ( $parser, $frame, $newlineMarker ) {
			// Newline preservation hack :(
			$matches = [];
			if ( preg_match( '/^' . preg_quote( $newlineMarker ) . '(\d+)$/', $block, $matches ) ) {
				return str_repeat( '<br />', intval( $matches[1] ) );
			}

			// Skip html tags that contain no content
			$doc = new DOMDocument;
			Wikimedia\suppressWarnings();
			$doc->loadHTML( $block );
			Wikimedia\restoreWarnings();
			if ( self::isHtmlTags( $doc->documentElement ) ) {
				return $block;
			}

			// 'shot-heading': a single line where the first four letters are 'INT.' or 'EXT.'
			if ( preg_match( '/^(?:INT[., -]|EXT[., -]).+$/', $block ) ) {
				return Html::rawElement(
					'div',
					[ 'class' => [ 'sp-slug', 'sp-shot-heading' ] ],
					$block
				);
			}

			// 'line': begins all caps (until a single \n) that is not a shot-heading;
			// single linebreaks within these delimit further <div> wrappers as follows:
			// * 'speaker': everything until the first single \n
			// * 'paren': any line wrapped in parentheses that is not a speaker
			// * 'dialogue': any other line within a 'line'

			// Anything but a lowercase letter.
			// http://www.regular-expressions.info/unicode.html
			if ( preg_match( '/^[^\p{Ll}]+?\n/', $block ) ) {
				$lines = explode( "\n", $block );
				$speaker = array_shift( $lines );

				$lines = array_map( function ( $line ) use ( $parser, $frame ) {
					if ( preg_match( '/^\(.+\)$/', $line ) ) {
						return Html::rawElement(
							'div',
							[ 'class' => 'sp-paren' ],
							$line
						);
					} else {
						return Html::rawElement(
							'div',
							[ 'class' => 'sp-dialogue' ],
							$line
						);
					}
				}, $lines );

				return Html::rawElement(
					'div',
					[ 'class' => [
						'sp-line',
						'sp-line-' . Sanitizer::escapeClass( strtolower( $speaker ) )
					] ],
					Html::rawElement(
						'div',
						[ 'class' => 'sp-speaker' ],
						$speaker
					) .
					implode( "\n", $lines )
				);
			}

			// 'slug': anything else
			return Html::rawElement(
				'div',
				[ 'class' => 'sp-slug' ],
				$block
			);
		}, $blocks );

		$parser->getOutput()->addModuleStyles( 'ext.screenplay' );
		$parser->addTrackingCategory( 'screenplay-tracking-category' );

		return $parser->recursiveTagParse(
			Html::rawElement(
				'div',
				[ 'class' => 'screenplay-container' ],
				Html::rawElement(
					'div',
					[ 'class' => 'screenplay' ],
					implode( "\n", $blocks )
				)
			),
			$frame
		);
	}

	/**
	 * Helper function for render to check if block contains html text nodes, or is just tags
	 * @param mixed $element Element to check
	 * @return bool
	 */
	private static function isHtmlTags( $element ) {
		if ( !is_object( $element ) || $element->nodeType == XML_TEXT_NODE ) {
			return false;
		}
		for ( $i = 0; $i < $element->childNodes->length; $i++ ) {
			if ( !self::isHtmlTags( $element->childNodes->item( $i ) ) ) {
				return false;
			}
		}

		return true;
	}
}
