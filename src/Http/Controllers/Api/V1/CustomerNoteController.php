<?php

/**
 * CustomerNoteController.
 *
 * `GET|POST customers/{customer}/notes` and
 * `DELETE customers/{customer}/notes/{note}` (engine issue #147). Writes
 * go through {@see CustomerNoteService}, which also records the
 * `note.added` / `note.deleted` activity entries.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddCustomerNoteRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerNoteResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerNoteController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  CustomerNoteService  $notes  Customer notes.
     */
    public function __construct( private readonly CustomerNoteService $notes )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request   $request   Request.
     * @param  Customer  $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List a customer\'s notes', resource: CustomerNoteResource::class, collection: true )]
    public function index( Request $request, Customer $customer ): JsonResponse
    {
        return $this->listResponse(
            CustomerNote::query()->where( 'customer_id', $customer->id ),
            $request,
            CustomerNoteResource::class,
            [],
            [ 'created_at' => 'created_at' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  AddCustomerNoteRequest  $request   Validated request.
     * @param  Customer                $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add a note to a customer', resource: CustomerNoteResource::class, status: 201 )]
    public function store( AddCustomerNoteRequest $request, Customer $customer ): JsonResponse
    {
        try {
            $note = $this->notes->add( $customer, (string) $request->validated( 'body' ), $this->actorId( $request ) );
        } catch ( InvalidArgumentException $exception ) {
            return Problem::make( 422, 'note-invalid', __( 'Note not added' ), $exception->getMessage(), $request );
        }

        return $this->resourceResponse( $note, $request, CustomerNoteResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request       $request   Request.
     * @param  Customer      $customer  Customer.
     * @param  CustomerNote  $note      Note (scoped to the customer).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a customer note', resource: CustomerNoteResource::class )]
    public function destroy( Request $request, Customer $customer, CustomerNote $note ): JsonResponse
    {
        $this->notes->delete( $note, $this->actorId( $request ) );

        return $this->resourceResponse( $note, $request, CustomerNoteResource::class );
    }

    /**
     * The acting user's id, when numeric.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return int|null
     */
    private function actorId( Request $request ): ?int
    {
        $actor = $request->user()?->getAuthIdentifier();

        return is_numeric( $actor ) ? (int) $actor : null;
    }
}
