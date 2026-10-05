<?php

/**
 * NotificationTemplateMail.
 *
 * The mail a catalog notification renders to. The rendered body is wrapped
 * in a minimal document carrying the recipient's language and text
 * direction, and sent with a `text/plain` alternative (audit H3). Mail in
 * a category the recipient can opt out of carries one-click unsubscribe
 * headers (RFC 8058) and a footer link.
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
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateMail extends Mailable
{
    /**
     * Languages written right to left.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const RTL_LANGUAGES = [ 'ar', 'dv', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi' ];

    /**
     * @since 1.0.0
     *
     * @param  string       $templateKey     Catalog key the mail was rendered from.
     * @param  string       $subjectLine     Rendered subject.
     * @param  string       $htmlBody        Rendered, escaped HTML body.
     * @param  string|null  $language        Recipient's locale (null: the app locale).
     * @param  string|null  $unsubscribeUrl  Signed one-click unsubscribe URL, for opt-out categories.
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly ?string $language = null,
        public readonly ?string $unsubscribeUrl = null,
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
        // Laravel's Content only takes a view for the text part; an
        // Htmlable text view is rendered as is.
        $this->textView = new HtmlString( $this->textBody() );

        return new Content( htmlString: $this->document() );
    }

    /**
     * One-click unsubscribe headers for opt-out categories.
     *
     * @since 1.0.0
     *
     * @return Headers
     */
    public function headers(): Headers
    {
        return new Headers( text: null === $this->unsubscribeUrl ? [] : [
            'List-Unsubscribe'      => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] );
    }

    /**
     * The full HTML document.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function document(): string
    {
        $locale   = $this->resolvedLocale();
        $language = strtolower( (string) preg_split( '/[_-]/', $locale )[0] );
        $footer   = null === $this->unsubscribeUrl ? '' : sprintf(
            '<p style="font-size:12px;color:#6b7280;margin-top:32px;"><a href="%s">%s</a></p>',
            e( $this->unsubscribeUrl ),
            e( __( 'Unsubscribe from these emails', [], $locale ) ),
        );

        return sprintf(
            '<!DOCTYPE html><html lang="%s" dir="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%s</title></head><body>%s%s</body></html>',
            e( str_replace( '_', '-', $locale ) ),
            in_array( $language, self::RTL_LANGUAGES, true ) ? 'rtl' : 'ltr',
            e( $this->subjectLine ),
            $this->htmlBody,
            $footer,
        );
    }

    /**
     * The `text/plain` alternative.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function textBody(): string
    {
        $text = HtmlToText::convert( $this->htmlBody );

        return null === $this->unsubscribeUrl
            ? $text
            : $text . "\n\n" . __( 'Unsubscribe from these emails', [], $this->resolvedLocale() ) . ': ' . $this->unsubscribeUrl;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    protected function resolvedLocale(): string
    {
        return '' !== (string) $this->language ? (string) $this->language : app()->getLocale();
    }
}
