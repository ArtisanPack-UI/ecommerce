# Upgrade guide

This guide lists every breaking change between releases of
`artisanpack-ui/ecommerce` and what you need to do about each one. Minor and
patch releases don't need any action; they're listed in
[CHANGELOG.md](../CHANGELOG.md).

What counts as a breaking change: a change to a public contract signature
([contracts.md](contracts.md)), a hook name or its arguments
([hooks.md](hooks.md)), an event's properties ([events.md](events.md)), a
REST/GraphQL request or response shape ([api.md](api.md)), a config key, or a
migration that changes an existing column.

## 1.0.0

Initial release. There's nothing to upgrade from.

---

<!--
Template for future entries. Copy it above the 1.0.0 section, newest first.

## Upgrading from X.Y to X+1.0

**Estimated effort:** low | medium | high

### High-impact changes

#### <Short title>

**Affects:** who is affected (store owners, satellite authors, API clients).

**What changed:** …

**What to do:**

```php
// before
// after
```

### Medium-impact changes

### Low-impact changes

### Database

- New migrations: run `php artisan migrate`. Note any long-running or locking migrations here.

### Deprecations

- `old_name` → `new_name` (removed in X+2.0).
-->
