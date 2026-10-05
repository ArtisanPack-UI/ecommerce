<?php

/**
 * JUnitReportParser.
 *
 * Aggregates a PHPUnit / Pest `--log-junit` file into per-class totals.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use DOMDocument;
use DOMElement;

/**
 * JUnit XML → per-class totals.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class JUnitReportParser
{
    /**
     * Parses JUnit XML. Returns null when the document can't be read.
     *
     * @since 1.0.0
     *
     * @param  string  $xml  JUnit XML document.
     *
     * @return array<string, array{tests: int, assertions: int, failures: int, skipped: int}>|null
     */
    public function parse( string $xml ): ?array
    {
        if ( '' === trim( $xml ) ) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded   = $document->loadXML( $xml, LIBXML_NONET );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return null;
        }

        $classes = [];

        /** @var DOMElement $case */
        foreach ( $document->getElementsByTagName( 'testcase' ) as $case ) {
            $class = $case->getAttribute( 'class' );

            if ( '' === $class ) {
                $class = str_replace( '.', '\\', $case->getAttribute( 'classname' ) );
            }

            if ( '' === $class ) {
                continue;
            }

            $class = ltrim( $class, '\\' );

            $classes[ $class ] ??= [ 'tests' => 0, 'assertions' => 0, 'failures' => 0, 'skipped' => 0 ];

            $classes[ $class ]['tests']++;
            $classes[ $class ]['assertions'] += (int) $case->getAttribute( 'assertions' );

            foreach ( $case->childNodes as $child ) {
                if ( ! $child instanceof DOMElement ) {
                    continue;
                }

                if ( in_array( $child->tagName, [ 'failure', 'error' ], true ) ) {
                    $classes[ $class ]['failures']++;

                    break;
                }

                if ( 'skipped' === $child->tagName ) {
                    $classes[ $class ]['skipped']++;

                    break;
                }
            }
        }

        ksort( $classes );

        return $classes;
    }
}
