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

        return ! $notifiable instanceof Customer
            || app( NotificationPreferenceService::class )->allows( $notifiable, $channel, $registry->get( $this->templateKey )->category() );
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
        $rendered = $this->render();

        $mail = new NotificationTemplateMail( $this->templateKey, (string) $rendered['subject'], $rendered['body'] );

        return $mail->to( $notifiable->routeNotificationFor( 'mail', $this ) );
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
     *
     * @since 1.0.0
     *
     * @return array{subject: string|null, body: string}
     */
    public function render(): array
    {
        return app( NotificationTemplateService::class )->render( $this->templateKey, $this->channel, $this->variables, $this->locale );
    }
}
