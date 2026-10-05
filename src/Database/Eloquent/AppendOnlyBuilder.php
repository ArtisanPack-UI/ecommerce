<?php

/**
 * Custom Eloquent query builder for append-only audit tables.
 *
 * Used by {@see \ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry} and
 * {@see \ArtisanPackUI\Ecommerce\Models\OrderEdit}. Blocks bulk updates and
 * bulk deletes issued through the query builder — mass operations bypass
 * model events, so the model-level `updating` / `deleting` hooks alone are
 * not enough to preserve the audit-trail invariant.
 *
 * Parent-order cascade deletion is enforced at the database FK layer
 * (`ON DELETE CASCADE`) and does not go through Eloquent, so this guard
 * does not interfere with the intended cleanup on order removal.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 *
 * @since 1.0.0
 */
class AppendOnlyBuilder extends Builder
{
    /**
     * Reject any bulk update against an append-only table.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values
     *
     * @return int
     */
    public function update( array $values ): int
    {
        throw new LogicException(
            sprintf(
                '%s rows are append-only and cannot be modified after creation.',
                $this->getModel()->getTable(),
            ),
        );
    }

    /**
     * Reject any bulk delete against an append-only table.
     *
     * @since 1.0.0
     *
     * @return mixed
     */
    public function delete()
    {
        throw new LogicException(
            sprintf(
                '%s rows are append-only and cannot be deleted directly.',
                $this->getModel()->getTable(),
            ),
        );
    }

    /**
     * Rejects increments: they would modify an append-only row.
     *
     * @since 1.0.0
     *
     * @param  mixed  $column  Column.
     * @param  mixed  $amount  Amount.
     * @param  array<string, mixed>  $extra  Extra columns.
     *
     * @throws LogicException Always.
     *
     * @return int
     */
    public function increment( $column, $amount = 1, array $extra = [] )
    {
        return $this->update( $extra );
    }

    /**
     * Rejects decrements: they would modify an append-only row.
     *
     * @since 1.0.0
     *
     * @param  mixed  $column  Column.
     * @param  mixed  $amount  Amount.
     * @param  array<string, mixed>  $extra  Extra columns.
     *
     * @throws LogicException Always.
     *
     * @return int
     */
    public function decrement( $column, $amount = 1, array $extra = [] )
    {
        return $this->update( $extra );
    }

    /**
     * Rejects upserts: they can overwrite an append-only row.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>  $values    Rows.
     * @param  mixed                     $uniqueBy  Unique columns.
     * @param  mixed                     $update    Columns to update.
     *
     * @throws LogicException Always.
     *
     * @return int
     */
    public function upsert( array $values, $uniqueBy, $update = null )
    {
        return $this->update( [] );
    }

    /**
     * Rejects force deletes.
     *
     * @since 1.0.0
     *
     * @throws LogicException Always.
     *
     * @return mixed
     */
    public function forceDelete()
    {
        return $this->delete();
    }
}
