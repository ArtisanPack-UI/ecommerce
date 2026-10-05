<?php

/**
 * SettingsWriteException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Settings\SettingsRepository}
 * when a settings write is refused: an unknown group or key, a value that
 * fails its rules, or a base-currency change that was not confirmed
 * (parent plan §16.4). Rendered as a 422 `settings-write-failed` problem
 * on the REST API.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SettingsWriteException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  array<int, array{field: string|null, code: string, message: string}>  $errors  One entry per refused field.
     */
    public function __construct( public readonly array $errors )
    {
        parent::__construct(
            (string) ( $errors[0]['message'] ?? __( 'The settings change was refused.' ) ),
            [ 'errors' => $errors ],
        );
    }

    /**
     * A refusal for a single field.
     *
     * @since 1.0.0
     *
     * @param  string|null  $field    Setting key, or null for the whole request.
     * @param  string       $code     Machine-readable code.
     * @param  string       $message  Translated message.
     *
     * @return self
     */
    public static function field( ?string $field, string $code, string $message ): self
    {
        return new self( [ [ 'field' => $field, 'code' => $code, 'message' => $message ] ] );
    }

    /**
     * The messages keyed by setting key, for form error bags.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public function messagesByField(): array
    {
        $messages = [];

        foreach ( $this->errors as $error ) {
            $messages[ (string) ( $error['field'] ?? '' ) ][] = $error['message'];
        }

        return $messages;
    }
}
