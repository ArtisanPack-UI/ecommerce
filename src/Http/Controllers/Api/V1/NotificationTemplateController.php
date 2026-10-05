<?php

/**
 * NotificationTemplateController.
 *
 * The notification-template editor API (engine spec §9.11, parent plan
 * §14.2). Listing seeds a default-locale row for any catalog entry that
 * lacks one. Each template carries its declared `variables` for editor
 * autocomplete. `PATCH` validates Twig sources through the sandbox (and
 * against the declared variables) before saving; `POST …/preview` renders
 * saved or unsaved sources against sample data through the exact renderer
 * used at delivery time. Invalid sources come back as 422 `problem+json`
 * with one error per problem.
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

use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\PreviewNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\NotificationTemplateResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationTemplateService  $templates  Template service.
     */
    public function __construct( private readonly NotificationTemplateService $templates )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List notification templates', resource: NotificationTemplateResource::class, collection: true, filters: [ 'key' => 'string', 'channel' => 'string', 'locale' => 'string', 'is_active' => 'boolean' ], sorts: [ 'key' ] )]
    public function index( Request $request ): JsonResponse
    {
        $this->templates->sync();

        return $this->listResponse(
            NotificationTemplate::query(),
            $request,
            NotificationTemplateResource::class,
            [ 'key' => 'key', 'channel' => 'channel', 'locale' => 'locale', 'is_active' => [ 'is_active', 'bool' ] ],
            [ 'key' => 'key' ],
            [],
            'key',
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request               $request   Request.
     * @param  NotificationTemplate  $template  Template.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a notification template', resource: NotificationTemplateResource::class )]
    public function show( Request $request, NotificationTemplate $template ): JsonResponse
    {
        return $this->resourceResponse( $template, $request, NotificationTemplateResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateNotificationTemplateRequest  $request   Validated request.
     * @param  NotificationTemplate               $template  Template.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a notification template', resource: NotificationTemplateResource::class )]
    public function update( UpdateNotificationTemplateRequest $request, NotificationTemplate $template ): JsonResponse
    {
        try {
            $this->templates->update( $template, $request->validated() );
        } catch ( NotificationTemplateException $e ) {
            return $this->invalid( $request, $e );
        }

        return $this->resourceResponse( $template, $request, NotificationTemplateResource::class );
    }

    /**
     * Renders a live preview.
     *
     * @since 1.0.0
     *
     * @param  PreviewNotificationTemplateRequest  $request   Validated request.
     * @param  NotificationTemplate                $template  Template.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Preview a notification template', description: 'Renders the saved (or supplied, unsaved) subject and body against sample data through the delivery-time sandbox. Returns { subject, body }.' )]
    public function preview( PreviewNotificationTemplateRequest $request, NotificationTemplate $template ): JsonResponse
    {
        $input = $request->validated();

        try {
            $rendered = $this->templates->preview(
                $template,
                array_key_exists( 'subject', $input ) ? $input['subject'] : null,
                $input['body'] ?? null,
                isset( $input['preview_data'] ) ? (array) $input['preview_data'] : null,
            );
        } catch ( NotificationTemplateException $e ) {
            return $this->invalid( $request, $e );
        }

        return new JsonResponse( [ 'data' => $rendered ] );
    }

    /**
     * A 422 listing every template problem.
     *
     * @since 1.0.0
     *
     * @param  Request                        $request  Request.
     * @param  NotificationTemplateException  $e        Failure.
     *
     * @return JsonResponse
     */
    protected function invalid( Request $request, NotificationTemplateException $e ): JsonResponse
    {
        return Problem::make( 422, 'invalid-notification-template', __( 'Invalid notification template' ), $e->getMessage(), $request, $e->errors );
    }
}
