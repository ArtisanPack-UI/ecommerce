<?php

/**
 * NotificationDispatcher.
 *
 * Sends catalog notifications (parent plan §14): checks that the template
 * is registered and switched on, runs the render context through
 * `ap.ecommerce.notification.templateVariables`, honours the recipient's
 * preferences for the template's category, and hands one
 * {@see EcommerceNotification} per recipient to Laravel's notification
 * system.
 *
 * Recipients are {@see Customer} models (preferences apply), or
 * on-demand routes for guest orders and store staff
 * (`artisanpack.ecommerce.notifications.admin_emails`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Notifications;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use DateTimeInterface;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationDispatcher
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationTemplateRegistry   $registry     Catalog.
     * @param  NotificationTemplateService    $templates    Store copy.
     * @param  NotificationPreferenceService  $preferences  Customer opt-ins.
     * @param  NotificationContext            $context      Context builder.
     */
    public function __construct(
        protected NotificationTemplateRegistry $registry,
        protected NotificationTemplateService $templates,
        protected NotificationPreferenceService $preferences,
        protected NotificationContext $context,
    ) {
    }

    /**
     * Sends `$templateKey` to each recipient.
     *
     * @since 1.0.0
     *
     * @param  string                    $templateKey  Catalog key.
     * @param  iterable<mixed>|mixed     $recipients   Notifiable(s).
     * @param  array<string, mixed>      $variables    Render context (`Store` is added).
     * @param  mixed                     $subject      Domain object the notification is about (for filters).
     * @param  DateTimeInterface|null    $delay        Send no earlier than this.
     * @param  string|null               $locale       Language to send in (an order's `locale`); each
     *                                                 customer's own preference otherwise, else the
     *                                                 app locale at delivery.
     *
     * @return int Number of notifications handed off.
     */
    public function send( string $templateKey, mixed $recipients, array $variables, mixed $subject = null, ?DateTimeInterface $delay = null, ?string $locale = null ): int
    {
        if ( ! (bool) config( 'artisanpack.ecommerce.notifications.enabled', true ) ) {
            return 0;
        }

        if ( ! $this->registry->has( $templateKey ) ) {
            Log::channel( 'ecommerce' )->warning( 'ecommerce.notification.unknown_template', [ 'template' => $templateKey ] );

            return 0;
        }

        if ( ! $this->templates->isActive( $templateKey ) ) {
            return 0;
        }

        $definition = $this->registry->get( $templateKey );
        $variables  = (array) applyFilters(
            'ap.ecommerce.notification.templateVariables',
            [ 'Store' => $this->context->store() ] + $variables,
            $templateKey,
            $subject,
        );

        $sent = 0;

        foreach ( is_iterable( $recipients ) ? $recipients : [ $recipients ] as $recipient ) {
            if ( $recipient instanceof Customer && ! $this->preferences->allows( $recipient, $definition->channel(), $definition->category() ) ) {
                continue;
            }

            // Queued only once the change it reports has committed.
            $notification = ( new EcommerceNotification( $templateKey, $definition->channel(), $variables ) )->afterCommit();

            if ( null !== $delay ) {
                $notification->delay( $delay );
            }

            $language = '' !== (string) $locale ? $locale : ( $recipient instanceof HasLocalePreference ? $recipient->preferredLocale() : null );

            if ( null !== $language && '' !== $language ) {
                $notification->locale( $language );
            }

            Notification::send( $recipient, $notification );
            $sent++;
        }

        return $sent;
    }

    /**
     * Who hears about `$order`: its customer record, else an on-demand
     * route to the order's email. Empty when there's no address.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<int, AnonymousNotifiable|Customer>
     */
    public function orderRecipients( Order $order ): array
    {
        if ( null !== $order->customer && '' !== (string) $order->customer->email ) {
            return [ $order->customer ];
        }

        return '' === (string) $order->email ? [] : [ Notification::route( 'mail', $order->email ) ];
    }

    /**
     * The language staff notifications are sent in: the store's
     * notification default, whatever language triggered them.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function adminLocale(): string
    {
        return $this->templates->defaultLocale();
    }

    /**
     * Store staff, from `artisanpack.ecommerce.notifications.admin_emails`.
     *
     * @since 1.0.0
     *
     * @return array<int, AnonymousNotifiable>
     */
    public function adminRecipients(): array
    {
        $emails = array_values( array_filter( (array) config( 'artisanpack.ecommerce.notifications.admin_emails', [] ), 'is_string' ) );

        return [] === $emails ? [] : [ Notification::route( 'mail', $emails ) ];
    }
}
