<?php

/**
 * NotificationPreferenceController.
 *
 * The signed-in shopper's notification preferences (engine spec §9.4,
 * parent plan §14.3): `GET me/notification-preferences` lists every
 * channel × category with its effective value; `PATCH` stores changes.
 * `transactional` is always on (`is_locked`) whatever is stored.
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

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateNotificationPreferencesRequest;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationPreferenceController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationPreferenceService  $preferences  Preference service.
     */
    public function __construct( private readonly NotificationPreferenceService $preferences )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List my notification preferences' )]
    public function show( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): array => $this->preferences->all( $customer ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateNotificationPreferencesRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update my notification preferences' )]
    public function update( UpdateNotificationPreferencesRequest $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): array => $this->preferences->update( $customer, (array) $request->validated( 'preferences' ) ) );
    }

    /**
     * Runs `$callback` for the signed-in shopper's customer record.
     *
     * @since 1.0.0
     *
     * @param  Request                          $request   Request.
     * @param  Closure(Customer): array<mixed>  $callback  Operation.
     *
     * @return JsonResponse
     */
    protected function forCustomer( Request $request, Closure $callback ): JsonResponse
    {
        $user = $request->user();

        if ( null !== $user && ! TokenAbilities::allowsStorefront( $user ) ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => TokenAbilities::STOREFRONT ] ), $request );
        }

        $customer = Customer::forUser( $user );

        if ( null === $customer ) {
            return Problem::make( 404, 'customer-not-found', __( 'Not found' ), __( 'No customer record is linked to this account.' ), $request );
        }

        return new JsonResponse( [ 'data' => $callback( $customer ) ] );
    }
}
