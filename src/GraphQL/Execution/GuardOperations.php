<?php

/**
 * GuardOperations.
 *
 * rebing/graphql-laravel execution middleware for the ecommerce schema. It
 * validates each document before anything executes:
 *
 * - **Read-only GET** — a GET request may only run a `query`. rebing does
 *   not enforce this itself, and without it a mutation could ride on a
 *   cross-site `<img src="/graphql/ecommerce?query=mutation…">` against a
 *   cookie-session admin.
 * - **Depth** — at most `artisanpack.ecommerce.graphql.max_depth` levels
 *   (the schema has relation cycles such as `Product.variants.product`).
 * - **Complexity** — at most `artisanpack.ecommerce.graphql.max_complexity`
 *   cost, where connections multiply by `first` and relation lists by an
 *   assumed size (see {@see \ArtisanPackUI\Ecommerce\GraphQL\EcommerceSchema}),
 *   which bounds nested-list and alias amplification.
 *
 * Applied per schema, so it never affects a host app's own GraphQL schemas.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL\Execution;

use Closure;
use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Rebing\GraphQL\Support\ExecutionMiddleware\AbstractExecutionMiddleware;
use Rebing\GraphQL\Support\OperationParams;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GuardOperations extends AbstractExecutionMiddleware
{
    /**
     * @since 1.0.0
     *
     * @param  string           $schemaName    Schema name.
     * @param  Schema           $schema        Schema.
     * @param  OperationParams  $params        Operation.
     * @param  mixed            $rootValue     Root value.
     * @param  mixed            $contextValue  Context value.
     * @param  Closure          $next          Next middleware.
     *
     * @return ExecutionResult
     */
    public function handle( string $schemaName, Schema $schema, OperationParams $params, $rootValue, $contextValue, Closure $next ): ExecutionResult
    {
        try {
            $document = $params->getParsedQuery();
        } catch ( Error $error ) {
            return new ExecutionResult( null, [ $error ] );
        }

        if ( $params->isReadOnly() && 'query' !== $this->operationType( $document, $params->operation ) ) {
            return new ExecutionResult( null, [ new Error( __( 'GET requests may only execute query operations; send mutations with POST.' ) ) ] );
        }

        $rules      = [];
        $depth      = (int) config( 'artisanpack.ecommerce.graphql.max_depth', 10 );
        $complexity = (int) config( 'artisanpack.ecommerce.graphql.max_complexity', 5_000 );

        if ( $depth > 0 ) {
            $rules[] = new QueryDepth( $depth );
        }

        if ( $complexity > 0 ) {
            $complexityRule = new QueryComplexity( $complexity );
            $complexityRule->setRawVariableValues( $params->variables ?? [] );
            $rules[] = $complexityRule;
        }

        if ( [] !== $rules ) {
            $errors = DocumentValidator::validate( $schema, $document, $rules );

            if ( [] !== $errors ) {
                return new ExecutionResult( null, $errors );
            }
        }

        return $next( $schemaName, $schema, $params, $rootValue, $contextValue );
    }

    /**
     * The type (`query`, `mutation`, `subscription`) of the operation that
     * will run, or null when it can't be determined.
     *
     * @since 1.0.0
     *
     * @param  DocumentNode  $document       Parsed document.
     * @param  string|null   $operationName  Requested operation name.
     *
     * @return string|null
     */
    protected function operationType( DocumentNode $document, ?string $operationName ): ?string
    {
        $operations = [];

        foreach ( $document->definitions as $definition ) {
            if ( $definition instanceof OperationDefinitionNode ) {
                $operations[] = $definition;
            }
        }

        foreach ( $operations as $operation ) {
            if ( null === $operationName ? 1 === count( $operations ) : $operation->name?->value === $operationName ) {
                return $operation->operation;
            }
        }

        return null;
    }
}
