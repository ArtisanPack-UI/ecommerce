<?php

/**
 * NotificationUnsubscribeController.
 *
 * Signed unsubscribe links for opt-out notification categories (audit H3):
 * `GET ecommerce/notifications/unsubscribe` shows a confirmation page (a
 * link scanner fetching it changes nothing); `POST` to the same signed URL
 * turns the category off — also what mail clients send for RFC 8058
 * one-click unsubscribe (`List-Unsubscribe-Post`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers;

use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationUnsubscribeController
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationPreferenceService  $preferences  Preferences.
     */
    public function __construct( private readonly NotificationPreferenceService $preferences )
    {
    }

    /**
     * The confirmation page.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Signed request.
     *
     * @return Response
     */
    public function show( Request $request ): Response
    {
        $form = sprintf(
            '<form method="post" action="%s"><button type="submit">%s</button></form>',
            e( $request->fullUrl() ),
            e( __( 'Unsubscribe' ) ),
        );

        return $this->page( __( 'Unsubscribe' ), __( 'Stop sending these emails to :email?', [ 'email' => (string) $request->query( 'email' ) ] ), $form );
    }

    /**
     * Turns the category off.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Signed request.
     *
     * @return Response
     */
    public function store( Request $request ): Response
    {
        $this->preferences->unsubscribe( (string) $request->query( 'email' ), (string) $request->query( 'channel', 'mail' ), (string) $request->query( 'category' ) );

        return $this->page( __( 'Unsubscribed' ), __( 'You will no longer receive these emails at :email.', [ 'email' => (string) $request->query( 'email' ) ] ) );
    }

    /**
     * A minimal HTML page.
     *
     * @since 1.0.0
     *
     * @param  string  $title    Title.
     * @param  string  $message  Message.
     * @param  string  $extra    Extra (trusted) markup.
     *
     * @return Response
     */
    protected function page( string $title, string $message, string $extra = '' ): Response
    {
        $html = sprintf(
            '<!DOCTYPE html><html lang="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>%s</title></head><body><main><h1>%s</h1><p>%s</p>%s</main></body></html>',
            e( str_replace( '_', '-', app()->getLocale() ) ),
            e( $title ),
            e( $title ),
            e( $message ),
            $extra,
        );

        return new Response( $html, 200, [ 'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store' ] );
    }
}
