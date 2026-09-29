<?php

/**
 * NotificationTemplateMail.
 *
 * The mailable an {@see \ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification}
 * sends on the `mail` channel: a subject and an HTML body already
 * rendered (and escaped) by the sandboxed template renderer.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateMail extends Mailable
{
    /**
     * @since 1.0.0
     *
     * @param  string  $templateKey  Catalog key the mail was rendered from.
     * @param  string  $subjectLine  Rendered subject.
     * @param  string  $htmlBody     Rendered, escaped HTML body.
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly string $subjectLine,
        public readonly string $htmlBody,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope( subject: $this->subjectLine );
    }

    /**
     * @since 1.0.0
     *
     * @return Content
     */
    public function content(): Content
    {
        return new Content( htmlString: $this->htmlBody );
    }
}
