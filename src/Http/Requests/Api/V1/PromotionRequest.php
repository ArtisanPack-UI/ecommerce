<?php

/**
 * PromotionRequest.
 *
 * Payload for `POST admin/promotions` / `PATCH admin/promotions/{promotion}`.
 * `conditions` / `actions`, when present, replace the promotion's
 * existing rows; each `type` must be a registered condition / action.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionSourceRegistry;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $promotion = $this->route( 'promotion' );

        $rules = $this->sometimes( [
            'key'                      => [ 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique( Promotion::class, 'key' )->ignore( $promotion ) ],
            'name'                     => [ 'string', 'max:255' ],
            'description'              => [ 'nullable', 'string' ],
            'source_type'              => [ 'string', Rule::in( app( PromotionSourceRegistry::class )->keys() ) ],
            'is_exclusive'             => [ 'boolean' ],
            'priority'                 => [ 'integer' ],
            'starts_at'                => [ 'nullable', 'date' ],
            'ends_at'                  => array_values( array_filter( [ 'nullable', 'date', $this->filled( 'starts_at' ) ? 'after:starts_at' : null ] ) ),
            'usage_limit_total'        => [ 'nullable', 'integer', 'min:1' ],
            'usage_limit_per_customer' => [ 'nullable', 'integer', 'min:1' ],
            'is_active'                => [ 'boolean' ],
            'conditions'               => [ 'array' ],
            'conditions.*.type'        => [ 'required', Rule::in( app( PromotionConditionRegistry::class )->keys() ) ],
            'conditions.*.config'      => [ 'present', 'array' ],
            'actions'                  => [ 'array' ],
            'actions.*.type'           => [ 'required', Rule::in( app( PromotionActionRegistry::class )->keys() ) ],
            'actions.*.config'         => [ 'present', 'array' ],
        ], [ 'key', 'name', 'source_type' ] );

        foreach ( [ 'conditions' => PromotionConditionRegistry::class, 'actions' => PromotionActionRegistry::class ] as $relation => $registry ) {
            foreach ( (array) $this->input( $relation, [] ) as $index => $row ) {
                if ( is_array( $row ) ) {
                    $rules += $this->configRules( app( $registry ), $row['type'] ?? null, "{$relation}.{$index}.config." );
                }
            }
        }

        return $rules;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex'              => __( 'Promotion keys must be lowercase kebab-case.' ),
            'key.unique'             => __( 'A promotion with this key already exists.' ),
            'source_type.in'         => __( 'Unknown promotion source.' ),
            'conditions.*.type.in'   => __( 'Unknown promotion condition type.' ),
            'actions.*.type.in'      => __( 'Unknown promotion action type.' ),
        ];
    }
}
