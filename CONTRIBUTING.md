# Contributing

Thanks for looking at this. Issues and pull requests are welcome.

---

## Setup

```bash
git clone git@github.com:jcolombo/granola-api-php.git
cd granola-api-php
composer install
```

PHP 8.1+. No API key is needed to develop or to run the unit suite.

---

## Testing

Two suites, for two different questions.

### PHPUnit — does the SDK behave correctly?

```bash
composer test
```

Runs against fixtures in `tests/Fixtures/`, copied from the shapes in Granola's published OpenAPI document. No key, no network, safe in CI. This is the suite that has to stay green.

`tests/Support/MockApi.php` wires a `Granola` connection to canned Guzzle responses and records what was sent, so tests can assert on query parameters, request bodies and call counts:

```php
$api = MockApi::make(
    MockApi::fixture('notes.list.page1'),
    MockApi::fixture('notes.list.page2'),
);

$notes = Note::list($api->granola)->fetchAll();

self::assertCount(3, $notes);
self::assertSame(2, $api->requestCount());
self::assertSame('eyJvZmZzZXQiOjJ9', $api->queryAt(1)['cursor']);
```

Extend `GranolaTestCase` — it resets configuration, connections, cache, log and limiter between tests, so nothing leaks between cases.

### Live runner — is Granola still sending what it documents?

```bash
tests/validate                 # read-only
tests/validate --verbose       # show the records returned
tests/validate --write         # also create, pause and delete one endpoint
tests/validate --dry-run       # parse options, make no requests
```

No test framework, only the SDK and a real key. Set `testing.api_key` in a `granolaapi.config.json` override, or export `GRANOLA_API_KEY`; `--write` also needs `testing.webhook_url`.

Run it after a Granola API changelog entry, before a release, and when an integration starts behaving strangely. It also reports **fields Granola sent that this SDK does not model** — the earliest warning that the package has fallen behind.

---

## Conventions

**Match the surrounding code.** `declare(strict_types=1)` everywhere, typed properties and returns, constructor promotion, enums over string constants.

**Comments explain why, not what.** A comment that restates the line below it is noise; one that records a Granola API quirk, or why an obvious approach was rejected, is worth keeping. If a decision needed a paragraph, it belongs in `OVERRIDES.md`.

**No new runtime dependencies.** Guzzle and `adbario/php-dot-notation` are the whole list, and applications embedding this in a larger dependency tree benefit from it staying that way. Optional integrations go behind an interface the host application implements — see `Cache::registerCacheMethods()` and `Log::registerWriter()`.

**Stay framework-agnostic.** No PSR-7 dependency, no container, no framework service providers. `WebhookHeaders::fromAny()` is the pattern: accept what any framework can produce, depend on none of them.

**Forward compatibility is a feature.** Unknown response fields land in `unmapped()` rather than being dropped, and an unknown webhook event type parses with `type === null` instead of throwing. Granola adds things; a running integration should not break when they do.

**Record deliberate divergences.** If you depart from the sibling SDK family's patterns (`paymo-api-php`, `niftyquoter-api-php`, `ninety-api-php`, `optmyzr-api-php`, `leadfeeder-api-php`), add an `OVERRIDE-NNN` entry to `OVERRIDES.md` and reference it from the code with `@override`.

---

## Adding an endpoint

When Granola ships one:

1. **Read the OpenAPI document** at <https://docs.granola.ai/api-reference/openapi.json>. It is the source of truth for shapes, and the fixtures come from it.
2. **Add the resource** in `src/Entity/Resource/`, with `LABEL`, `API_PATH`, `OBJECT_TYPE`, `ID_PREFIX` and `PROP_TYPES`. Add `MUTABLE` only if the endpoint genuinely writes.
3. **Add the collection** in `src/Entity/Collection/`, with `RESULT_KEY` and, when the endpoint paginates, `PAGE_SIZE_CONFIG`. Override the typed accessors (`first`, `last`, `find`, `all`, `current`, `offsetGet`, `each`) so callers keep their types.
4. **Register both** in `default.granolaapi.config.json` under `classMap.entity`.
5. **Add a `list()` override** on the resource narrowing the return type to your collection.
6. **Add a fixture** taken from the OpenAPI examples, and tests covering hydration, pagination and at least one failure path.
7. **Document it** — README where it earns a place, `docs/API-REFERENCE.md` always, and an example if it introduces a new pattern.
8. **Changelog.**

---

## Pull requests

- One concern per PR.
- `composer test` green.
- Public API changes documented in the same PR.
- Changelog entry under `[Unreleased]`.
- Say what you actually verified. "Ran the unit suite; live runner not exercised, no key" is more useful than silence.

---

## Releasing

Maintainers:

```bash
bin/release 0.2.0           # tag locally
bin/release 0.2.0 --push    # tag and push
```

The script validates the version, refuses to run on a dirty tree, moves `[Unreleased]` into a dated section, commits, and creates an annotated `v<version>` tag. Packagist picks the tag up from the GitHub webhook.

Run `tests/validate` against the real API before tagging.

---

## Security

Do not open a public issue for a security problem — email <jc-dev@360psg.com>.

Signature verification is the part of this package where a bug is most costly. If you find one there, that route especially.
