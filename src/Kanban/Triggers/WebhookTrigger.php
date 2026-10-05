<?php

/**
 * WebhookTrigger.
 *
 * `webhook`: POSTs the order to a URL. Config:
 *
 * - `url`    — Endpoint. `https://` only unless
 *              `artisanpack.ecommerce.webhooks.allow_insecure_urls` is on,
 *              and — like webhook subscriptions — it must resolve to a
 *              public address unless `allow_private_hosts` is on.
 * - `secret` — Optional. When set, the body is signed with the
 *              `X-ArtisanPack-Signature` scheme used by outbound webhooks.
 *
 * Delivery is queued ({@see SendKanbanAutomationWebhookJob}).
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

use ArtisanPackUI\Ecommerce\Jobs\SendKanbanAutomationWebhookJob;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'webhook';

    /**
     * @since 1.0.0
     *
     * @param  WebhookUrlGuard  $urlGuard  SSRF guard.
     */
    public function __construct( private readonly WebhookUrlGuard $urlGuard )
    {
    }

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
        return __( 'Send a webhook' );
    }

    /**
     * Fields this trigger's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'url', 'url', __( 'URL' ), [ 'required' => true ] ),
            ConfigField::make( 'secret', 'text', __( 'Signing secret' ), [ 'rules' => [ 'max:255' ] ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException When the URL is missing, malformed, or not allowed.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $url     = $this->requireString( $config, 'url' );
        $schemes = (bool) config( 'artisanpack.ecommerce.webhooks.allow_insecure_urls', false ) ? [ 'http', 'https' ] : [ 'https' ];

        if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), $schemes, true ) ) {
            throw new InvalidArgumentException( sprintf( 'The "webhook" trigger URL must be a valid %s URL.', implode( ' or ', $schemes ) ) );
        }

        if ( ! $this->urlGuard->allows( $url ) ) {
            throw new InvalidArgumentException( 'The "webhook" trigger URL does not resolve to a public address.' );
        }

        $payloads = new WebhookPayloadFactory( (bool) config( 'artisanpack.ecommerce.webhooks.include_admin_fields', false ) );

        Bus::dispatch( new SendKanbanAutomationWebhookJob( $url, [
            'event'         => 'kanban.automation',
            'automation_id' => (int) $automation->id,
            'board_id'      => (int) $automation->board_id,
            'to_column_id'  => (int) $automation->to_column_id,
            'occurred_at'   => Carbon::now()->format( DATE_ATOM ),
            'order'         => $payloads->serialize( $order ),
        ], (int) $automation->id ) );
    }
}
