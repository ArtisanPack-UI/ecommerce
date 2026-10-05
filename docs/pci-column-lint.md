# PCI column lint

The `ecommerce:lint:pci-columns` Artisan command is the build-time enforcement
mechanism for the engine's SAQ-A commitment: raw cardholder data must never
touch the engine's own storage. It scans every migration file recursively for
column-name substrings that indicate cardholder data would be persisted, and
fails the build on any hit.

See engine spec §11.1.

## What is scanned

By default the command scans:

- The engine's own `database/migrations/` directory (bundled with the package).
- The consumer application's `database_path( 'migrations' )` when the command
  is invoked from a full Laravel application.
- Any additional directories passed via one or more `--path=` options
  (relative paths are resolved against `base_path()`).

Scanning is recursive — nested subdirectories are covered.

## Forbidden substrings

Matching is case-insensitive and only inspects string literals (single- or
double-quoted) on each line, so common English words that happen to contain a
forbidden substring (e.g. `company` containing `pan`) do not trigger. Within a
literal, a substring matches when it appears as a full snake_case token —
either the entire literal, or bounded by underscores on both sides.

Only column names that appear as a plain single-line string literal are
covered — dynamic names built from PHP variables, string concatenation, or
heredoc/nowdoc blocks are out of scope and will not be flagged. Reviewers
should reject those constructs during code review; the lint is the automated
belt around that manual suspender.

The forbidden substrings are:

- `card_number`
- `cardnumber`
- `pan`
- `cvv`
- `cvc`
- `card_cvc`
- `card_cvv`
- `card_expiry`
- `cardholder`
- `raw_card`
- `card_track`
- `magstripe`

## Running locally

```bash
php artisan ecommerce:lint:pci-columns
```

To scan additional directories (e.g. a satellite's migrations folder):

```bash
php artisan ecommerce:lint:pci-columns --path=packages/my-satellite/database/migrations
```

## CI enforcement

Every pull request runs the `PCI Column Lint` GitHub Actions workflow
(`.github/workflows/pci-lint.yml`). A single hit fails the build and blocks
merge until the offending column is renamed, removed, or explicitly allow-listed.

## Removing a false positive

If a match is a genuine false positive (for example a legacy read-only import
mirror scheduled for removal, or a column whose name coincidentally contains a
forbidden substring), append an inline annotation to the offending line:

```php
$table->string( 'legacy_pan_reference' ); // pci-lint:ignore reason: read-only import mirror, drop after 2026-Q4 backfill
```

Rules:

- The annotation must be on the **same line** as the match.
- The reason string is **required** and must be non-empty.
- Every allow-listed line is logged in the build output as
  `pci-lint:ignore <file>:<line> [<match>] reason: <text>`, so reviewers can
  audit the exceptions.

Annotations with a missing or empty reason string are treated as violations
and fail the build.
