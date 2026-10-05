<?php

/**
 * HtmlToText.
 *
 * Turns a rendered notification body into its `text/plain` alternative
 * (audit H3): block elements become line breaks, list items get a dash,
 * table cells are separated, and links keep their target as
 * `text (https://…)`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Mail;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class HtmlToText
{
    /**
     * @since 1.0.0
     *
     * @param  string  $html  HTML.
     *
     * @return string
     */
    public static function convert( string $html ): string
    {
        $text = (string) preg_replace( '#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html );

        $text = (string) preg_replace_callback( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function ( array $match ): string {
            $href  = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $label = trim( html_entity_decode( strip_tags( $match[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

            if ( '' === $label || $label === $href ) {
                return $href;
            }

            return $label . ' (' . $href . ')';
        }, $text );

        $text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
        $text = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
        $text = (string) preg_replace( '#</t[dh]>\s*<t[dh]\b[^>]*>#i', "\t", $text );
        $text = (string) preg_replace( '#</(p|div|tr|h[1-6]|ul|ol|blockquote|table)>#i', "\n\n", $text );
        $text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $lines = array_map( static fn ( string $line ): string => trim( (string) preg_replace( '/[ \x{00A0}]+/u', ' ', $line ) ), explode( "\n", $text ) );

        return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
    }
}
