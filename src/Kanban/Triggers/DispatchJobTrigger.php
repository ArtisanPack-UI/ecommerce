<?php

/**
 * DispatchJobTrigger.
 *
 * `dispatch-job`: queues a Laravel job with the order id. Config:
 *
 * - `job` — Job class name. It must be listed in
 *           `artisanpack.ecommerce.kanban.dispatchable_jobs`; automations
 *           are admin data, so the allow-list keeps them from instantiating
 *           arbitrary classes.
 *
 * The job is constructed as `new $job( int $orderId )`.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DispatchJobTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'dispatch-job';

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
        return __( 'Dispatch a job' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException When the job is not allow-listed or does not exist.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $job     = $this->requireString( $config, 'job' );
        $allowed = array_filter( (array) config( 'artisanpack.ecommerce.kanban.dispatchable_jobs', [] ), 'is_string' );

        if ( ! in_array( $job, $allowed, true ) || ! class_exists( $job ) ) {
            throw new InvalidArgumentException( sprintf( 'Job "%s" is not in artisanpack.ecommerce.kanban.dispatchable_jobs.', $job ) );
        }

        Bus::dispatch( new $job( (int) $order->id ) );
    }
}
