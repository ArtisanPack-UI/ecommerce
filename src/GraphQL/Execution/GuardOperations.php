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
 * - **Introspection** — `__schema` / `__type` are refused when
 *   `artisanpack.ecommerce.graphql.introspection` is false (null means
 *   "everywhere but production").
 *
 * Applied per schema, so it never affects a host app's own GraphQL schemas.
 * rebing applies its `graphql.security` limits to every schema through
 * webonyx's global rules (rebing 10 defaults them to a complexity of 500,
 * a depth of 13, and no introspection). While the ecommerce schema runs,
 * those global rules are stood down, because the engine's own limits above
 * already validated the document, and restored afterwards.
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
use GraphQL\Validator\Rules\DisableIntrospection;
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

        if ( ! $this->introspectionAllowed() ) {
            $rules[] = new DisableIntrospection( DisableIntrospection::ENABLED );
        }

        if ( [] !== $rules ) {
            $errors = DocumentValidator::validate( $schema, $document, $rules );

            if ( [] !== $errors ) {
                return new ExecutionResult( null, $errors );
            }
        }

        return $this->withGlobalRulesStoodDown(
            fn (): ExecutionResult => $next( $schemaName, $schema, $params, $rootValue, $contextValue ),
        );
    }

    /**
     * Whether introspection queries may run on the ecommerce schema.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function introspectionAllowed(): bool
    {
        $configured = config( 'artisanpack.ecommerce.graphql.introspection' );

        return null === $configured ? ! app()->isProduction() : (bool) $configured;
    }

    /**
     * Runs `$callback` with webonyx's global complexity, depth, and
     * introspection rules disabled, then restores their previous settings,
     * even when the callback throws.
     *
     * @since 1.0.0
     *
     * @param  Closure(): ExecutionResult  $callback  The rest of the pipeline.
     *
     * @return ExecutionResult
     */
    protected function withGlobalRulesStoodDown( Closure $callback ): ExecutionResult
    {
        $complexity    = DocumentValidator::getRule( QueryComplexity::class );
        $depth         = DocumentValidator::getRule( QueryDepth::class );
        $introspection = DocumentValidator::getRule( DisableIntrospection::class );

        $previousComplexity    = $complexity instanceof QueryComplexity ? $complexity->getMaxQueryComplexity() : null;
        $previousDepth         = $depth instanceof QueryDepth ? $depth->getMaxQueryDepth() : null;
        $previousIntrospection = $introspection instanceof DisableIntrospection ? $this->introspectionRuleState( $introspection ) : null;

        try {
            if ( null !== $previousComplexity ) {
                $complexity->setMaxQueryComplexity( QueryComplexity::DISABLED );
            }

            if ( null !== $previousDepth ) {
                $depth->setMaxQueryDepth( QueryDepth::DISABLED );
            }

            if ( null !== $previousIntrospection ) {
                $introspection->setEnabled( DisableIntrospection::DISABLED );
            }

            return $callback();
        } finally {
            if ( null !== $previousComplexity ) {
                $complexity->setMaxQueryComplexity( $previousComplexity );
            }

            if ( null !== $previousDepth ) {
                $depth->setMaxQueryDepth( $previousDepth );
            }

            if ( null !== $previousIntrospection ) {
                $introspection->setEnabled( $previousIntrospection );
            }
        }
    }

    /**
     * Reads a {@see DisableIntrospection} rule's current setting, which
     * webonyx keeps in a protected property with no getter.
     *
     * @since 1.0.0
     *
     * @param  DisableIntrospection  $rule  The global rule.
     *
     * @return int
     */
    protected function introspectionRuleState( DisableIntrospection $rule ): int
    {
        return ( fn (): int => $this->isEnabled )->call( $rule );
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
