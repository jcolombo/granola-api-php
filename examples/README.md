# Examples

Eight runnable scripts. All read-only unless noted.

```bash
composer install
export GRANOLA_API_KEY=grn_your_key_here

php examples/01-connect.php
```

`bootstrap.php` handles setup for every example: it merges any `granolaapi.config.json` beside the package root, takes the key from `GRANOLA_API_KEY` (or `connection.apiKey`), turns on `error.throwOnApiError` so failures are loud, and returns a connected `Granola`.

| Script | Shows | Writes? |
|--------|-------|---------|
| [01-connect.php](01-connect.php) | Connecting, connection reuse, safe-to-log fingerprints | no |
| [02-notes.php](02-notes.php) | Listing, filtering, and the summary-vs-detail split | no |
| [03-transcripts.php](03-transcripts.php) | Inline vs paged transcripts, speakers, timings, export | no |
| [04-folders.php](04-folders.php) | Rebuilding the hierarchy locally; folder-scoped note listing | no |
| [05-pagination-and-sync.php](05-pagination-and-sync.php) | `fetch`/`fetchNext`/`each`, and a cursor-resumable sync loop | no |
| [06-webhook-endpoints.php](06-webhook-endpoints.php) | Listing endpoints; optionally register → pause → delete | **with a URL argument** |
| [07-webhook-receiver.php](07-webhook-receiver.php) | A production-shaped receiver, plus an offline self-test | no |
| [08-configuration-and-keys.php](08-configuration-and-keys.php) | Config, two keys side by side, audit events, both error styles | no |

## The two that need more than a key

**06** lists endpoints with no arguments. Pass an HTTPS URL you control and it runs the whole lifecycle against Granola, creating and then deleting a real endpoint:

```bash
php examples/06-webhook-endpoints.php https://your-tunnel.example.com/granola-webhooks
```

**07** runs an offline self-test from the CLI — it signs a delivery locally, verifies it, parses it, then proves a tampered body is rejected and an unknown event type still parses:

```bash
php examples/07-webhook-receiver.php
```

To receive real deliveries, serve it and register the URL with 06:

```bash
export GRANOLA_WEBHOOK_SECRET=whsec_...
php -S 0.0.0.0:8080 examples/07-webhook-receiver.php
```

## If an example prints nothing

Granola's API only returns notes that already have a generated AI summary and transcript. A brand-new key on a quiet account can legitimately see zero notes; the scripts say so rather than failing.

Audit events additionally need a workspace API key on an Enterprise plan — 08 reports that cleanly instead of erroring.
