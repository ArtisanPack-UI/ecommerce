<?php

/**
 * SendEmailTrigger.
 *
 * `send-email`: emails a notification when the card moves. Config:
 *
 * - `to`      — `"customer"` (the order email), an address, or a list of
 *               either.
 * - `subject` — Subject line (required).
 * - `body`    — Plain-text body.
 *
 * Subject and body accept `{order_number}`, `{order_id}`, `{email}`,
 * `{status}`, `{board}`, and `{column}` placeholders. The mail is queued.
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

use ArtisanPackUI\Ecommerce\Mail\KanbanAutomationMail;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SendEmailTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'send-email';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Send an email' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException On missing subject or no valid recipient.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $recipients = $this->recipients( $order, $config['to'] ?? null );
        $subject    = $this->interpolate( $this->requireString( $config, 'subject' ), $order, $automation );
        $body       = $this->interpolate( (string) ( $this->optionalString( $config, 'body' ) ?? '' ), $order, $automation );

        Mail::to( $recipients )->queue( new KanbanAutomationMail( $subject, $body ) );
    }

    /**
     * Resolves and validates the `to` config.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     * @param  mixed  $to     Raw `to` config.
     *
     * @throws InvalidArgumentException When no valid address results.
     *
     * @return array<int, string>
     */
    protected function recipients( Order $order, mixed $to ): array
    {
        $addresses = [];

        foreach ( is_array( $to ) ? $to : [ $to ] as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }

            $address = 'customer' === $entry ? (string) $order->email : trim( $entry );

            if ( false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) ) {
                $addresses[] = $address;
            }
        }

        if ( [] === $addresses ) {
            throw new InvalidArgumentException( 'The "send-email" trigger requires at least one valid "to" address.' );
        }

        return array_values( array_unique( $addresses ) );
    }
}
