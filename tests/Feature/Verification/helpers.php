<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Testing\Verification\SatelliteManifest;
use ArtisanPackUI\Ecommerce\Testing\Verification\SuiteRunner;
use ArtisanPackUI\Ecommerce\Testing\Verification\SuiteRunResult;

if ( ! function_exists( 'verificationTempDir' ) ) {
    /**
     * Creates a throwaway satellite directory with the given files.
     *
     * @param  array<string, string>  $files  Relative path → contents.
     */
    function verificationTempDir( array $files = [] ): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecommerce-verify-test-' . bin2hex( random_bytes( 6 ) );

        mkdir( $dir, 0755, true );

        foreach ( $files as $relative => $contents ) {
            $path = $dir . DIRECTORY_SEPARATOR . $relative;

            if ( ! is_dir( dirname( $path ) ) ) {
                mkdir( dirname( $path ), 0755, true );
            }

            file_put_contents( $path, $contents );
        }

        return (string) realpath( $dir );
    }
}

if ( ! function_exists( 'fakeSuiteRunner' ) ) {
    /**
     * A {@see SuiteRunner} that returns canned results and records calls.
     *
     * @param  array<string, array{tests: int, assertions: int, failures: int, skipped: int}>  $classes
     */
    function fakeSuiteRunner( array $classes = [], ?string $error = null ): SuiteRunner
    {
        return new class( $classes, $error ) implements SuiteRunner {
            /** @var array<int, array<int, string>> */
            public array $calls = [];

            /**
             * @param  array<string, array{tests: int, assertions: int, failures: int, skipped: int}>  $classes
             */
            public function __construct( private readonly array $classes, private readonly ?string $error )
            {
            }

            public function run( SatelliteManifest $manifest, array $testClasses ): SuiteRunResult
            {
                $this->calls[] = $testClasses;

                return new SuiteRunResult( array_intersect_key( $this->classes, array_flip( $testClasses ) ), $this->error );
            }
        };
    }
}

if ( ! function_exists( 'taxSatelliteFiles' ) ) {
    /**
     * A fake satellite that "owns" the engine's Tax namespace and ships a
     * contract test for ManualTaxProvider.
     *
     * @return array<string, string>
     */
    function taxSatelliteFiles( bool $withTest = true ): array
    {
        $files = [
            'composer.json' => json_encode( [
                'name'         => 'acme/ecommerce-tax-fixture',
                'version'      => '1.2.3',
                'autoload'     => [ 'psr-4' => [ 'ArtisanPackUI\\Ecommerce\\Tax\\' => 'src/' ] ],
                'autoload-dev' => [ 'psr-4' => [ 'Acme\\Tests\\' => 'tests/' ] ],
            ] ),
        ];

        if ( $withTest ) {
            $files['tests/ManualTaxProviderTest.php'] = <<<'PHP'
<?php

namespace Acme\Tests;

use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\TaxProviderContractTest;

class ManualTaxProviderTest extends TaxProviderContractTest
{
    protected function provider(): ManualTaxProvider
    {
        return new ManualTaxProvider();
    }
}
PHP;
        }

        return $files;
    }
}
