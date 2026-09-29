<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

const TEMPLATES_API = '/api/ecommerce/v1/admin/notification-templates';

function confirmationTemplate(): NotificationTemplate
{
    app( NotificationTemplateService::class )->sync();

    return NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_CONFIRMATION )->sole();
}

it( 'requires the notificationTemplate abilities', function (): void {
    $this->getJson( TEMPLATES_API )->assertUnauthorized();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( TEMPLATES_API )->assertForbidden();

    Gate::define( 'ecommerce.notificationTemplate.viewAny', fn (): bool => true );
    $template = confirmationTemplate();

    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( TEMPLATES_API )->assertOk();

    // Rendering arbitrary sources is an authoring action: preview needs update.
    Gate::define( 'ecommerce.notificationTemplate.view', fn (): bool => true );
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( TEMPLATES_API . "/{$template->id}/preview", [ 'body' => 'x' ] )
        ->assertForbidden();
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->patchJson( TEMPLATES_API . "/{$template->id}", [ 'subject' => 'x' ], idem() )
        ->assertForbidden();
} );

it( 'lists the catalog with labels, categories, and variables for autocomplete', function (): void {
    $response = $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( TEMPLATES_API . '?per_page=50' )->assertOk();

    $confirmation = collect( $response->json( 'data' ) )->firstWhere( 'key', NotificationCatalog::ORDER_CONFIRMATION );

    expect( $response->json( 'data' ) )->toHaveCount( count( NotificationCatalog::definitions() ) )
        ->and( $confirmation['type'] )->toBe( 'notificationTemplate' )
        ->and( $confirmation['label'] )->toBe( 'Order confirmation' )
        ->and( $confirmation['category'] )->toBe( 'transactional' )
        ->and( $confirmation['channel'] )->toBe( 'mail' )
        ->and( $confirmation['variables'] )->toContain( 'Order.number', 'Order.customer.name', 'Order.items.*.name' )
        ->and( $confirmation['preview_data']['Order']['number'] )->toBe( 'K7QM2XW9' );

    $this->getJson( TEMPLATES_API . '/' . $confirmation['id'] )->assertOk()->assertJsonPath( 'data.key', NotificationCatalog::ORDER_CONFIRMATION );
} );

it( 'saves valid edits', function (): void {
    $template = confirmationTemplate();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( TEMPLATES_API . "/{$template->id}", [ 'subject' => 'Order {{ Order.number }}' ] )->assertStatus( 400 );

    $this->patchJson( TEMPLATES_API . "/{$template->id}", [
        'subject'   => 'Order {{ Order.number }} confirmed',
        'is_active' => false,
    ], idem() )->assertOk()
        ->assertJsonPath( 'data.subject', 'Order {{ Order.number }} confirmed' )
        ->assertJsonPath( 'data.is_active', false );
} );

it( 'rejects templates that escape the sandbox or use undeclared variables', function ( string $body, string $code ): void {
    $template = confirmationTemplate();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( TEMPLATES_API . "/{$template->id}", [ 'body' => $body ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/invalid-notification-template' )
        ->assertJsonPath( 'errors.0.field', 'body' )
        ->assertJsonPath( 'errors.0.code', $code );

    expect( $template->fresh()->body )->not->toBe( $body );
} )->with( [
    'system() call'      => [ "{{ system('rm -rf /') }}", 'template-error' ],
    'forbidden filter'   => [ "{{ Order.number|filter('system') }}", 'forbidden' ],
    'undeclared secret'  => [ '{{ Order.payment_reference }}', 'undeclared-variable' ],
] );

it( 'previews saved and unsaved sources through the delivery sandbox', function (): void {
    $template = confirmationTemplate();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( TEMPLATES_API . "/{$template->id}/preview" )
        ->assertOk()
        ->assertJsonPath( 'data.subject', 'Your ' . config( 'app.name' ) . ' order K7QM2XW9' );

    $this->postJson( TEMPLATES_API . "/{$template->id}/preview", [
        'subject'      => 'Hi {{ Order.customer.name }}',
        'body'         => '<p>{{ Order.customer.name }} — {{ Order.total }}</p>',
        'preview_data' => [ 'Order' => [ 'customer' => [ 'name' => '<i>Grace</i>' ] ] ],
    ] )->assertOk()
        ->assertJsonPath( 'data.subject', 'Hi <i>Grace</i>' )
        ->assertJsonPath( 'data.body', '<p>&lt;i&gt;Grace&lt;/i&gt; — $50.36</p>' );

    $this->postJson( TEMPLATES_API . "/{$template->id}/preview", [ 'body' => '{{ Order.nope }}' ] )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'undeclared-variable' );

    expect( $template->fresh()->subject )->toBe( $template->subject );
} );

it( 'exposes the editor over GraphQL', function (): void {
    $template = confirmationTemplate();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    gql( $this, '{ notificationTemplates { key label category variables } }' )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.notificationTemplates.0.key', NotificationCatalog::DOWNLOAD_READY );

    gql( $this, 'query ($id: ID!) { notificationTemplate(id: $id) { key variables } }', [ 'id' => $template->id ] )
        ->assertJsonPath( 'data.notificationTemplate.key', NotificationCatalog::ORDER_CONFIRMATION );

    $update = 'mutation ($input: UpdateNotificationTemplateInput!) { updateNotificationTemplate(input: $input) { notification_template { subject } errors { field code } } }';

    gql( $this, $update, [ 'input' => [ 'id' => $template->id, 'subject' => 'Got it, {{ Order.customer.first_name }}' ] ] )
        ->assertJsonPath( 'data.updateNotificationTemplate.errors', [] )
        ->assertJsonPath( 'data.updateNotificationTemplate.notification_template.subject', 'Got it, {{ Order.customer.first_name }}' );

    gql( $this, $update, [ 'input' => [ 'id' => $template->id, 'body' => "{{ system('id') }}" ] ] )
        ->assertJsonPath( 'data.updateNotificationTemplate.errors.0.field', 'body' );

    gql( $this, 'mutation ($input: PreviewNotificationTemplateInput!) { previewNotificationTemplate(input: $input) { rendered { subject body } errors { code } } }', [
        'input' => [ 'id' => $template->id, 'body' => '{{ Order.number }}' ],
    ] )->assertJsonPath( 'data.previewNotificationTemplate.rendered.subject', 'Got it, Ada' )
        ->assertJsonPath( 'data.previewNotificationTemplate.rendered.body', 'K7QM2XW9' );
} );

it( 'keeps GraphQL template fields admin-only', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    gql( $this, '{ notificationTemplates { key } }' )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );
} );

it( 'lets shoppers read and change their notification preferences', function (): void {
    $customer = Customer::factory()->forUser( 2 )->create( [ 'accepts_marketing' => false ] );

    $this->getJson( '/api/ecommerce/v1/me/notification-preferences' )->assertUnauthorized();

    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $prefs = collect( $this->getJson( '/api/ecommerce/v1/me/notification-preferences' )->assertOk()->json( 'data' ) )->keyBy( 'category' );

    expect( $prefs['transactional'] )->toMatchArray( [ 'is_enabled' => true, 'is_locked' => true ] )
        ->and( $prefs['marketing']['is_enabled'] )->toBeFalse()
        ->and( $prefs['review-requests']['is_enabled'] )->toBeTrue();

    $this->patchJson( '/api/ecommerce/v1/me/notification-preferences', [ 'preferences' => [ [ 'channel' => 'mail', 'category' => 'bogus', 'is_enabled' => false ] ] ], idem() )
        ->assertStatus( 422 );

    $updated = collect( $this->patchJson( '/api/ecommerce/v1/me/notification-preferences', [ 'preferences' => [
        [ 'channel' => 'mail', 'category' => 'marketing', 'is_enabled' => true ],
        [ 'channel' => 'mail', 'category' => 'transactional', 'is_enabled' => false ],
    ] ], idem() )->assertOk()->json( 'data' ) )->keyBy( 'category' );

    expect( $updated['marketing']['is_enabled'] )->toBeTrue()
        ->and( $updated['transactional']['is_enabled'] )->toBeTrue()
        ->and( $customer->notificationPreferences()->count() )->toBe( 2 );
} );

it( 'answers 404 when the account has no customer record', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/me/notification-preferences' )
        ->assertNotFound();
} );
