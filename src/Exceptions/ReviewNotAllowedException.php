<?php

/**
 * ReviewNotAllowedException.
 *
 * A review submission its author isn't eligible for (#181); `reason` is a
 * {@see \ArtisanPackUI\Ecommerce\Reviews\ReviewEligibility} reason code.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

use ArtisanPackUI\Ecommerce\Reviews\ReviewEligibility;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReviewNotAllowedException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $reason  Reason code.
     */
    public function __construct( public readonly string $reason )
    {
        parent::__construct( match ( $reason ) {
            ReviewEligibility::GUESTS_NOT_ALLOWED => __( 'Sign in to review this product.' ),
            ReviewEligibility::ALREADY_REVIEWED   => __( 'You have already reviewed this product.' ),
            ReviewEligibility::PURCHASE_REQUIRED  => __( 'Only customers who bought this product can review it.' ),
            default                               => __( 'You can\'t review this product.' ),
        } );
    }
}
