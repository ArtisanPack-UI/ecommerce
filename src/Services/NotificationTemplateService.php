<?php

/**
 * NotificationTemplateService.
 *
 * Bridges the notification catalog ({@see NotificationTemplateRegistry})
 * and the store owner's editable copy (`notification_templates`, engine
 * spec §3.30):
 *
 * - {@see self::sync()} makes sure every catalog entry has a row in the
 *   default locale, seeded with the default copy, and keeps each row's
 *   `variables` in step with its definition;
 * - {@see self::update()} validates edited sources through the sandbox
 *   before saving them;
 * - {@see self::preview()} and {@see self::render()} both render through
 *   {@see NotificationTemplateRenderer::renderTemplate()}, so the editor's
 *   preview matches what gets delivered.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateService
{
    /**
     * @since 1.0.0
     *
     * @param  NotificationTemplateRegistry  $registry  Catalog.
     * @param  NotificationTemplateRenderer  $renderer  Sandboxed renderer.
     * @param  NotificationContext           $context   Context builder.
     */
    public function __construct(
        protected NotificationTemplateRegistry $registry,
        protected NotificationTemplateRenderer $renderer,
        protected NotificationContext $context,
    ) {
    }

    /**
     * The locale templates are seeded in and fall back to.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function defaultLocale(): string
    {
        return (string) ( config( 'artisanpack.ecommerce.notifications.default_locale' ) ?? config( 'app.fallback_locale', 'en' ) );
    }

    /**
     * Seeds a default-locale row for every catalog entry that lacks one and
     * refreshes each row's declared `variables` from its definition.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function sync(): void
    {
        $locale   = $this->defaultLocale();
        $existing = NotificationTemplate::query()->where( 'locale', $locale )->get()->keyBy( fn ( NotificationTemplate $row ): string => $row->key . '|' . $row->channel );

        foreach ( $this->registry->all() as $definition ) {
            $row = $existing->get( $definition->key() . '|' . $definition->channel() );

            if ( null === $row ) {
                // createOrFirst: two first loads racing on the unique
                // (key, channel, locale) index must not 500.
                NotificationTemplate::query()->createOrFirst( [
                    'key'     => $definition->key(),
                    'channel' => $definition->channel(),
                    'locale'  => $locale,
                ], [
                    'subject'      => $definition->defaultSubject(),
                    'body'         => $definition->defaultBody(),
                    'variables'    => $definition->variables(),
                    'preview_data' => $definition->previewData(),
                    'is_active'    => true,
                ] );

                continue;
            }

            if ( $row->variables !== $definition->variables() ) {
                $row->forceFill( [ 'variables' => $definition->variables() ] )->save();
            }
        }
    }

    /**
     * The row that supplies `$key`'s copy on `$channel` for `$locale`
     * (falling back to the default locale), if the store has one.
     *
     * @since 1.0.0
     *
     * @param  string       $key      Template key.
     * @param  string       $channel  Channel.
     * @param  string|null  $locale   Preferred locale.
     *
     * @return NotificationTemplate|null
     */
    public function find( string $key, string $channel, ?string $locale = null ): ?NotificationTemplate
    {
        $locales = array_values( array_unique( array_filter( [ $locale ?? app()->getLocale(), $this->defaultLocale() ] ) ) );

        return NotificationTemplate::query()
            ->where( 'key', $key )
            ->where( 'channel', $channel )
            ->whereIn( 'locale', $locales )
            ->get()
            ->sortBy( fn ( NotificationTemplate $row ): int|false => array_search( $row->locale, $locales, true ) )
            ->first();
    }

    /**
     * Whether `$key` should be sent at all: registered, and its row (if
     * any) not switched off.
     *
     * @since 1.0.0
     *
     * @param  string       $key     Template key.
     * @param  string|null  $locale  Preferred locale.
     *
     * @return bool
     */
    public function isActive( string $key, ?string $locale = null ): bool
    {
        if ( ! $this->registry->has( $key ) ) {
            return false;
        }

        return $this->find( $key, $this->registry->get( $key )->channel(), $locale )?->is_active ?? true;
    }

    /**
     * Renders `$key` with the store's current copy (or the catalog
     * default when the store has none).
     *
     * @since 1.0.0
     *
     * @param  string                $key        Template key.
     * @param  string                $channel    Channel.
     * @param  array<string, mixed>  $variables  Render context.
     * @param  string|null           $locale     Preferred locale.
     *
     * @throws NotificationTemplateException When the copy fails to render.
     *
     * @return array{subject: string|null, body: string}
     */
    public function render( string $key, string $channel, array $variables, ?string $locale = null ): array
    {
        $row        = $this->find( $key, $channel, $locale );
        $definition = $this->registry->has( $key ) ? $this->registry->get( $key ) : null;

        return $this->renderer->renderTemplate(
            $key,
            $row?->subject ?? $definition?->defaultSubject(),
            (string) ( $row?->body ?? $definition?->defaultBody() ?? '' ),
            $variables,
            $channel,
        );
    }

    /**
     * Validates and saves edits to a row's `subject`, `body`, `is_active`,
     * and `preview_data`.
     *
     * @since 1.0.0
     *
     * @param  NotificationTemplate  $template    Row.
     * @param  array<string, mixed>  $attributes  Validated attributes.
     *
     * @throws NotificationTemplateException When a source is invalid.
     *
     * @return NotificationTemplate
     */
    public function update( NotificationTemplate $template, array $attributes ): NotificationTemplate
    {
        $attributes = array_intersect_key( $attributes, array_flip( [ 'subject', 'body', 'is_active', 'preview_data' ] ) );
        $sources    = array_intersect_key( $attributes, array_flip( [ 'subject', 'body' ] ) );

        if ( [] !== $sources ) {
            $this->renderer->validate( $sources, $this->declaredVariables( $template ), $this->sampleData( $template ), 'mail' === $template->channel );
        }

        $template->fill( $attributes )->save();

        return $template;
    }

    /**
     * Renders a live preview of `$template` — or of unsaved `$subject` /
     * `$body` sources — against `$previewData` (default: the row's stored
     * preview data), validating the sources exactly as a save would.
     * `Store` always carries the live store details, as at delivery.
     *
     * @since 1.0.0
     *
     * @param  NotificationTemplate       $template     Row.
     * @param  string|null                $subject      Unsaved subject source.
     * @param  string|null                $body         Unsaved body source.
     * @param  array<string, mixed>|null  $previewData  Sample context.
     *
     * @throws NotificationTemplateException When a source is invalid.
     *
     * @return array{subject: string|null, body: string}
     */
    public function preview( NotificationTemplate $template, ?string $subject = null, ?string $body = null, ?array $previewData = null ): array
    {
        $subject ??= $template->subject;
        $body ??= $template->body;

        $this->renderer->validate( [ 'subject' => $subject, 'body' => $body ], $this->declaredVariables( $template ), $this->sampleData( $template ), 'mail' === $template->channel );

        $variables = (array) applyFilters(
            'ap.ecommerce.notification.templateVariables',
            array_replace_recursive(
                $this->sampleData( $template ),
                $previewData ?? (array) $template->preview_data,
                [ 'Store' => $this->context->store() ],
            ),
            $template->key,
            null,
        );

        return $this->renderer->renderTemplate( $template->key, $subject, $body, $variables, $template->channel );
    }

    /**
     * The variables `$template` may reference: its definition's, else the
     * row's own list.
     *
     * @since 1.0.0
     *
     * @param  NotificationTemplate  $template  Row.
     *
     * @return array<int, string>
     */
    public function declaredVariables( NotificationTemplate $template ): array
    {
        return $template->definition()?->variables() ?? (array) $template->variables;
    }

    /**
     * Baseline sample context: the definition's preview data over the
     * engine's sample values plus the live store details.
     *
     * @since 1.0.0
     *
     * @param  NotificationTemplate  $template  Row.
     *
     * @return array<string, mixed>
     */
    protected function sampleData( NotificationTemplate $template ): array
    {
        return array_replace_recursive(
            (array) ( $template->definition()?->previewData() ?? [] ),
            [ 'Store' => $this->context->store() ],
        );
    }
}
