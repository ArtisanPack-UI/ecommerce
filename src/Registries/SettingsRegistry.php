<?php

/**
 * SettingsRegistry.
 *
 * The allow-list of admin-editable store settings (engine issue #145). Each
 * group (`general`, `checkout`, `tax`, …) holds {@see SettingDefinition}s —
 * the keys an admin may change, with their type and validation rules — and
 * {@see SettingSecret}s, credentials that stay in env / config and are only
 * ever reported as configured or not.
 *
 * The engine fills the core groups at boot; satellites add their own keys
 * and groups from their service provider's `boot()`:
 *
 * ```php
 * $settings = app( SettingsRegistry::class );
 * $settings->addGroup( 'paypal', __( 'PayPal' ), 60 );
 * $settings->define( new SettingDefinition(
 *     key: 'paypal.enabled', group: 'payments', type: 'boolean',
 *     label: __( 'Enable PayPal' ), rules: [ 'boolean' ],
 *     configKey: 'artisanpack.ecommerce-paypal.enabled',
 * ) );
 * ```
 *
 * Defining a key applies its stored value (if any) to config straight away
 * through {@see SettingsRepository::apply()}, so code that reads config
 * after the definition sees the admin's value.
 *
 * Bound as a singleton in
 * {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Settings\SettingDefinition;
use ArtisanPackUI\Ecommerce\Settings\SettingSecret;
use ArtisanPackUI\Ecommerce\Settings\SettingsGroup;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SettingsRegistry
{
    /**
     * Groups by key.
     *
     * @since 1.0.0
     *
     * @var array<string, SettingsGroup>
     */
    protected array $groups = [];

    /**
     * Definitions by setting key.
     *
     * @since 1.0.0
     *
     * @var array<string, SettingDefinition>
     */
    protected array $definitions = [];

    /**
     * Secrets by config key.
     *
     * @since 1.0.0
     *
     * @var array<string, SettingSecret>
     */
    protected array $secrets = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Used to reach the repository lazily.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Adds (or relabels) a group.
     *
     * @since 1.0.0
     *
     * @param  SettingsGroup|string  $group        Group, or its key.
     * @param  string|null           $label        Translated label when `$group` is a key.
     * @param  int                   $position     Sort order when `$group` is a key.
     * @param  string|null           $description  Help text when `$group` is a key.
     *
     * @throws InvalidArgumentException When the key is empty or not URL-safe.
     *
     * @return static
     */
    public function addGroup( SettingsGroup|string $group, ?string $label = null, int $position = 100, ?string $description = null ): static
    {
        if ( is_string( $group ) ) {
            $group = new SettingsGroup( $group, $label ?? $group, $position, $description );
        }

        if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $group->key ) ) {
            throw new InvalidArgumentException( sprintf( 'Settings group key "%s" must be lowercase letters, digits, dashes, or underscores.', $group->key ) );
        }

        $this->groups[ $group->key ] = $group;

        return $this;
    }

    /**
     * Whether a group exists.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Group key.
     *
     * @return bool
     */
    public function hasGroup( string $key ): bool
    {
        return isset( $this->groups[ $key ] );
    }

    /**
     * One group.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Group key.
     *
     * @return SettingsGroup|null
     */
    public function group( string $key ): ?SettingsGroup
    {
        return $this->groups[ $key ] ?? null;
    }

    /**
     * Every group, by position then key.
     *
     * @since 1.0.0
     *
     * @return array<string, SettingsGroup>
     */
    public function groups(): array
    {
        $groups = $this->groups;

        uasort( $groups, static fn ( SettingsGroup $a, SettingsGroup $b ): int => [ $a->position, $a->key ] <=> [ $b->position, $b->key ] );

        return $groups;
    }

    /**
     * Allow-lists a setting and applies its stored value to config.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|SettingDefinition  $definition  Definition, or its constructor arguments.
     *
     * @throws InvalidArgumentException When the group is unknown, the config key is a secret, or the key is taken (local/testing).
     *
     * @return static
     */
    public function define( SettingDefinition|array $definition ): static
    {
        if ( is_array( $definition ) ) {
            $definition = SettingDefinition::fromArray( $definition );
        }

        if ( ! $this->hasGroup( $definition->group ) ) {
            throw new InvalidArgumentException( sprintf( 'Setting "%s" names unknown group "%s"; add the group first.', $definition->key, $definition->group ) );
        }

        if ( $this->overlapsSecret( $definition->configKey() ) ) {
            throw new InvalidArgumentException( sprintf( 'Setting "%s" overlays secret "%s"; secrets cannot be stored.', $definition->key, $definition->configKey() ) );
        }

        if ( isset( $this->definitions[ $definition->key ] ) ) {
            $message = sprintf( 'Setting "%s" is already defined; the new definition replaces the previous one.', $definition->key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->definitions[ $definition->key ] = $definition;

        $this->container->make( SettingsRepository::class )->apply( $definition );

        return $this;
    }

    /**
     * Whether a setting key is allow-listed.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Setting key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->definitions[ $key ] );
    }

    /**
     * One definition.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Setting key.
     *
     * @return SettingDefinition|null
     */
    public function definition( string $key ): ?SettingDefinition
    {
        return $this->definitions[ $key ] ?? null;
    }

    /**
     * Definitions, optionally for one group, by position then key.
     *
     * @since 1.0.0
     *
     * @param  string|null  $group  Group key, or null for all.
     *
     * @return array<string, SettingDefinition>
     */
    public function definitions( ?string $group = null ): array
    {
        $definitions = null === $group
            ? $this->definitions
            : array_filter( $this->definitions, static fn ( SettingDefinition $definition ): bool => $group === $definition->group );

        uasort( $definitions, static fn ( SettingDefinition $a, SettingDefinition $b ): int => [ $a->position, $a->key ] <=> [ $b->position, $b->key ] );

        return $definitions;
    }

    /**
     * Lists a credential on a group as configured / not configured.
     *
     * @since 1.0.0
     *
     * @param  SettingSecret  $secret  Secret.
     *
     * @throws InvalidArgumentException When the group is unknown or a definition already overlays the key.
     *
     * @return static
     */
    public function addSecret( SettingSecret $secret ): static
    {
        if ( ! $this->hasGroup( $secret->group ) ) {
            throw new InvalidArgumentException( sprintf( 'Secret "%s" names unknown group "%s"; add the group first.', $secret->configKey, $secret->group ) );
        }

        foreach ( $this->definitions as $definition ) {
            if ( self::overlaps( $definition->configKey(), $secret->configKey ) ) {
                throw new InvalidArgumentException( sprintf( 'Secret "%s" is already an editable setting ("%s").', $secret->configKey, $definition->key ) );
            }
        }

        $this->secrets[ $secret->configKey ] = $secret;

        return $this;
    }

    /**
     * Whether a config key is a secret.
     *
     * @since 1.0.0
     *
     * @param  string  $configKey  Full config path.
     *
     * @return bool
     */
    public function isSecret( string $configKey ): bool
    {
        return isset( $this->secrets[ $configKey ] );
    }

    /**
     * Whether a config key is a secret, holds one (a parent array), or sits
     * inside one — any of which would let a stored value expose or replace
     * the secret.
     *
     * @since 1.0.0
     *
     * @param  string  $configKey  Full config path.
     *
     * @return bool
     */
    public function overlapsSecret( string $configKey ): bool
    {
        foreach ( array_keys( $this->secrets ) as $secret ) {
            if ( self::overlaps( $configKey, $secret ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Secrets, optionally for one group.
     *
     * @since 1.0.0
     *
     * @param  string|null  $group  Group key, or null for all.
     *
     * @return array<string, SettingSecret>
     */
    public function secrets( ?string $group = null ): array
    {
        return null === $group
            ? $this->secrets
            : array_filter( $this->secrets, static fn ( SettingSecret $secret ): bool => $group === $secret->group );
    }

    /**
     * Whether two config paths are equal or one contains the other.
     *
     * @since 1.0.0
     *
     * @param  string  $a  Config path.
     * @param  string  $b  Config path.
     *
     * @return bool
     */
    protected static function overlaps( string $a, string $b ): bool
    {
        return $a === $b || str_starts_with( $a, $b . '.' ) || str_starts_with( $b, $a . '.' );
    }
}
