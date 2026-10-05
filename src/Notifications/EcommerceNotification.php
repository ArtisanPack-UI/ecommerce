<?php

/**
 * EcommerceNotification.
 *
 * One catalog notification on its way to one recipient. It carries the
 * template key and a pre-built, plain-array render context — never models
 * — and renders the store owner's current copy when it is delivered, so
 * an edit made while the notification sits in the queue still applies.
 *
 * Sent through {@see NotificationDispatcher}; queued like any
 * `ShouldQueue` notification, and encrypted on the queue because the
 * context can carry download links and license keys.
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

use ArtisanPackUI\Ecommerce\Mail\NotificationTemplateMail;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @since 1.0.0
     *
     * @param  string                $templateKey  Catalog key.
     * @param  string                $channel      Delivery channel.
     * @param  array<string, mixed>  $variables    Render context.
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly string $channel,
        public readonly array $variables,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  Recipient.
     *
     * @return array<int, string>
     */
    public function via( mixed $notifiable ): array
    {
        return [ $this->channel ];
    }

    /**
     * Re-checks at delivery time — a queued (or delayed) notification can sit
     * for days — that the template is still on and the customer still wants
     * this category.
     *
     * @since 1.0.0
     *
     * @param  mixed   $notifiable  Recipient.
     * @param  string  $channel     Channel.
     *
     * @return bool
     */
    public function shouldSend( mixed $notifiable, string $channel ): bool
    {
        $registry = app( NotificationTemplateRegistry::class );

        if ( ! app( NotificationTemplateService::class )->isActive( $this->templateKey, $this->locale ) ) {
            return false;
        }

        $preferences = app( NotificationPreferenceService::class );
        $category    = $registry->get( $this->templateKey )->category();

        if ( $notifiable instanceof Customer ) {
            return $preferences->allows( $notifiable, $channel, $category );
        }

        // A guest's address may belong to someone who unsubscribed.
        $email = self::mailAddress( $notifiable, $this );

        return null === $email || $preferences->allowsEmail( $email, $channel, $category );
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  Recipient.
     *
     * @return NotificationTemplateMail
     */
    public function toMail( mixed $notifiable ): NotificationTemplateMail
    {
        $unsubscribe = $this->unsubscribeUrl( $notifiable );
        $rendered    = $this->render( $unsubscribe );

        $mail = new NotificationTemplateMail( $this->templateKey, (string) $rendered['subject'], $rendered['body'], $this->locale ?? app()->getLocale(), $unsubscribe );

        return $mail->to( $notifiable->routeNotificationFor( 'mail', $this ) );
    }

    /**
     * The signed unsubscribe link for `$notifiable`, when this template's
     * category can be turned off and the recipient is one address.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  Recipient.
     *
     * @return string|null
     */
    public function unsubscribeUrl( mixed $notifiable ): ?string
    {
        $email = self::mailAddress( $notifiable, $this );

        return null === $email ? null : app( NotificationPreferenceService::class )->unsubscribeUrl(
            $email,
            $this->channel,
            app( NotificationTemplateRegistry::class )->get( $this->templateKey )->category(),
        );
    }

    /**
     * Payload for the `database` (and broadcast) channels.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  Recipient.
     *
     * @return array{template: string, subject: string|null, body: string}
     */
    public function toArray( mixed $notifiable ): array
    {
        return [ 'template' => $this->templateKey ] + $this->render();
    }

    /**
     * Renders the current copy for this notification's locale.
     * `Store.preferences_url` is the recipient's unsubscribe link when
     * there is one, else `notifications.preferences_url`.
     *
     * @since 1.0.0
     *
     * @param  string|null  $preferencesUrl  The recipient's unsubscribe link.
     *
     * @return array{subject: string|null, body: string}
     */
    public function render( ?string $preferencesUrl = null ): array
    {
        $variables                             = $this->variables;
        $variables['Store']                    = (array) ( $variables['Store'] ?? [] );
        $variables['Store']['preferences_url'] = $preferencesUrl ?? config( 'artisanpack.ecommerce.notifications.preferences_url' );

        return app( NotificationTemplateService::class )->render( $this->templateKey, $this->channel, $variables, $this->locale );
    }

    /**
     * The single mail address `$notifiable` routes to, or null (none, or a
     * list such as the staff addresses).
     *
     * @since 1.0.0
     *
     * @param  mixed         $notifiable    Recipient.
     * @param  Notification  $notification  Notification.
     *
     * @return string|null
     */
    protected static function mailAddress( mixed $notifiable, Notification $notification ): ?string
    {
        if ( ! is_object( $notifiable ) || ! method_exists( $notifiable, 'routeNotificationFor' ) ) {
            return null;
        }

        $route = $notifiable->routeNotificationFor( 'mail', $notification );

        if ( is_array( $route ) ) {
            $route = 1 === count( $route ) ? ( is_string( array_key_first( $route ) ) ? array_key_first( $route ) : reset( $route ) ) : null;
        }

        return is_string( $route ) && '' !== $route ? $route : null;
    }
}
