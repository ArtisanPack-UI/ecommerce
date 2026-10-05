# Satellite verification

Satellites prove they honour the engine's contracts by extending the
engine's abstract contract suites and running `ecommerce:verify-satellite`.
The command writes a JSON report. On each tag, CI signs a passing report and
the satellite's release job attaches it to the GitHub release, which is what
earns the "contract-verified" badge on the [satellites page](satellites.md).
Parent plan §15.2.

Verification is advisory. The engine never refuses to boot an unverified
satellite.

> **What a badge does and doesn't prove.** Verification is
> *author-attested*: the satellite's own CI runs its own contract tests and
> signs the report with the author's own key. A valid signature proves the
> report came from the key the author published and was not altered since —
> not that an independent party re-ran the suites. Treat the badge as "the
> author ran the engine's contract suites against this release", and read
> the report itself (`namespaces`, `allow_empty`, per-registration test
> counts) before trusting it for anything important.

## What the verifier does

1. **Reads your `composer.json`.** Your package name, version, and the
   PSR-4 namespaces under `autoload` tell the verifier which classes are
   yours. Directories under `autoload-dev` (or `tests/`) are scanned for
   contract tests.
2. **Discovers your registrations.** It boots your satellite through
   Orchestra Testbench and walks every engine registry and container
   binding. Every entry whose class lives in one of your namespaces is one
   of your registrations.
3. **Matches each registration to a contract test.** A registration is
   covered by any concrete class in your tests that extends the contract's
   abstract suite (directly or through your own abstract base class) and
   mentions the implementation — its class name or its registry key as a
   quoted string. If you register only one implementation of a contract,
   every subclass of that contract's suite covers it.
4. **Runs the matched tests** in a subprocess with your own
   `vendor/bin/pest` (or `vendor/bin/phpunit`) and reads the JUnit log.
5. **Writes the report** to `.ecommerce-verify-report.json` and exits
   non-zero unless the satellite is verified.

### Contracts and suites

| Contract | Found in | Suite to extend |
|---|---|---|
| `ProductType` | `ProductTypeRegistry` | `ProductTypeContractTest` |
| `PaymentGateway` | `PaymentGatewayRegistry` | `PaymentGatewayContractTest` |
| `ShippingRateProvider` | `ShippingRateProviderRegistry` | `ShippingRateProviderContractTest` |
| `ShippingLabelProvider` | `ShippingLabelProviderRegistry` | — (`no-suite`) |
| `ShippingMethodType` | `ShippingMethodTypeRegistry` | — (`no-suite`) |
| `TaxProvider` | `TaxProviderRegistry` | `TaxProviderContractTest` |
| `CurrencyRateProvider` | `CurrencyRateProviderRegistry` | — (`no-suite`) |
| `FraudProvider` | `FraudProviderRegistry` | `FraudProviderContractTest` |
| `FulfillmentAllocationStrategy` | `FulfillmentAllocationStrategyRegistry` | `FulfillmentAllocationStrategyContractTest` |
| `PromotionCondition` | `PromotionConditionRegistry` | `PromotionConditionContractTest` |
| `PromotionAction` | `PromotionActionRegistry` | `PromotionActionContractTest` |
| `KanbanCardWidget` | `KanbanCardWidgetRegistry` | `KanbanCardWidgetContractTest` |
| `KanbanAutomationTrigger` | `KanbanAutomationRegistry` | `KanbanAutomationTriggerContractTest` |
| `NotificationTemplate` | `NotificationTemplateRegistry` | `NotificationTemplateContractTest` |
| `SearchProvider` | `SearchProviderRegistry` | `SearchProviderContractTest` |
| `SearchIndexer` | `SearchIndexerRegistry` | — (`no-suite`) |
| `CartStorage` | container binding | `CartStorageContractTest` |
| `OrderNumberGenerator` | container binding | `OrderNumberGeneratorContractTest` |
| `ReviewModerator` | container binding | `ReviewModeratorContractTest` |

The engine ships fifteen suites in `ArtisanPackUI\Ecommerce\Testing\Contracts\`
(see [contracts.md](contracts.md#contract-test-suites) for what each one
checks). A contract without a shared suite reports `no-suite`; that never
fails verification. The table above is
[`ContractSuiteMap`](../src/Testing/Verification/ContractSuiteMap.php).

### Statuses

| Status | Meaning | Fails verification? |
|---|---|---|
| `passed` | The matched tests ran, at least one wasn't skipped, and none failed. | No |
| `failed` | A matched test failed or errored, every matched test was skipped, or the runner couldn't run. | Yes |
| `missing` | The contract has a suite, but none of your tests extends it for this implementation. | Yes |
| `no-suite` | The engine ships no shared suite for this contract yet. | No |
| `not-run` | Discovery-only run (`--no-run`). | n/a |

A satellite that registers nothing is unverified unless you pass
`--allow-empty` (UI-only satellites).

## Setup

Your satellite needs `artisanpack-ui/ecommerce` and
`orchestra/testbench` (dev). Make sure your `testbench.yaml` boots both the
engine's provider and yours:

```yaml
providers:
  - ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider
  - Acme\EcommerceShippo\ShippoServiceProvider
```

Add the Composer script:

```json
{
  "scripts": {
    "ecommerce:verify-satellite": "ecommerce-verify-satellite"
  }
}
```

`ecommerce-verify-satellite` is a Composer bin the engine ships. It runs
`vendor/bin/testbench ecommerce:verify-satellite --path=<cwd>` and passes
any other arguments through.

## Writing contract tests

Extend the suite for each contract you implement and fill in its abstract
methods:

```php
namespace Acme\EcommerceShippo\Tests\Contracts;

use Acme\EcommerceShippo\ShippoRateProvider;
use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ShippingRateProviderContractTest;

final class ShippoRateProviderContractTest extends ShippingRateProviderContractTest
{
    protected function provider(): ShippingRateProvider
    {
        return new ShippoRateProvider( $this->fakeShippoClient() );
    }
}
```

If you ship several implementations of the same contract, each test class
must mention the implementation it covers — by class (as above) or by
registry key (`->get( 'shippo-ground' )`).

## Running it

```bash
composer ecommerce:verify-satellite
```

| Option | Purpose |
|---|---|
| `--path=` | Satellite root (defaults to the working directory). |
| `--output=` | Report path (default `.ecommerce-verify-report.json`, config `artisanpack.ecommerce.satellites.verification.report_path`). |
| `--package-version=` | Version to record, e.g. the git tag. Defaults to `composer.json`'s `version`, else `dev`. |
| `--namespace=*` | Extra namespace prefixes that belong to the satellite. |
| `--tests=*` | Extra directories to scan for contract tests. |
| `--provider=*` | Service providers to register before discovery (if `testbench.yaml` doesn't). |
| `--no-run` | Discovery only: list registrations and matched tests. Fails only if a registration is `missing`. |
| `--allow-empty` | A satellite with no registrations counts as verified. |
| `--sign` | Sign a passing report with `ECOMMERCE_VERIFY_SIGNING_KEY`. |
| `--signature=` | Signature path (default `verify-report.sig` next to the report). |
| `--check-signature` | Check an existing report + signature instead of running. Uses `--public-key=` or `ECOMMERCE_VERIFY_PUBLIC_KEY`. |
| `--record` | Store a passing report's sha256 on the satellite's `ecommerce_satellites.verified_report_hash` (the satellite must be registered in the running app; see [satellite-lifecycle.md](satellite-lifecycle.md)). |

Add `.ecommerce-verify-report.json` and `verify-report.sig` to your
`.gitignore`.

## Report format

```json
{
    "schema": 1,
    "package": "acme/ecommerce-shippo",
    "version": "v1.2.0",
    "engine_version": "1.0.0",
    "generated_at": "2026-09-29T12:00:00Z",
    "php_version": "8.4.3",
    "namespaces": [ "Acme\\EcommerceShippo\\" ],
    "ran": true,
    "allow_empty": false,
    "runner_error": null,
    "registrations": [
        {
            "contract": "ArtisanPackUI\\Ecommerce\\Contracts\\ShippingRateProvider",
            "key": "shippo",
            "class": "Acme\\EcommerceShippo\\ShippoRateProvider",
            "suite": "ArtisanPackUI\\Ecommerce\\Testing\\Contracts\\ShippingRateProviderContractTest",
            "test_classes": [ "Acme\\EcommerceShippo\\Tests\\Contracts\\ShippoRateProviderContractTest" ],
            "status": "passed",
            "tests": 8,
            "assertions": 31,
            "failures": 0,
            "skipped": 0,
            "error": null
        }
    ],
    "summary": { "registrations": 1, "passed": 1, "failed": 0, "missing": 0, "no-suite": 0, "not-run": 0 },
    "verified": true
}
```

`error` explains a `failed` row the verifier could not even test (for
example a container binding whose class could not be determined).
`namespaces` lists the namespace prefixes that decided which registrations
count as the satellite's (from its `composer.json`, plus any `--namespace`
flags), and `allow_empty` records whether `--allow-empty` let a satellite
with no registrations pass. Both are part of the signed bytes.

The SHA-256 of the report's exact bytes is printed after every run. It is
the value recorded as `ecommerce_satellites.verified_report_hash`. A recorded hash covers
one release: when the satellite's version changes, the next registry sync
clears it until the new version is verified and recorded again.

## Signing

Reports are signed with an Ed25519 detached signature (libsodium). The
signature file is JSON:

```json
{
    "schema": 1,
    "algorithm": "ed25519",
    "report_sha256": "…",
    "key_id": "…",
    "public_key": "…",
    "signature": "…"
}
```

Anyone checking a signature must use the public key they trust, not the one
embedded in the file. The embedded key only tells a reader which key signed.

Generate a keypair once:

```bash
php -r '$k = sodium_crypto_sign_keypair(); echo "secret: ", base64_encode(sodium_crypto_sign_secretkey($k)), "\npublic: ", base64_encode(sodium_crypto_sign_publickey($k)), "\n";'
```

- Store the **secret** as the `ECOMMERCE_VERIFY_SIGNING_KEY` repository (or
  organization) secret. Never commit it. A 32-byte seed also works.
- Publish the **public** key with your satellite's listing on the
  [satellites page](satellites.md).

In CI, sign with `ecommerce-sign-report [report] [signature]` rather than
`--sign`. It reads the key from `ECOMMERCE_VERIFY_SIGNING_KEY` and loads only
the engine's `ReportSigner` (no autoloader, no service providers, no satellite
or dependency code). It refuses to sign a report whose `verified` flag isn't
`true`. `--sign` remains for local use.

Running the signer in a separate process is not enough on its own. On the
runner that ran `composer install` and your tests, a dependency or Composer
plugin could have changed `vendor/bin/ecommerce-sign-report` or read the key
from the environment. The [reusable workflow](#ci) avoids that by signing in
a separate job on a fresh runner, with a copy of the signer checked out from
the engine repository. If you build your own pipeline, do the same: give the
key only to a job that never installs or runs satellite code.

Check a signature locally:

```bash
vendor/bin/testbench ecommerce:verify-satellite --check-signature --public-key=<base64>
```

## CI

The engine ships a reusable workflow at
[`.github/workflows/verify-satellite.yml`](../.github/workflows/verify-satellite.yml)
(`workflow_call` only, so it never runs on the engine's own pushes). Copy
[`stubs/workflows/verify-satellite.yml`](../stubs/workflows/verify-satellite.yml)
into your satellite as `.github/workflows/verify-satellite.yml`:

```yaml
on:
  pull_request:
  push:
    tags:
      - 'v*'

jobs:
  verify:
    uses: ArtisanPack-UI/ecommerce/.github/workflows/verify-satellite.yml@v1.0.0
    permissions:
      contents: read
    with:
      php-version: '8.4'
    secrets:
      ECOMMERCE_VERIFY_SIGNING_KEY: ${{ secrets.ECOMMERCE_VERIFY_SIGNING_KEY }}

  release:
    needs: verify
    if: ${{ startsWith(github.ref, 'refs/tags/') && needs.verify.outputs.signed == 'true' }}
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      - uses: actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093 # v4
        with:
          name: ecommerce-verify-signature
          path: verification

      - uses: softprops/action-gh-release@v2
        with:
          files: |
            verification/.ecommerce-verify-report.json
            verification/verify-report.sig
```

Keep the reference pinned to an engine release tag (or a commit SHA). The
job passes the workflow your signing key, so never point it at a moving
branch such as `@main`. The reusable workflow SHA-pins every third-party
action it uses.

### How the workflow runs

The reusable workflow has two jobs, so the signing key never shares a runner
with your satellite's code:

1. **`verify`** runs on every call, with no secrets and read-only
   permissions. It checks out your repository, runs `composer install`, and
   runs `vendor/bin/ecommerce-verify-satellite --package-version=<ref name>`
   plus `extra-args`. It then uploads `.ecommerce-verify-report.json` as the
   `ecommerce-verify-report` artifact, even when verification fails.
2. **`sign`** runs only on tag refs, and only after `verify` succeeds. A
   report that isn't verified fails `verify`, so it's never signed. On a fresh
   runner, `sign` downloads only the report artifact and does a sparse checkout
   of the engine at the exact commit of the workflow file
   (`bin/ecommerce-sign-report` and `ReportSigner.php`, with no
   `composer install`). It then runs `php engine/bin/ecommerce-sign-report`
   with `ECOMMERCE_VERIFY_SIGNING_KEY`, and uploads the report and
   `verify-report.sig` as the `ecommerce-verify-signature` artifact. With no
   key passed, it logs a notice and skips signing.

The workflow's `signed` output is `true` when the
`ecommerce-verify-signature` artifact exists.

The workflow never creates or edits a GitHub release. Under immutable
releases, a release it created would lock before your own release step could
attach anything. Instead, the stub's `release` job downloads the
`ecommerce-verify-signature` artifact and attaches both files when it creates
the release. If you already have a release workflow, move that job's two
steps into it and make it `needs: verify`.

| Input | Default | Purpose |
|---|---|---|
| `php-version` | `8.4` | PHP version for the `verify` job. |
| `php-extensions` | `dom, curl, libxml, mbstring, zip, pdo, sqlite, pdo_sqlite` | Extensions to install (`intl` and `sodium` are always added). |
| `working-directory` | `.` | Satellite root in a monorepo. |
| `extra-args` | `''` | Extra verifier arguments, e.g. `--allow-empty`. |
| `upload-release-assets` | `false` | **Deprecated and ignored.** Setting it only logs a warning. Attach the `ecommerce-verify-signature` artifact from your own release step instead. |

| Secret | Required | Purpose |
|---|---|---|
| `ECOMMERCE_VERIFY_SIGNING_KEY` | No | Base64 Ed25519 secret key. Only the `sign` job receives it. |

| Output | Purpose |
|---|---|
| `signed` | `true` when a signature was produced. |

## Building on the verifier

All the logic lives in `ArtisanPackUI\Ecommerce\Testing\Verification\`:
`SatelliteManifest`, `RegistrationDiscovery`, `TestClassLocator`,
`SuiteRunner` (`ProcessSuiteRunner`), `SatelliteVerifier`,
`VerificationReport`, and `ReportSigner`. The console command is a thin
shell around `SatelliteVerifier`, and binding your own `SuiteRunner` in the
container swaps out the subprocess runner.
