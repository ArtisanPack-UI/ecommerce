<?php

/**
 * Registration.
 *
 * One implementation a satellite contributes to an engine registry or
 * container binding, as found by {@see RegistrationDiscovery}.
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

/**
 * Immutable discovered-registration value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class Registration
{
    /**
     * @since 1.0.0
     *
     * @param  class-string       $contract  Contract interface the implementation satisfies.
     * @param  string             $key       Registry key (or binding id for container bindings).
     * @param  class-string       $class     Implementation class.
     * @param  class-string|null  $suite     Abstract contract suite, null when none ships.
     * @param  string|null        $error     Why the implementation could not be inspected; a registration with an error always fails.
     */
    public function __construct(
        public readonly string $contract,
        public readonly string $key,
        public readonly string $class,
        public readonly ?string $suite,
        public readonly ?string $error = null,
    ) {
    }
}
