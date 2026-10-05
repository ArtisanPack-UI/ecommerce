# Digital delivery and license keys

Digital products deliver files, license keys, or both (parent plan §5.13).

## Files and entitlements

| Table | Holds |
|---|---|
| `digital_files` | A deliverable attached to a product or a variant. Its bytes live on a filesystem disk (`disk` + `path`), or in the media library (`media_id`, resolved when `artisanpack-ui/media-library` is installed). `is_streaming_only` files can be streamed but never downloaded. |
| `digital_downloads` | One entitlement for an order line and file: `downloads_remaining` (null means unlimited), `expires_at`, `download_count`, and first / last download times. |
| `digital_download_events` | An audit row for every `download`, `stream`, and `forbidden` hit, with the IP and user agent. `ecommerce:prune-ledgers` deletes rows older than `retention.download_events_days` (default 365). |

An order line is entitled to its variant's files plus the product's product-wide
(variant-less) files. Archived files are left out of new entitlements.

### Issuing

With `artisanpack.ecommerce.digital.auto_issue` on (the default), the engine
issues entitlements when `ap.ecommerce.payment.succeeded` fires. Each one
gets `digital.download_limit` downloads (default 5) and lasts
`digital.download_expiry_days` days (default 30). Set either to 0 to remove
that cap. A product can override both in its meta, where 0 also means no cap:

```json
{ "digital": { "download_limit": 3, "download_expiry_days": 30 } }
```

The customer then gets the `digital.download-ready.customer` email
with their links and license keys. Issuing is idempotent per order line and
file, and runs under a lock on the order, so a replayed or concurrent payment
webhook doesn't issue anything twice.

With `digital.revoke_on_refund` on (the default), a full refund
(`ap.ecommerce.order.refunded` leaving `payment_status` at `refunded`) or a
cancellation expires the order's downloads and revokes its license keys.
Partial refunds keep access.

To issue manually:

```php
$download = app( DigitalDownloadService::class )->issue( $orderItem, $file, downloads: 3, expiresAt: now()->addWeek() );
$download->plainToken; // put this in the link — it is never stored
```

### Tokens

Download links carry **opaque, server-issued tokens**, not Laravel signed
URLs. A signed URL proves nobody tampered with it, but it can't spend a
download from a quota atomically. Each token is 64 random characters. Only its
sha256 is stored, so a leaked database contains no working links. The plain
token exists once, in the link sent to the customer.

### Endpoints

| Method | Path | Behaviour |
|---|---|---|
| GET | `downloads/{token}` | Spends one download and sends the file as an attachment. Streaming-only files get 403. |
| GET | `downloads/{token}/stream` | Streams the file inline and honours `Range: bytes=…` (206 Partial Content; 416 when unsatisfiable). Only the first request of a stream spends a download: one with no `Range`, or a range starting at byte 0. Later range requests are free while the stream is fresh: within `digital.stream_window_minutes` (default 240) of that first request, and up to `digital.stream_byte_allowance` (default 3) times the file size in total. After that the player has to start a new stream. |

Both endpoints use the `ecommerce.catalog.read` rate policy. Redeeming a token
works like this:

1. Lock the entitlement row (`SELECT … FOR UPDATE`).
2. Check `expires_at` and the quota, and check that the file exists. A
   download is never spent on a missing file.
3. Spend the download with a conditional update
   (`… WHERE downloads_remaining > 0`).
4. Record the event.

If two browser tabs race for the last download, exactly one gets the file and
the other gets 410. Refusals are `problem+json` responses:

- `download-not-found` (404)
- `download-expired` (410)
- `download-limit-reached` (410)
- `download-streaming-only` (403)
- `download-file-missing` (404)
- `download-not-started` (416): a range continuation with no fresh stream
- `download-stream-exhausted` (410): the stream's byte allowance is spent

`HEAD` requests (which some mail link scanners send) get a body-less 200 /
404 / 410 and never spend a download. Scanners that prefetch with `GET` still
can. If that's a problem for your customers, edit
`digital.download-ready.customer` to link to your account page and let the
customer click through from there.

The file is always read from its disk and piped through PHP. Neither
endpoint exposes its disk path or a storage URL, which is how a
streaming-only file never leaks a direct link.

### From the account area

A signed-in shopper can reach their files without the emailed link.
`DigitalDownloadService::forCustomer( $customer )` lists the entitlements on
the customer's orders, and `redeemOwned( $customer, $downloadId, $mode, $request )`
redeems one by id, after checking that it belongs to that customer.

| Method | Path | Behaviour |
|---|---|---|
| GET | `me/downloads` | The customer's entitlements. Include: `file`, `order_item`. Sorts: `created_at`, `expires_at`. |
| GET | `me/downloads/{download}` | Like `downloads/{token}`: spends one download and sends the file. |
| GET | `me/downloads/{download}/stream` | Like `downloads/{token}/stream`, with the same `Range` rules. |

These routes need a signed-in shopper and apply the same limits, expiry, and
refusal codes as the token links. Another customer's download id gets
`download-not-found` (404).

### Hooks

| Hook | Use |
|---|---|
| `ap.ecommerce.digital.tokenIssued` | After an entitlement is created. |
| `ap.ecommerce.digital.downloading` | Filters `{ mode, disk, path, filename, headers }` before the first byte, e.g. to swap in a personalised copy. |
| `ap.ecommerce.digital.streamWatermark` | Filters the `StreamedResponse` of every stream, so a satellite can wrap the byte stream (PDF stamping, video watermarking). It must return a `StreamedResponse`. |
| `ap.ecommerce.digital.downloaded` | After the last byte is written. |
| `ap.ecommerce.digital.productUpdated` | When a file's `version` changes from one value to another (plus the `DigitalProductUpdated` event). Setting a first version on a file that had none isn't an update. Everyone who bought the file gets `digital.product-updated.customer`. |

### Admin API

| Method | Path | Ability |
|---|---|---|
| GET | `admin/digital-files` | `ecommerce.digitalFile.viewAny`. Filters: `product_id`, `product_variant_id`, `is_streaming_only`. |
| POST | `admin/digital-files` | `ecommerce.digitalFile.create` |
| PATCH | `admin/digital-files/{file}` | `ecommerce.digitalFile.update` |
| DELETE | `admin/digital-files/{file}` | `ecommerce.digitalFile.delete` |

A file that any customer has an entitlement for can't be deleted: the delete
gets 409 `digital-file-in-use`. Archive it instead with
`PATCH { "is_archived": true }`. That sets `archived_at`, keeps existing
downloads working, and stops issuing the file to new orders. Send `false` to
restore it.

The resource shows `disk` and `path` to admins only. Customers never see them.

`disk` must be one of `digital.allowed_disks` (default: the `digital.disk`
disk only), so an admin can't attach, and then download, a file from any
other disk. `path` must be relative and stay inside the disk (no `..`).

## License keys

| Table | Holds |
|---|---|
| `license_keys` | A key such as `K7QM2-XW9RT-4HJ8P-LMN3Q-ZX2CV` (no 0/O or 1/I look-alikes) issued for an order line, with `activations_limit` (null means unlimited), `activations_count`, `expires_at`, and a revocation flag. |
| `license_activations` | One row per machine fingerprint the key has been validated from. |

A product issues keys when its `meta.licensing.enabled` is `true`:

```json
{ "licensing": { "enabled": true, "activations_limit": 5, "expires_in_days": 365 } }
```

Missing values fall back to `artisanpack.ecommerce.licenses.*`. The line's
quantity multiplies the activation limit, so 3 seats × 5 gives 15 machines.
Keys are issued on `ap.ecommerce.payment.succeeded`, alongside the downloads,
and listed in the same email.

Keys are stored encrypted, next to a `key_hash` (an HMAC-SHA256 of the
normalised key under `app.key`). Lookups go through `key_hash`, so a leaked
database doesn't reveal working keys. Keys hashed under a key listed in
`app.previous_keys` are still found after you rotate `APP_KEY`, and are
re-hashed on the next lookup. Use `LicenseService::findByKey( $key )` to look
one up.

### Validation

`POST license/validate` is public. It needs an Idempotency-Key and uses the
`ecommerce.license.validate` rate policy: 60 per minute per key and 600 per
minute per IP. The key is normalised (trimmed and upper-cased) before it is
counted, so changing its case doesn't reset the limit.

```http
POST /api/ecommerce/v1/license/validate
{ "key": "K7QM2-XW9RT-4HJ8P-LMN3Q-ZX2CV", "fingerprint": "sha256-of-machine-id" }
```

```json
{ "data": { "valid": true, "expires_at": null, "product": { "id": 12, "name": "Pro Plugin" }, "revoked": false, "reason": null } }
```

- The key is looked up by its `key_hash`, and the decrypted key is
  re-checked with `hash_equals()`. Keys have about 125 bits of entropy, and validation is
  rate-limited per key and per IP, so they can't be guessed.
- Fingerprints are trimmed and lower-cased, so the same machine always matches
  its activation.
- A fingerprint the key hasn't seen before takes an activation slot, under a
  lock on the key row. Machines racing for the last slot can't go over
  `activations_limit`. A new activation fires `ap.ecommerce.license.activated`
  and `LicenseActivated`, and the customer gets `license.activated.customer`.
  That email identifies the key by its last group only.
- `expires_at` is an RFC 3339 UTC timestamp, or `null` for a key that
  never expires.
- When `valid` is false, `reason` is one of `not-found`, `revoked`, `expired`,
  or `activation-limit-reached`.
- The response runs through `ap.ecommerce.license.validating`
  `(array $result, string $key, string $fingerprint)`.

### Deactivation

When a customer moves to a new machine, their app frees the old slot:

```http
POST /api/ecommerce/v1/license/deactivate
{ "key": "K7QM2-XW9RT-4HJ8P-LMN3Q-ZX2CV", "fingerprint": "sha256-of-old-machine-id" }
```

```json
{ "data": { "deactivated": true, "activations_count": 2, "activations_limit": 5, "reason": null } }
```

It uses the same rate policy as validation and needs an Idempotency-Key.
When nothing was freed, `reason` is `not-found` (no such key) or
`not-activated` (the machine had no activation). Revoked and expired keys can
be deactivated too. A freed slot fires `ap.ecommerce.license.deactivated` and
`LicenseDeactivated`. In code, call `LicenseService::deactivate( $license, $fingerprint )`
or `deactivateByKey( $key, $fingerprint )`.

### The customer's keys

`GET me/license-keys` lists the license keys on the signed-in customer's
orders, newest first (`LicenseService::forCustomer()`). Include:
`activations`, `order_item`. The owner sees the full key. `meta` is shown to
admins only.

### Admin API

| Method | Path | Ability |
|---|---|---|
| GET | `admin/license-keys` | `ecommerce.licenseKey.view`. Filters: `key`, `order_item_id`, `is_revoked`. Include: `activations`. |
| POST | `admin/license-keys/{key}/revoke` | `ecommerce.licenseKey.revoke`. Body: `{ reason? }`. |

After revocation, validation returns `revoked: true`. The revocation also
fires `ap.ecommerce.license.revoked` and `LicenseRevoked`.
