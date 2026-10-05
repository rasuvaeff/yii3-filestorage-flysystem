# AGENTS.md — yii3-filestorage-flysystem

Guidance for AI agents working on this package. Read before changing code.

## What this is

The Flysystem-backed physical store for `rasuvaeff/yii3-filestorage`: S3, GCS,
Azure, FTP, anything with an adapter. Namespace
`Rasuvaeff\Yii3FilestorageFlysystem`.

Public API: `FlysystemStore` (`StoreUrlProviderInterface` +
`MaintenanceStoreInterface`), `FlysystemContentAddressableStore`
(adds `ContentAddressableStoreInterface`), `AdapterSemantics`,
`Url\TemporaryUrlOptionsInterface` with `Url\S3TemporaryUrlOptions`.
`Stream\StreamWrapper` is `@internal`.

DI wiring: `config/di.php` binds `StoreInterface` and nothing else. It must
**not** bind `StorageInterface` (core's), `RepositoryInterface` (`-db`'s),
`FilesystemOperator` or `TemporaryUrlOptionsInterface` — the last two are
application configuration, and `yiisoft/config` allows one vendor package per
key.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `make build`. For anything touching URLs, uploads or dedup, also
   `make test-integration` against real MinIO — the in-memory adapter has no
   URL generator, no multipart upload and no signatures, so a green unit suite
   proves nothing about S3.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never claim a capability the adapter cannot honour.** This package's whole
   design is that one class wraps every adapter and reports capability as a
   *result*: a null URL, an explicit `AdapterSemantics`, an absent `Range`
   interface. Adding an interface this store cannot honour for every adapter —
   or a runtime flag that switches one on — puts the lie inside the type system,
   where the caller cannot see it.
4. **Preserve the public contract.** Update `README.md` **and `README.ru.md`**,
   `llms.txt` and the tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
make build
make test-integration   # always resolves an endpoint; needs a reachable MinIO
make cs-fix
make psalm
make test
make mutation
make release-check
```

MinIO for the integration suite and the S3 example is gone: the images were
deleted from Docker Hub in September 2026 and quay.io pulls now require
authentication (the community edition is archived, the commercial successor
needs a license). SeaweedFS — a real S3 gateway, Apache-2.0 — replaces it:

```bash
docker run -d --name weed -p 8333:8333 \
  chrislusf/seaweedfs@sha256:4e61d15fd35994cb1e43e1e553dff106794841fd9a99ade2fc8c8bfce4d7872d \
  server -dir=/data -s3
until curl -fs http://127.0.0.1:8333/ >/dev/null; do sleep 1; done
FILESTORAGE_S3_ENDPOINT=http://127.0.0.1:8333 make test-integration
```

The gateway runs authless without an s3 config, so the default
`minioadmin`/`minioadmin` credentials the Makefile supplies are accepted
as-is.

`make test-integration` supplies the four `FILESTORAGE_S3_*` variables itself,
defaulting to that container, and runs with `--network host` — the suite talks
to the host's `127.0.0.1:9000`, which is not the container's. Override any of
them from the environment to point at something else. Because `make` always
supplies an endpoint, it never skips: with no MinIO reachable there, the S3
client fails to connect inside `#[BeforeTest]` and Testo reports the affected
tests **aborted**, not skipped.

**Skipping is a bare `composer test:integration` behaviour.** Run directly,
without `make` and with no `FILESTORAGE_S3_*` variable set, `FlysystemStore` is
never constructed and every test throws `SkipTest`, which Testo reports as
**skipped**. A skipped run is not a passing run, and Testo says which is which:
if you see `5 passed`, MinIO was really reached; if you see `5 skipped`,
nothing was tested. Check the word before believing the colour.

`composer.lock` is gitignored (library).

## Mutation testing

`minMsi` is **88, and no mutator is ignored** — 202 of 227 mutants killed. Every
fault the store distinguishes has a test that breaks it that specific way:
missing vs. unreachable vs. present-but-unreadable, a declared size trusted
over a re-measurement, and the reuse cap's own boundary distinct from the
pre-write one. The survivors are the groups below, none of which a test can
kill without inventing a state the code cannot reach:

| Group | Example | Why no test kills it |
|---|---|---|
| Null-safe operators in the stream wrapper | `$this->stream?->read(...)` | PHP calls `stream_open()` before any other method and refuses the handle if it returns false, so `$stream` is never null when the others run. The null-safety is there because the property must be typed nullable, not because the branch happens |
| Floors and clamps | `max(0, $written)` around a size Flysystem already reports as non-negative | Guards a value the source cannot produce; removing the floor changes nothing observable |
| The pre-write byte check | `$maxBytes > 0 && $declared !== null && $declared > $maxBytes`, and its `throw`, in both stores | Deleting it does not let an oversized upload through — the post-write verification catches the same body, with the same message and the same end state. What the pre-check saves is bandwidth, and no assertion can see bandwidth |
| A redundant `rewind()` | `measure()`'s own `$stream->rewind()` | `Upload::stream()` already rewinds on every call, so nothing downstream can ever observe whether `measure()` also did |
| Fields no caller reads | `'dev'` in the stat array; `use_include_path` in the `fopen()` call | `stream_stat()`'s own comment says only `size` matters to anything downstream; a custom stream-wrapper protocol never consults the include path either |
| A nullsafe property read | `$this->semantics?->orderedListing` in `objects()` | Same shape as the stream wrapper's null-safe calls: reading a property through `->` on a null object is a PHP warning, not a fatal error, and evaluates to null either way |

The `return null` inside each URL `catch` (`publicUrl()`, `temporaryUrl()`) is
the same shape: falling through lands on a `$url === '' ? null : $url` over an
unassigned variable, which is also null.

## Invariants & gotchas

- **Flysystem 3's URL methods are `@method` docblock annotations** on
  `FilesystemReader` — "Will be added in 4.0" — not interface declarations.
  `Filesystem` and `MountManager` have them; a hand-rolled operator or a
  decorator written against the interface may not, and calling one there is a
  fatal `Call to undefined method`, not a catchable exception. Hence
  `operatorHas()` before every URL call. When the family moves to Flysystem 4,
  that probe can go — and only then.
- **An empty-string URL is not a URL.** Passing one through hands the caller a
  link to the current page.
- **No `RangeReadableStoreInterface`, deliberately.** `readStream()` returns the
  whole object and `LimitedStream` requires a seekable stream, which an S3 body
  is not. `-web` already serves a range from a seekable stream without the
  interface, so absent is both honest and sufficient.
- **`AdapterSemantics` has no permissive default and must keep none.** A value
  object that accepted "atomic but mutable" would make deduplication opt-*out*,
  and what it hides is silent corruption found months later.
- **`putIfAbsent()` verifies length before reusing, and never overwrites.** The
  key is the hash; a length mismatch means a foreign writer, which invalidates
  the premise rather than being something to paper over.
- **Byte caps cannot be enforced mid-write here.** Checked before, verified
  after, object removed on violation. Do not "fix" this into a streaming
  counter — a remote PUT has no abort, and the pre-check plus `Upload`'s spool
  cap already bound the body.
- **`StreamWrapper::stream_close()` deliberately does nothing.** The stream
  belongs to the `Upload`, which promises `stream()` is readable again — closing
  it would make a retry after a failed write impossible.
- **The unit suite runs on `league/flysystem-memory`.** It cannot exercise
  presigned URLs, multipart upload or eventual consistency. Anything in those
  areas needs a case in `tests/Integration/`, which CI runs against MinIO in its
  own job — and that job waits for the endpoint and fails if it never appears,
  because a suite that skips itself looks exactly like one that passed.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments, trailing commas.
- Every validation regex ends with `\z`, never `$` (`docs/evolved-rules.md`
  ER-001).
- `config/di.php` is covered by neither cs, nor psalm, nor `src`-scoped tests.
  `ConfigWiringTest` exercises it through a real container instead.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned**, the MinIO service image by digest. Every
  `uses:` references a 40-char commit SHA with a `# vN` trailing comment; never
  revert to floating `@vN` tags. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit), plus
  `llms.txt`, `resources/skills/*/SKILL.md` and `examples/` if usage changed;
  update `CHANGELOG.md` when releasing.
- Re-run `make build` and, for anything touching S3 behaviour,
  `make test-integration` against MinIO. Paste the output.
