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
                '%s rows are append-only and cannot be deleted directly; delete the owning order to cascade.',
                $this->getModel()->getTable(),
            ),
        );
    }
}
