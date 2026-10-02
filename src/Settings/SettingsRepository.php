<?php

/**
 * SettingsRepository.
 *
 * Reads and writes the persisted store settings (engine issue #145, parent
 * plan §11.1). Stored values live in `ecommerce_settings` and overlay
 * `config( 'artisanpack.ecommerce' )` for the allow-listed keys in
 * {@see SettingsRegistry}:
 *
 * - **Reads** fall back to config. The stored rows are cached (ten minutes) under
 *   {@see self::CACHE_KEY}, memoised per process, and applied to the config
 *   repository when each key is defined, so every `config()` read of an
 *   allow-listed key — in the engine or a satellite — resolves through this
 *   repository without each caller changing. Queue workers re-apply them
 *   before every job, so a long-running worker picks up admin changes.
 * - **Writes** go through {@see self::update()}: unknown keys and invalid
 *   values are refused with a {@see SettingsWriteException}, the rows are
 *   written in a transaction, and the cache is forgotten once it commits.
 * - **Secrets** are never stored or returned; {@see self::secrets()} only
 *   says whether each one is configured.
 * - **Base currency** changes need an explicit confirmation (parent plan
 *   §16.4). Historical orders keep their `base_currency` and
 *   `fx_rate_to_base_e8` snapshot; only new orders adopt the new base.
 *
 * Hooks: `ap.ecommerce.settings.updated` (group, changes) and
 * `ap.ecommerce.settings.baseCurrencyChanged` (old, new), both after commit.
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

namespace ArtisanPackUI\Ecommerce\Settings;

use ArtisanPackUI\Ecommerce\Exceptions\SettingsWriteException;
use ArtisanPackUI\Ecommerce\Models\EcommerceSetting;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SettingsRepository
{
    /**
     * Cache key holding the stored `key => value` map.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_KEY = 'ap.ecommerce.settings';

    /**
     * Setting key of the store's base currency.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const BASE_CURRENCY_KEY = 'base_currency';

    /**
     * Seconds the stored rows stay cached.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CACHE_TTL = 600;

    /**
     * Per-process copy of the stored values.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>|null
     */
    protected ?array $stored = null;

    /**
     * Config values as they were before any stored value was applied, by
     * setting key, so {@see self::forget()} can restore them.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [];

    /**
     * Whether the table could not be read in this process (fresh install,
     * mid-migration), so later lookups skip the query until a write.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $unavailable = false;

    /**
     * Setting keys whose config value currently comes from a stored row,
     * so {@see self::apply()} only restores defaults it replaced and never
     * reverts config the host set at runtime.
     *
     * @since 1.0.0
     *
     * @var array<string, true>
     */
    protected array $overlaid = [];

    /**
     * Whether stored values are applied to config. Off while
     * `config:cache` / `optimize` run, so stored values never end up in the
     * cached config file.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $overlayEnabled = true;

    /**
     * Checks run on the combined state of every setting after a write,
     * keyed by name. Each receives `( array $values )` — the effective value
     * of every allow-listed key after the write — and returns the errors
     * (`field`, `code`, `message`) the write must be refused with.
     *
     * @since 1.0.0
     *
     * @var array<string, Closure(array<string, mixed>): array<int, array{field: string|null, code: string, message: string}>>
     */
    protected array $constraints = [];

    /**
     * @since 1.0.0
     *
     * @param  SettingsRegistry  $registry  The allow-list.
     * @param  ConfigRepository  $config    Config repository the values overlay.
     */
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * The current value of a setting: the stored value when there is one,
     * otherwise config, otherwise `$default`.
     *
     * @since 1.0.0
     *
     * @param  string  $key      Setting key (e.g. `tax.provider`).
     * @param  mixed   $default  Fallback when neither is set.
     *
     * @return mixed
     */
    public function get( string $key, mixed $default = null ): mixed
    {
        $definition = $this->registry->definition( $key );
        $stored     = $this->stored();

        if ( null !== $definition && array_key_exists( $key, $stored ) ) {
            return $stored[ $key ];
        }

        return $this->config->get( $definition?->configKey() ?? SettingDefinition::CONFIG_PREFIX . $key, $default );
    }

    /**
     * Whether an admin has stored a value for the key (as opposed to it
     * coming from config).
     *
     * @since 1.0.0
     *
     * @param  string  $key  Setting key.
     *
     * @return bool
     */
    public function isStored( string $key ): bool
    {
        return array_key_exists( $key, $this->stored() );
    }

    /**
     * The current value of every setting in a group.
     *
     * @since 1.0.0
     *
     * @param  string  $group  Group key.
     *
     * @return array<string, mixed>
     */
    public function values( string $group ): array
    {
        $values = [];

        foreach ( $this->registry->definitions( $group ) as $key => $definition ) {
            $values[ $key ] = $this->get( $key );
        }

        return $values;
    }

    /**
     * The group's secrets as `config key => configured?`, never their values.
     *
     * @since 1.0.0
     *
     * @param  string  $group  Group key.
     *
     * @return array<string, bool>
     */
    public function secrets( string $group ): array
    {
        return array_map( static fn ( SettingSecret $secret ): bool => $secret->isConfigured(), $this->registry->secrets( $group ) );
    }

    /**
     * Validates and stores new values for settings in one group, and resets
     * stored values back to config, in one transaction.
     *
     * Keys left out of `$values` and `$reset` are untouched. Values equal to
     * the current one are skipped, as are reset keys with nothing stored. A
     * key in both `$values` and `$reset` takes the value.
     *
     * @since 1.0.0
     *
     * @param  string                $group                      Group key.
     * @param  array<string, mixed>  $values                     `setting key => value`.
     * @param  bool                  $confirmBaseCurrencyChange  Must be true to change the base currency (parent plan §16.4).
     * @param  array<int, string>    $reset                      Setting keys whose stored value to delete.
     *
     * @throws SettingsWriteException When the group or a key is unknown, a value fails validation, or a base-currency change is unconfirmed.
     *
     * @return array<string, array{from: mixed, to: mixed, reset: bool}> What changed, by setting key.
     */
    public function update( string $group, array $values, bool $confirmBaseCurrencyChange = false, array $reset = [] ): array
    {
        $normalized = $this->validate( $group, $values );

        $this->assertKeysInGroup( $group, $reset );

        $changes = [];

        foreach ( $reset as $key ) {
            if ( ! array_key_exists( $key, $normalized ) && $this->isStored( $key ) ) {
                $changes[ $key ] = [ 'from' => $this->get( $key ), 'to' => $this->defaultFor( $key ), 'reset' => true ];
            }
        }

        foreach ( $normalized as $key => $value ) {
            $current = $this->get( $key );

            if ( $current !== $value ) {
                $changes[ $key ] = [ 'from' => $current, 'to' => $value, 'reset' => false ];
            }
        }

        $baseCurrency = $changes[ self::BASE_CURRENCY_KEY ] ?? null;
        $baseChanges  = null !== $baseCurrency && strtoupper( (string) $baseCurrency['from'] ) !== strtoupper( (string) $baseCurrency['to'] );

        if ( $baseChanges && ! $confirmBaseCurrencyChange ) {
            throw SettingsWriteException::field(
                self::BASE_CURRENCY_KEY,
                'base-currency-change-unconfirmed',
                __( 'Changing the base currency needs confirmation. Existing orders keep the base currency and exchange rate they were placed with; reports convert them to the new base at today\'s rate and flag them.' ),
            );
        }

        if ( [] === $changes ) {
            return [];
        }

        $this->assertConstraints( $changes );

        DB::transaction( function () use ( $changes ): void {
            foreach ( $changes as $key => $change ) {
                if ( $change['reset'] ) {
                    EcommerceSetting::query()->where( 'key', $key )->delete();
                } else {
                    EcommerceSetting::query()->updateOrCreate( [ 'key' => $key ], [ 'value' => $change['to'] ] );
                }
            }
        } );

        $this->afterWrite( function () use ( $group, $changes, $baseChanges ): void {
            doAction( 'ap.ecommerce.settings.updated', $group, $changes );

            if ( $baseChanges ) {
                doAction(
                    'ap.ecommerce.settings.baseCurrencyChanged',
                    strtoupper( (string) $changes[ self::BASE_CURRENCY_KEY ]['from'] ),
                    strtoupper( (string) $changes[ self::BASE_CURRENCY_KEY ]['to'] ),
                );
            }
        } );

        return $changes;
    }

    /**
     * Deletes stored values so the keys fall back to config again.
     *
     * @since 1.0.0
     *
     * @param  string             $group                      Group key.
     * @param  array<int, string> $keys                       Setting keys in the group.
     * @param  bool               $confirmBaseCurrencyChange  Must be true when resetting changes the base currency.
     *
     * @throws SettingsWriteException When the group or a key is unknown, or the base currency would change unconfirmed.
     *
     * @return array<string, array{from: mixed, to: mixed, reset: bool}> What changed, by setting key.
     */
    public function forget( string $group, array $keys, bool $confirmBaseCurrencyChange = false ): array
    {
        return $this->update( $group, [], $confirmBaseCurrencyChange, $keys );
    }

    /**
     * The config value a setting falls back to when nothing is stored.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Setting key.
     *
     * @return mixed
     */
    public function defaultFor( string $key ): mixed
    {
        $definition = $this->registry->definition( $key );

        if ( null === $definition ) {
            return null;
        }

        return array_key_exists( $key, $this->defaults ) ? $this->defaults[ $key ] : $this->config->get( $definition->configKey() );
    }

    /**
     * Applies the stored value of one definition to config, remembering the
     * config value it replaces. Called by {@see SettingsRegistry::define()}.
     *
     * @since 1.0.0
     *
     * @param  SettingDefinition  $definition  Definition.
     *
     * @return void
     */
    public function apply( SettingDefinition $definition ): void
    {
        if ( ! array_key_exists( $definition->key, $this->defaults ) ) {
            $this->defaults[ $definition->key ] = $this->config->get( $definition->configKey() );
        }

        if ( ! $this->overlayEnabled ) {
            return;
        }

        $stored = $this->stored();

        if ( array_key_exists( $definition->key, $stored ) ) {
            $this->config->set( $definition->configKey(), $stored[ $definition->key ] );
            $this->overlaid[ $definition->key ] = true;

            return;
        }

        // Only undo our own overlay; config the host set at runtime stays.
        if ( isset( $this->overlaid[ $definition->key ] ) ) {
            $this->config->set( $definition->configKey(), $this->defaults[ $definition->key ] );
            unset( $this->overlaid[ $definition->key ] );
        }
    }

    /**
     * Stops (or resumes) applying stored values to config. The engine turns
     * it off while `config:cache` / `optimize` boot the app, so the cached
     * config holds only the real config files.
     *
     * @since 1.0.0
     *
     * @param  bool  $enabled  Whether to apply stored values.
     *
     * @return void
     */
    public function setOverlayEnabled( bool $enabled ): void
    {
        $this->overlayEnabled = $enabled;
    }

    /**
     * Adds a check on the combined settings, run before every write. Use it
     * for rules that span keys (a fraud chain that needs a gateway enabled).
     *
     * @since 1.0.0
     *
     * @param  string                                                                                   $name        Unique name.
     * @param  Closure(array<string, mixed>): array<int, array{field: string|null, code: string, message: string}>  $constraint  Returns the errors, or `[]`.
     *
     * @return void
     */
    public function constrain( string $name, Closure $constraint ): void
    {
        $this->constraints[ $name ] = $constraint;
    }

    /**
     * Re-reads the stored values and re-applies every definition. Queue
     * workers call this before each job.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->stored      = null;
        $this->unavailable = false;

        foreach ( $this->registry->definitions() as $definition ) {
            $this->apply( $definition );
        }
    }

    /**
     * Forgets the cached values.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->stored      = null;
        $this->unavailable = false;

        try {
            Cache::forget( self::CACHE_KEY );
        } catch ( Throwable ) {
            // Nothing cached to forget.
        }
    }

    /**
     * The stored `key => value` map, from memory, the cache, or the table.
     * Before the table exists (fresh install, mid-migration) it is empty.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        if ( null !== $this->stored ) {
            return $this->stored;
        }

        if ( $this->unavailable ) {
            return [];
        }

        try {
            $cached = Cache::get( self::CACHE_KEY );

            if ( is_array( $cached ) ) {
                return $this->stored = $cached;
            }
        } catch ( Throwable ) {
            // Fall through to the database.
        }

        try {
            $stored = EcommerceSetting::query()->get( [ 'key', 'value' ] )->pluck( 'value', 'key' )->all();
        } catch ( Throwable ) {
            $this->unavailable = true;

            return [];
        }

        // Rows read inside a transaction may be rolled back, so they are
        // served but not remembered.
        if ( self::inTransaction() ) {
            return $stored;
        }

        try {
            // A TTL bounds how long a read that raced a write can serve the
            // pre-write rows.
            Cache::put( self::CACHE_KEY, $stored, self::CACHE_TTL );
        } catch ( Throwable ) {
            // Serve from the database until the cache is back.
        }

        return $this->stored = $stored;
    }

    /**
     * Runs the cross-key constraints on the state the write would leave.
     *
     * @since 1.0.0
     *
     * @param  array<string, array{from: mixed, to: mixed, reset: bool}>  $changes  Pending changes.
     *
     * @throws SettingsWriteException When a constraint fails.
     *
     * @return void
     */
    protected function assertConstraints( array $changes ): void
    {
        if ( [] === $this->constraints ) {
            return;
        }

        $values = [];

        foreach ( $this->registry->definitions() as $key => $definition ) {
            $values[ $key ] = array_key_exists( $key, $changes ) ? $changes[ $key ]['to'] : $this->get( $key );
        }

        $errors = [];

        foreach ( $this->constraints as $constraint ) {
            $errors = [ ...$errors, ...$constraint( $values ) ];
        }

        if ( [] !== $errors ) {
            throw new SettingsWriteException( array_values( $errors ) );
        }
    }

    /**
     * Normalizes and validates `$values` against the group's definitions.
     *
     * @since 1.0.0
     *
     * @param  string                $group   Group key.
     * @param  array<string, mixed>  $values  Submitted values.
     *
     * @throws SettingsWriteException On any unknown key or invalid value.
     *
     * @return array<string, mixed> Normalized values.
     */
    protected function validate( string $group, array $values ): array
    {
        $this->assertKeysInGroup( $group, array_map( 'strval', array_keys( $values ) ) );

        $errors     = [];
        $normalized = [];

        foreach ( $values as $key => $value ) {
            $definition = $this->registry->definition( (string) $key );
            $value      = $definition->normalize( $value );
            $validator  = Validator::make(
                [ 'value' => $value ],
                [ 'value' => [ ...$this->typeRules( $definition ), ...$definition->rules() ], 'value.*' => $this->itemRules( $definition ) ],
                [],
                [ 'value' => $definition->label, 'value.*' => $definition->label ],
            );

            if ( $validator->fails() ) {
                foreach ( $validator->errors()->all() as $message ) {
                    $errors[] = [ 'field' => (string) $key, 'code' => 'invalid', 'message' => $message ];
                }

                continue;
            }

            $normalized[ (string) $key ] = $value;
        }

        if ( [] !== $errors ) {
            throw new SettingsWriteException( $errors );
        }

        return $normalized;
    }

    /**
     * Refuses an unknown group or keys that are not allow-listed in it.
     *
     * @since 1.0.0
     *
     * @param  string             $group  Group key.
     * @param  array<int, string> $keys   Setting keys.
     *
     * @throws SettingsWriteException When the group or a key is unknown.
     *
     * @return void
     */
    protected function assertKeysInGroup( string $group, array $keys ): void
    {
        if ( ! $this->registry->hasGroup( $group ) ) {
            throw SettingsWriteException::field( null, 'unknown-group', __( 'Settings group ":group" does not exist.', [ 'group' => $group ] ) );
        }

        $errors = [];

        foreach ( $keys as $key ) {
            $definition = $this->registry->definition( $key );

            if ( null === $definition || $group !== $definition->group ) {
                $errors[] = [ 'field' => $key, 'code' => 'unknown-setting', 'message' => __( '":key" is not an editable setting in this group.', [ 'key' => $key ] ) ];
            }
        }

        if ( [] !== $errors ) {
            throw new SettingsWriteException( $errors );
        }
    }

    /**
     * Rules every value of the definition's type must pass.
     *
     * @since 1.0.0
     *
     * @param  SettingDefinition  $definition  Definition.
     *
     * @return array<int, mixed>
     */
    protected function typeRules( SettingDefinition $definition ): array
    {
        $options = array_map( 'strval', array_keys( $definition->options() ) );

        return match ( $definition->type ) {
            'string', 'text'             => [ 'string' ],
            'email'                      => [ 'string', 'email' ],
            'integer'                    => [ 'integer' ],
            'boolean'                    => [ 'boolean' ],
            'select'                     => [] === $options
                ? [ 'string', static fn ( string $attribute, mixed $value, Closure $fail ) => $fail( __( 'There is nothing to choose for :attribute yet.' ) ) ]
                : [ 'string', Rule::in( $options ) ],
            'multiselect', 'list', 'map' => [ 'array' ],
            'currency'                   => [ 'string', 'size:3', $this->currencyRule() ],
            'timezone'                   => [ 'string', 'timezone:all' ],
        };
    }

    /**
     * Rules for each entry of a list or map value.
     *
     * @since 1.0.0
     *
     * @param  SettingDefinition  $definition  Definition.
     *
     * @return array<int, mixed>
     */
    protected function itemRules( SettingDefinition $definition ): array
    {
        $options = array_map( 'strval', array_keys( $definition->options() ) );

        return match ( $definition->type ) {
            'multiselect' => [ 'string', ...( [] === $options ? [] : [ Rule::in( $options ) ] ), ...$definition->itemRules ],
            'list', 'map' => [ 'string', ...$definition->itemRules ],
            default       => [],
        };
    }

    /**
     * A rule accepting ISO 4217 codes only.
     *
     * @since 1.0.0
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function currencyRule(): Closure
    {
        return static function ( string $attribute, mixed $value, Closure $fail ): void {
            if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Z]{3}$/', $value ) || ! ( new ISOCurrencies() )->contains( new Currency( $value ) ) ) {
                $fail( __( ':attribute must be an ISO 4217 currency code.' ) );
            }
        };
    }

    /**
     * Forgets the cache now, then — once the outermost transaction commits —
     * re-reads the values, re-applies them to config, and runs `$hooks`.
     *
     * @since 1.0.0
     *
     * @param  Closure(): void  $hooks  Actions to fire.
     *
     * @return void
     */
    protected function afterWrite( Closure $hooks ): void
    {
        $this->flush();

        DB::afterCommit( function () use ( $hooks ): void {
            $this->flush();
            $this->refresh();
            $hooks();
        } );
    }

    /**
     * Whether a transaction is open whose commit or rollback could change
     * the rows (the transaction a test wraps around each case does not count).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected static function inTransaction(): bool
    {
        if ( app()->bound( 'db.transactions' ) ) {
            return app( 'db.transactions' )->callbackApplicableTransactions()->isNotEmpty();
        }

        return DB::transactionLevel() > 0;
    }
}
