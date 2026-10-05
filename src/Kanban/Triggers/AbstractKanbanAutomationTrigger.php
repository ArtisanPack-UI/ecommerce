<?php

/**
 * AbstractKanbanAutomationTrigger.
 *
 * Shared helpers for the built-in kanban automation triggers: config
 * accessors that throw {@see InvalidArgumentException} on bad input (the
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger} contract)
 * and `{placeholder}` interpolation for user-authored text.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban\Triggers;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractKanbanAutomationTrigger implements KanbanAutomationTrigger, DescribesConfig
{
    /**
     * Placeholders {@see self::interpolate()} expands in template fields.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TOKENS = [ '{order_number}', '{order_id}', '{email}', '{status}', '{board}', '{column}' ];

    /**
     * A required, non-empty string config value.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $config  Trigger config.
     * @param  string                $key     Config key.
     *
     * @throws InvalidArgumentException When missing or not a non-empty string.
     *
     * @return string
     */
    protected function requireString( array $config, string $key ): string
    {
        $value = $config[ $key ] ?? null;

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            throw new InvalidArgumentException( sprintf( 'The "%s" trigger requires a "%s" string.', $this->key(), $key ) );
        }

        return trim( $value );
    }

    /**
     * An optional string config value.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $config  Trigger config.
     * @param  string                $key     Config key.
     *
     * @return string|null
     */
    protected function optionalString( array $config, string $key ): ?string
    {
        $value = $config[ $key ] ?? null;

        return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
    }

    /**
     * Replaces `{order_number}`, `{order_id}`, `{email}`, `{status}`,
     * `{board}`, and `{column}` in `$text`.
     *
     * @since 1.0.0
     *
     * @param  string            $text        Template text.
     * @param  Order             $order       Order.
     * @param  KanbanAutomation  $automation  Automation being fired.
     *
     * @return string
     */
    protected function interpolate( string $text, Order $order, KanbanAutomation $automation ): string
    {
        return strtr( $text, [
            '{order_number}' => (string) $order->order_number,
            '{order_id}'     => (string) $order->id,
            '{email}'        => (string) $order->email,
            '{status}'       => (string) $order->system_status,
            '{board}'        => (string) $automation->board?->name,
            '{column}'       => (string) $automation->toColumn?->displayLabel(),
        ] );
    }
}
