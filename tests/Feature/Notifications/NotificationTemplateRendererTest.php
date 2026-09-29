<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;

function renderer(): NotificationTemplateRenderer
{
    return app( NotificationTemplateRenderer::class );
}

/**
 * The errors `validate()` reports for a body source.
 *
 * @param  array<int, string>  $declared
 *
 * @return array<int, array{field: string, code: string, message: string}>
 */
function templateErrors( string $body, array $declared = [ 'Order.number', 'Order.customer.name', 'Order.items.*.name' ] ): array
{
    try {
        renderer()->validate( [ 'body' => $body ], $declared, [ 'Order' => [ 'number' => 'A1', 'customer' => [ 'name' => 'Ada' ], 'items' => [ [ 'name' => 'Mug' ] ] ] ] );
    } catch ( NotificationTemplateException $exception ) {
        return $exception->errors;
    }

    return [];
}

it( 'renders variables, escaping HTML in mail bodies but not in subjects', function (): void {
    $vars = [ 'Order' => [ 'number' => 'A1', 'customer' => [ 'name' => '<b>Ada</b> & Co' ] ] ];

    $rendered = renderer()->renderTemplate( 'test', 'Order {{ Order.number }} for {{ Order.customer.name }}', '<p>{{ Order.customer.name }}</p>', $vars, 'mail' );

    expect( $rendered['subject'] )->toBe( 'Order A1 for <b>Ada</b> & Co' )
        ->and( $rendered['body'] )->toBe( '<p>&lt;b&gt;Ada&lt;/b&gt; &amp; Co</p>' );
} );

it( 'runs the body through the rendering filter', function (): void {
    addFilter( 'ap.ecommerce.notification.rendering', fn ( string $body, string $key ): string => $body . '<footer>{{ Order.number }} via ' . $key . '</footer>' );

    expect( renderer()->renderTemplate( 'order.x', null, 'Hi', [ 'Order' => [ 'number' => 'A1' ] ], 'mail' )['body'] )
        ->toBe( 'Hi<footer>A1 via order.x</footer>' );
} );

it( 'rejects anything outside the sandbox', function ( string $source, string $code ): void {
    $errors = templateErrors( $source );

    expect( $errors )->not->toBeEmpty()
        ->and( array_column( $errors, 'code' ) )->toContain( $code );
} )->with( [
    'arbitrary PHP function'   => [ "{{ system('rm -rf /') }}", 'template-error' ],
    'callable filter'          => [ "{{ ['id']|map('system') }}", 'forbidden' ],
    'raw output'               => [ '{{ Order.number|raw }}', 'forbidden' ],
    'include tag'              => [ "{% include 'secrets.twig' %}", 'forbidden' ],
    'macro tag'                => [ '{% macro x() %}{% endmacro %}', 'forbidden' ],
    'constant function'        => [ "{{ constant('PHP_VERSION') }}", 'forbidden' ],
    'padding batch filter'     => [ "{{ Order.items|batch(100000000, 'x')|length }}", 'forbidden' ],
    'string-doubling set'      => [ '{% set s = Order.number %}{% set s = s ~ s %}{{ s }}', 'forbidden' ],
    'sprintf width'            => [ "{{ '%2147483646s'|format('a') }}", 'forbidden' ],
    'split into a loop'        => [ "{% for c in 'abcdef'|split('') %}{{ c }}{% endfor %}", 'forbidden' ],
    'loops nested three deep'  => [ '{% for a in Order.items %}{% for b in Order.items %}{% for c in Order.items %}x{% endfor %}{% endfor %}{% endfor %}', 'loop-too-deep' ],
    'range operator'           => [ '{% for i in 1..100000000 %}{{ i }}{% endfor %}', 'forbidden-operator' ],
    'the template context'     => [ '{{ _context|keys|join }}', 'undeclared-variable' ],
] );

it( 'rejects sandbox escapes at render time too', function (): void {
    renderer()->render( "{{ system('id') }}", [], false );
} )->throws( NotificationTemplateException::class );

it( 'rejects references to undeclared variables, naming the line', function (): void {
    $errors = templateErrors( "{{ Order.number }}\n{{ Order.secret_token }}\n{{ Customer.password }}" );

    expect( array_column( $errors, 'code' ) )->toBe( [ 'undeclared-variable', 'undeclared-variable' ] )
        ->and( $errors[0]['message'] )->toContain( 'Line 2' )->toContain( 'Order.secret_token' )
        ->and( $errors[1]['message'] )->toContain( 'Customer.password' );
} );

it( 'accepts declared paths, their parents, loop variables, and nested loops', function (): void {
    expect( templateErrors( '{% if Order.customer %}ok{% endif %}{{ Order.customer.name|upper }}{% for item in Order.items %}{{ loop.index }}. {{ item.name }}{% endfor %}{% for a in Order.items %}{% for b in Order.items %}{{ a.name }}{{ b.name }}{% endfor %}{% endfor %}{{ Order.items[0].name }}' ) )
        ->toBe( [] );
} );

it( 'reports syntax errors per field', function (): void {
    try {
        renderer()->validate( [ 'subject' => '{{ Order.number', 'body' => 'fine' ], [ 'Order.number' ] );
        $this->fail( 'Expected a template exception.' );
    } catch ( NotificationTemplateException $exception ) {
        expect( $exception->errors )->toHaveCount( 1 )
            ->and( $exception->errors[0]['field'] )->toBe( 'subject' )
            ->and( $exception->errors[0]['code'] )->toBe( 'template-error' );
    }
} );

it( 'registers the whole notification catalog', function (): void {
    expect( array_keys( app( NotificationTemplateRegistry::class )->all() ) )->toEqualCanonicalizing( [
        NotificationCatalog::ORDER_CONFIRMATION,
        NotificationCatalog::ORDER_PAID_ADMIN,
        NotificationCatalog::ORDER_SHIPPED,
        NotificationCatalog::ORDER_DELIVERED,
        NotificationCatalog::ORDER_CANCELLED,
        NotificationCatalog::ORDER_REFUNDED,
        NotificationCatalog::REVIEW_REQUEST,
        NotificationCatalog::DOWNLOAD_READY,
        NotificationCatalog::DIGITAL_PRODUCT_UPDATED,
        NotificationCatalog::LICENSE_ACTIVATED,
        NotificationCatalog::LOW_STOCK_ADMIN,
        NotificationCatalog::OUT_OF_STOCK_ADMIN,
        NotificationCatalog::REVIEW_AWAITING_MODERATION_ADMIN,
    ] );
} );

it( 'ships default copy that passes its own validation', function ( string $key ): void {
    $definition = app( NotificationTemplateRegistry::class )->get( $key );

    renderer()->validate(
        [ 'subject' => $definition->defaultSubject(), 'body' => $definition->defaultBody() ],
        $definition->variables(),
        $definition->previewData(),
    );

    $rendered = renderer()->renderTemplate( $key, $definition->defaultSubject(), $definition->defaultBody(), $definition->previewData(), $definition->channel() );

    expect( trim( (string) $rendered['subject'] ) )->not->toBe( '' )
        ->and( $rendered['body'] )->not->toContain( '{{' );
} )->with( array_values( ( new ReflectionClass( NotificationCatalog::class ) )->getConstants() ) );
