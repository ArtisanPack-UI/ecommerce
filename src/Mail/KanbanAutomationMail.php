<?php

/**
 * KanbanAutomationMail.
 *
 * The message sent by the `send-email` kanban automation trigger. Subject
 * and body are admin-authored plain text; the body is HTML-escaped and
 * line breaks are preserved.
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

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAutomationMail extends Mailable
{
    use Queueable;

    /**
     * @since 1.0.0
     *
     * @param  string  $subjectLine  Rendered subject.
     * @param  string  $bodyText     Rendered plain-text body.
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
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
        return new Content( htmlString: nl2br( e( $this->bodyText ) ) );
    }
}
