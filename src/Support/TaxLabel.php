<?php

/**
 * TaxLabel.
 *
 * Locale-driven label for the tax line on receipts, checkout summaries, and
 * notifications (parent plan §16.5): "Tax" (en), "IVA" (es), "TVA" (fr),
 * "USt." (de).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

/**
 * Resolves the tax line label for a locale.
 *
 * A store owner's per-locale override in
 * `artisanpack.ecommerce.localization.tax_labels` wins (some US stores
 * prefer "Sales Tax"); otherwise the label comes from the shipped
 * catalogue for that locale.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TaxLabel
{
    /**
     * The tax label for `$locale` (defaults to the application locale).
     *
     * @since 1.0.0
     *
     * @param  string|null  $locale  Locale code.
     *
     * @return string
     */
    public static function for( ?string $locale = null ): string
    {
        $locale    = $locale ?? (string) app()->getLocale();
        $overrides = (array) config( 'artisanpack.ecommerce.localization.tax_labels', [] );

        foreach ( [ $locale, strtok( $locale, '_-' ) ] as $candidate ) {
            $override = $overrides[ $candidate ] ?? null;

            if ( is_string( $override ) && '' !== trim( $override ) ) {
                return $override;
            }
        }

        return __( 'Tax', [], $locale );
    }
}
