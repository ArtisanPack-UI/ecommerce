<?php

/**
 * SettingsController.
 *
 * `admin/settings` (engine issue #145): list the settings groups, read one
 * group's values and secret statuses, and change or reset values through
 * {@see SettingsRepository}. Secrets are reported only as configured or
 * not. Refusals render as 422 `settings-write-failed` problems.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateSettingsRequest;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\SettingDefinition;
use ArtisanPackUI\Ecommerce\Settings\SettingSecret;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SettingsController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  SettingsRegistry    $registry  The allow-list.
     * @param  SettingsRepository  $settings  Stored values.
     */
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Every settings group.
     *
     * @since 1.0.0
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the settings groups' )]
    public function index(): JsonResponse
    {
        return new JsonResponse( [
            'data' => array_values( array_map( static fn ( $group ): array => $group->toArray(), $this->registry->groups() ) ),
        ] );
    }

    /**
     * One group's settings with their current values, and its secrets'
     * configured status.
     *
     * @since 1.0.0
     *
     * @param  string  $group  Group key.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a settings group' )]
    public function show( string $group ): JsonResponse
    {
        abort_unless( $this->registry->hasGroup( $group ), 404 );

        return new JsonResponse( [ 'data' => $this->payload( $group ) ] );
    }

    /**
     * Changes values (`values`) and resets stored values back to config
     * (`reset`) in one group. Changing the base currency also needs
     * `confirm_base_currency_change: true`.
     *
     * @since 1.0.0
     *
     * @param  UpdateSettingsRequest  $request  Validated request.
     * @param  string                 $group    Group key.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a settings group' )]
    public function update( UpdateSettingsRequest $request, string $group ): JsonResponse
    {
        abort_unless( $this->registry->hasGroup( $group ), 404 );

        $changes = $this->settings->update(
            $group,
            (array) $request->validated( 'values', [] ),
            $request->boolean( 'confirm_base_currency_change' ),
            array_map( 'strval', (array) $request->validated( 'reset', [] ) ),
        );

        return new JsonResponse( [ 'data' => $this->payload( $group ), 'meta' => [ 'changed' => array_keys( $changes ) ] ] );
    }

    /**
     * The serialized group.
     *
     * @since 1.0.0
     *
     * @param  string  $group  Group key.
     *
     * @return array<string, mixed>
     */
    protected function payload( string $group ): array
    {
        return [
            ...$this->registry->group( $group )->toArray(),
            'settings' => array_values( array_map( fn ( SettingDefinition $definition ): array => [
                ...$definition->toArray(),
                'value'  => $this->settings->get( $definition->key ),
                'stored' => $this->settings->isStored( $definition->key ),
            ], $this->registry->definitions( $group ) ) ),
            'secrets'  => array_values( array_map( static fn ( SettingSecret $secret ): array => $secret->toArray(), $this->registry->secrets( $group ) ) ),
        ];
    }
}
