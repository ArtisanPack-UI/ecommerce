<?php

/**
 * RecordModelActivity.
 *
 * Eloquent observer that writes activity-log entries for product, variant,
 * price, customer, promotion, and coupon writes. Those writes have no engine
 * service yet (admins and satellites save the models directly), so the
 * observer is the one place every write passes through. Each entry is filed
 * against the owning top-level entity through {@see ActivityLogService}.
 *
 * Update entries carry a diff-style payload
 * (`{ "changes": { "field": { "before": x, "after": y } } }`). Timestamps
 * and counters the engine maintains itself (ratings, order totals, coupon
 * usage) are left out, and an update that only touched those is not
 * recorded at all.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RecordModelActivity
{
    /**
     * Models observed.
     *
     * @since 1.0.0
     *
     * @var array<int, class-string<Model>>
     */
    public const MODELS = [
        Product::class,
        ProductVariant::class,
        ProductPrice::class,
        Customer::class,
        Promotion::class,
        Coupon::class,
    ];

    /**
     * Columns never reported in an update diff, per model. `*` applies to all.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    protected const IGNORED = [
        '*'                   => [ 'created_at', 'updated_at' ],
        Product::class        => [ 'avg_rating', 'reviews_count' ],
        Customer::class       => [ 'total_spent_amount', 'total_spent_currency', 'orders_count', 'last_ordered_at' ],
        Promotion::class      => [ 'times_used' ],
        ProductPrice::class   => [ 'cost_amount' ],
    ];

    /**
     * @since 1.0.0
     *
     * @param  ActivityLogService  $activity  Activity log.
     */
    public function __construct( protected ActivityLogService $activity )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Model  $model  Created model.
     *
     * @return void
     */
    public function created( Model $model ): void
    {
        $this->write( $model, 'created', $this->summary( $model ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Model  $model  Updated model.
     *
     * @return void
     */
    public function updated( Model $model ): void
    {
        $changes = $this->changes( $model );

        if ( [] === $changes ) {
            return;
        }

        $this->write( $model, 'updated', $this->reference( $model ) + [ 'changes' => $changes ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Model  $model  Deleted model.
     *
     * @return void
     */
    public function deleted( Model $model ): void
    {
        $this->write( $model, 'deleted', $this->summary( $model ) );
    }

    /**
     * Resolves the subject and event prefix, then records.
     *
     * @since 1.0.0
     *
     * @param  Model                 $model    Changed model.
     * @param  string                $action   created|updated|deleted.
     * @param  array<string, mixed>  $payload  Payload.
     *
     * @return void
     */
    protected function write( Model $model, string $action, array $payload ): void
    {
        if ( ! $this->activity->enabled() ) {
            return;
        }

        [ $subject, $prefix ] = match ( true ) {
            $model instanceof Product        => [ $model, 'product' ],
            $model instanceof ProductVariant => [ Product::query()->find( $model->product_id ), 'variant' ],
            $model instanceof ProductPrice   => [ $this->priceProduct( $model ), 'price' ],
            $model instanceof Customer       => [ $model, 'customer' ],
            $model instanceof Promotion      => [ $model, 'promotion' ],
            $model instanceof Coupon         => [ Promotion::query()->find( $model->promotion_id ), 'coupon' ],
            default                          => [ null, '' ],
        };

        if ( ! $subject instanceof Model ) {
            return;
        }

        // An audit entry must never block the business write it describes.
        try {
            $this->activity->record( $subject, $prefix . '.' . $action, $payload );
        } catch ( Throwable $exception ) {
            report( $exception );
        }
    }

    /**
     * Identifying fields for an entry about a child row.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  Model.
     *
     * @return array<string, mixed>
     */
    protected function reference( Model $model ): array
    {
        return match ( true ) {
            $model instanceof ProductVariant => [ 'variant_id' => (int) $model->id, 'sku' => $model->sku ],
            $model instanceof ProductPrice   => [
                'price_id'   => (int) $model->id,
                'variant_id' => $this->isVariantPrice( $model ) ? (int) $model->priceable_id : null,
                'currency'   => $model->currency,
            ],
            $model instanceof Coupon         => [ 'coupon_id' => (int) $model->id, 'code' => $model->code ],
            default                          => [],
        };
    }

    /**
     * Payload for a created / deleted entry.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  Model.
     *
     * @return array<string, mixed>
     */
    protected function summary( Model $model ): array
    {
        return $this->reference( $model ) + match ( true ) {
            $model instanceof Product        => [ 'name' => $model->name, 'sku' => $model->sku, 'type' => $model->type, 'status' => $model->status ],
            $model instanceof ProductVariant => [ 'name' => $model->name ],
            $model instanceof ProductPrice   => [ 'amount' => (int) $model->price_amount, 'compare_at_amount' => null === $model->compare_at_amount ? null : (int) $model->compare_at_amount ],
            $model instanceof Customer       => [ 'email' => $model->email, 'first_name' => $model->first_name, 'last_name' => $model->last_name ],
            $model instanceof Promotion      => [ 'name' => $model->name, 'key' => $model->key ],
            default                          => [],
        };
    }

    /**
     * The reportable `{field: {before, after}}` changes of an update.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  Updated model.
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    protected function changes( Model $model ): array
    {
        $ignored = array_merge( self::IGNORED['*'], self::IGNORED[ $model::class ] ?? [] );
        $changes = [];

        foreach ( array_keys( $model->getChanges() ) as $field ) {
            if ( in_array( $field, $ignored, true ) ) {
                continue;
            }

            $before = $this->normalize( $model->getOriginal( $field ) );
            $after  = $this->normalize( $model->getAttribute( $field ) );

            if ( $before === $after ) {
                continue;
            }

            $changes[ $field ] = [ 'before' => $before, 'after' => $after ];
        }

        return $changes;
    }

    /**
     * Makes a value JSON-safe.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Value.
     *
     * @return mixed
     */
    protected function normalize( mixed $value ): mixed
    {
        return match ( true ) {
            $value instanceof DateTimeInterface => $value->format( DateTimeInterface::ATOM ),
            $value instanceof BackedEnum        => $value->value,
            is_object( $value )                 => method_exists( $value, '__toString' ) ? (string) $value : null,
            default                             => $value,
        };
    }

    /**
     * The product a price belongs to (directly or through a variant).
     *
     * @since 1.0.0
     *
     * @param  ProductPrice  $price  Price.
     *
     * @return Product|null
     */
    protected function priceProduct( ProductPrice $price ): ?Product
    {
        if ( $this->isVariantPrice( $price ) ) {
            return ProductVariant::query()->find( $price->priceable_id )?->product;
        }

        if ( $this->matchesMorph( $price->priceable_type, Product::class ) ) {
            return Product::query()->find( $price->priceable_id );
        }

        return null;
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductPrice  $price  Price.
     *
     * @return bool
     */
    protected function isVariantPrice( ProductPrice $price ): bool
    {
        return $this->matchesMorph( $price->priceable_type, ProductVariant::class );
    }

    /**
     * Whether a stored morph type names `$class`.
     *
     * @since 1.0.0
     *
     * @param  string|null          $type   Stored morph type.
     * @param  class-string<Model>  $class  Model class.
     *
     * @return bool
     */
    protected function matchesMorph( ?string $type, string $class ): bool
    {
        return $class === $type || ( new $class() )->getMorphClass() === $type;
    }
}
