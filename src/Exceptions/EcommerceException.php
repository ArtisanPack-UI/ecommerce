<?php

/**
 * EcommerceException.
 *
 * Base class for every exception thrown by the ecommerce engine. Carries
 * a structured `context()` array so callers (log handlers, error reporters,
 * `problem+json` responders) can render machine-readable error detail
 * without string-parsing the message.
 *
 * Engine plan §16.3.
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

use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceException extends RuntimeException
{
    /**
     * Structured context values attached to this exception.
     *
     * @since 1.0.0
     *
     * @var  array<string, mixed>
     */
    protected array $context = [];

    /**
     * @since 1.0.0
     *
     * @param  string                $message   Human-readable exception message.
     * @param  array<string, mixed>  $context   Structured context values.
     * @param  int                   $code      Optional exception code.
     * @param  Throwable|null        $previous  Optional previous exception.
     */
    public function __construct(
        string $message = '',
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct( $message, $code, $previous );

        $this->context = $context;
    }

    /**
     * Returns the structured context array attached to this exception.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Merges additional values into the exception context and returns the
     * exception for fluent use inside a `throw` expression.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Additional context to merge.
     *
     * @return static
     */
    public function withContext( array $context ): static
    {
        $this->context = array_merge( $this->context, $context );

        return $this;
    }
}
