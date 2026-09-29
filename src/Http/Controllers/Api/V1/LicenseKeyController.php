<?php

/**
 * LicenseKeyController.
 *
 * License keys (engine spec §9.9):
 *
 * - `POST license/validate` — public; the customer's app sends
 *   `{ key, fingerprint }` and gets `{ valid, expires_at, product, revoked }`
 *   (plus a `reason` when invalid). Rate-limited per key and per IP by
 *   `ecommerce.license.validate`; a new fingerprint takes an activation
 *   slot.
 * - `GET admin/license-keys` and `POST admin/license-keys/{key}/revoke` —
 *   admin lookup and revocation.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\RevokeLicenseRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ValidateLicenseRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\LicenseKeyResource;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LicenseKeyController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseService  $licenses  License lifecycle.
     */
    public function __construct( private readonly LicenseService $licenses )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  ValidateLicenseRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Validate a license key', description: 'Returns { valid, expires_at, product, revoked, reason }. A fingerprint the key has not seen before is activated when a slot is free.' )]
    public function validateKey( ValidateLicenseRequest $request ): JsonResponse
    {
        return new JsonResponse( [
            'data' => $this->licenses->validate( (string) $request->validated( 'key' ), (string) $request->validated( 'fingerprint' ), $request->ip() ),
        ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List license keys', resource: LicenseKeyResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            LicenseKey::query(),
            $request,
            LicenseKeyResource::class,
            [
                'key'           => static fn ( Builder $query, string $value ) => $query->where( 'key', LicenseKey::normalize( $value ) ),
                'order_item_id' => [ 'order_item_id', 'int' ],
                'is_revoked'    => [ 'is_revoked', 'bool' ],
            ],
            [ 'created_at' => 'created_at' ],
            [ 'activations' => 'activations' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  RevokeLicenseRequest  $request  Validated request.
     * @param  LicenseKey            $key      Key to revoke.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Revoke a license key', resource: LicenseKeyResource::class )]
    public function revoke( RevokeLicenseRequest $request, LicenseKey $key ): JsonResponse
    {
        return $this->resourceResponse( $this->licenses->revoke( $key, $request->validated( 'reason' ) ), $request, LicenseKeyResource::class );
    }
}
