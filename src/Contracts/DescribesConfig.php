<?php

/**
 * DescribesConfig contract.
 *
 * Optional companion to the configurable registry contracts
 * ({@see PromotionCondition}, {@see PromotionAction},
 * {@see ShippingMethodType}, {@see KanbanAutomationTrigger},
 * {@see KanbanCardWidget}). An entry that implements it declares the fields
 * its `config` JSON takes, so an admin — Livewire, React, or Vue — can
 * render a form for it without hard-coding the entry, and the engine can
 * validate writes against the same declaration (engine issue #149).
 *
 * Entries that don't implement it keep working; admins fall back to a raw
 * JSON editor and writes are not checked beyond "is an object".
 *
 * Each field is a framework-neutral array:
 *
 * ```php
 * [
 *     'name'     => 'amount',            // config key, snake_case
 *     'type'     => 'money',             // see ConfigSchema::TYPES
 *     'label'    => __( 'Minimum subtotal' ),
 *     'required' => true,                // optional, default false
 *     'rules'    => [ 'min:0' ],         // optional extra Laravel-style rule strings
 *     'help'     => __( '…' ),           // optional
 *     'default'  => 0,                   // optional
 *     'multiple' => true,                // optional: product, variant, category, tag, weekday
 *     'options'  => [ [ 'value' => 'any', 'label' => __( 'Any' ) ] ], // select, multiselect
 *     'fields'   => [ … ],               // repeater: the fields of each row
 *     'tokens'   => [ '{order_number}' ], // template: placeholders it expands
 * ]
 * ```
 *
 * Build fields with {@see \ArtisanPackUI\Ecommerce\Support\ConfigField} and
 * check a schema with {@see \ArtisanPackUI\Ecommerce\Support\ConfigSchema::problems()};
 * the type vocabulary is documented in `docs/contracts.md`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface DescribesConfig
{
    /**
     * The fields this entry's `config` takes, in display order. Return an
     * empty array when the entry takes no configuration.
     *
     * Labels are translated when this is called, so call it per request
     * rather than caching the result across locales.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array;
}
