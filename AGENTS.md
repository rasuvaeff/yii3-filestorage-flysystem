# AGENTS.md — yii3-filestorage-flysystem

Guidance for AI agents working on this package. Read before changing code.

## What this is

The Flysystem-backed physical store for `rasuvaeff/yii3-filestorage`: S3, GCS,
Azure, FTP, anything with an adapter. Namespace
`Rasuvaeff\Yii3FilestorageFlysystem`.

Public API: `FlysystemStore` (`StoreUrlProviderInterface` +
`MaintenanceStoreInterface` + `PubliclyReadableStoreInterface`, with layout and
quarantine), `FlysystemContentAddressableStore` (adds
`ContentAddressableStoreInterface`), `AdapterSemantics`,
`Url\TemporaryUrlOptionsInterface` with `Url\S3TemporaryUrlOptions`.
`Stream\StreamWrapper`, `Listing\Continuation` and `FlysystemStore::filesystem()`
are `@internal`.

DI wiring: `config/di.php` binds `StoreInterface` and nothing else. It must
**not** bind `StorageInterface` (core's), `RepositoryInterface` (`-db`'s),
`FilesystemOperator` or `TemporaryUrlOptionsInterface` — the last two are
application configuration, and `yiisoft/config` allows one vendor package per
key.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `make build`. For anything touching URLs, uploads, listing or dedup, also
   `make test-integration` against SeaweedFS — the in-memory adapter has no
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
make test-integration   # always resolves an endpoint; needs a reachable SeaweedFS
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
make test-integration
docker rm -f weed
```

The gateway runs authless without an s3 config, so the default
`minioadmin`/`minioadmin` credentials the Makefile supplies are accepted
as-is.

`make test-integration` supplies the four `FILESTORAGE_S3_*` variables itself,
defaulting to that container, and runs with `--network host` — the suite talks
to the host's `127.0.0.1:8333`, which is not the container's. Override any of
them from the environment to point at something else. Because `make` always
supplies an endpoint, it never skips: with no gateway reachable there, the S3
client fails to connect inside `#[BeforeTest]` and Testo reports the affected
tests **aborted**, not skipped. `aQuarantinedObjectIsPrivate` skips itself on
SeaweedFS, which reports every object as private (no ACLs): the quarantine's
private visibility is verified only against AWS or MinIO.

**Skipping is a bare `composer test:integration` behaviour.** Run directly,
without `make` and with no `FILESTORAGE_S3_*` variable set, `FlysystemStore` is
never constructed and every test throws `SkipTest`, which Testo reports as
**skipped**. A skipped run is not a passing run, and Testo says which is which:
if you see `passed`, the gateway was really reached; if you see `skipped`,
nothing was tested. Check the word before believing the colour.

`composer.lock` is gitignored (library).

## Mutation testing

`minMsi` is **88**. `composer mutation` runs Infection with `--with-uncovered`
(code the tests execute but do not name in `#[Covers]` is mutated too), so the
number is the overall MSI: 409 of 418 counted mutants killed (about 98%, CI
2026-11-27, at 0.4.0 — the 0.5 listing/rollback code has not been through a
mutation run yet; Infection is not run locally on this machine), with
the template's signature-default ignore and Public/ProtectedVisibility off. The
16 ignored mutants are `type $name = default,` signature lines; the signatures
that carry them (`objects()`, `quarantined()`, `putIfAbsent()`) are written one
parameter per line so the ignore applies. Every fault the store distinguishes
has a test that breaks it that specific way: missing vs. unreachable vs.
present-but-unreadable, a declared size trusted over a re-measurement, the
reuse cap's own boundary distinct from the pre-write one, a pre-write
refusal that never touches the filesystem, and — for the quarantine — a
vanished member vs. a failed move vs. an unreachable probe, a restore that
skips only the re-published member, and the exact depth of every listing. The survivors are the groups below:

| Group | Example | Why no test kills it |
|---|---|---|
| Floors and clamps | `max(0, $written)` around a size Flysystem already reports as non-negative | Guards a value the source cannot produce; removing the floor changes nothing observable |
| A redundant `rewind()` | `measure()`'s own `$stream->rewind()` | `Upload::stream()` already rewinds on every call, so nothing downstream can ever observe whether `measure()` also did |
| The `use_include_path` argument | `fopen(..., use_include_path: false, ...)` in `StreamWrapper::wrap()` | A custom stream-wrapper protocol never consults the include path |
| An `\assert()` in `page()` | `\assert($entry !== null)` after `valid()` | Not compiled in CI (`zend.assertions=-1`); it exists for psalm, since `Iterator::current()` is nullable |
| Optimisations with an equal answer | `unset($listing)` in `inventory()`; `holds()` falling back to a fresh listing | A fresh listing from the same key yields the same page, only slower |

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
- **`putIfAbsent()` verifies length before reusing, and never overwrites with
  different bytes.** The key is the hash; a length mismatch means a foreign
  writer, which invalidates the premise rather than being something to paper
  over.
- **`created` is advisory, not exclusive.** Two writers that both see the key
  absent both PUT the same bytes and both report `created: true`
  (`concurrentWritersBothPublishTheSameBytesAndBothReportCreated`). That is the
  contract, not a bug: no caller deletes on `created`, the ledger's reservations
  decide. Do not "fix" it with S3 `If-None-Match` — Flysystem 3's S3 adapter
  drops the option, and it would hold for one adapter only (golden rule 3).
- **The quarantine prefix is reserved namespace.** Its first segment starts
  with `.` or `_`, which no group or scope name can, so no generated key lies
  inside or around it. `objects()` skips it, `write()` / `putIfAbsent()` /
  `restore()` refuse targets in it. Moves into it pass
  `visibility: private` — Flysystem's S3 copy retains the source ACL
  otherwise, and a deleted public object stays downloadable for the whole
  retention. Restores pass `retain_visibility: false`, so the object gets the
  filesystem's default visibility like a fresh write.
- **`restore()` is check-then-move and not atomic on S3** (a move there is a
  copy plus a delete). Correct only because what `gc` restores are
  content-addressed keys: a writer that won the race wrote the same bytes. Do
  not reuse it for mutable keys.
- **One listing per `gc` pass (review 1.0 #6, #8).** `objects()` and
  `quarantined()` park their listing in a `Listing\Continuation`; a page whose
  cursor is the previous page's last key continues it, any other cursor starts
  over. An exhausted listing stays parked once, so the closing empty page costs
  nothing. `FlysystemStoreListingTest` counts adapter calls and entries — keep
  it at one call per pass.
- **`orderedListing` is verified, never trusted.** It lives on `FlysystemStore`
  (not `AdapterSemantics`, since 0.5). With it, every consecutive pair of the
  deep listing (and of the quarantine's shallow one) is compared and the first
  descent is a `StoreException` — the review's probe silently lost 9014 of
  10000 objects to a wrong flag. Never replace the exception with a fallback
  sort: pages already yielded cannot be taken back. Without it, the listing is
  sorted once per pass (`usort` over path/mtime/size triples).
- **Hidden paths are never inventory.** A segment starting with `.` (`.keep`,
  `.well-known/`) — no generator emits one (core review 1.0 B2). There is no
  `ownedPrefixes` here: a bucket shared with other content is scoped with the
  adapter's own prefix.
- **The layout guards `delete()`.** Core 0.10's `DirectoryLayout` refuses a flat
  key; `delete()` rethrows that as `StoreException` (so `-web` answers 404) and
  deletes nothing. DI checks the bound `PathGeneratorInterface` with
  `LayoutCompatibility::assertPaths()`; content keys are `-db`'s to check.
- **`quarantine()` rolls back.** A failed move puts already moved members back
  (`retain_visibility: false`, like `restore()`); a member that cannot go back is
  named in the exception and stays under the entry.
- **Active content is `attachment` on a presigned URL.** `S3TemporaryUrlOptions`
  repeats core's `DeliveryOptions` rule with its own `ActiveMediaTypes` (extras
  configurable) and strips CR/LF/NUL from the response type. `write()` passes
  the detected type as S3 `ContentType` — never a scriptable one: core keys
  those `.bin` so a public bucket serves them as octet-stream, and storing
  `image/svg+xml` would undo that (core review 1.0 B5). `putIfAbsent()` has no
  media type.
- **`publicBucket` is a statement, not a capability switch.** Both stores
  implement core's `PubliclyReadableStoreInterface` for every adapter and answer
  the flag (the CA store answers for the store it wraps) — that is a result, as
  golden rule 3 asks, not a conditional interface. Only `filestorage:check`
  reads it (`PublicBucketCheckTest` runs core's `CheckCommand` against both
  stores). Never let it change URLs or write visibility, and never infer it from
  `publicUrl()` — an S3 adapter builds that URL for a private bucket too.
- **`objects()` takes `lastModified` / `size` from the listing.** Null stays
  null — the core sweep never deletes an undated object. Do not add a
  per-object `lastModified()` request, and never substitute "now" or 0.
- **The layout decides `delete()`.** `DirectoryLayout`: `deleteDirectory()` on
  the key prefix, or `delete()` on the object itself when it sits at the store
  root (its "directory" is itself). `FlatSiblingLayout`: one **shallow**
  listing of the directory — a deep one would walk every subdirectory to find
  nothing (`everyListingIsAsNarrowAsTheLayoutAllows` pins this).
- No `DerivativeAwareStoreInterface`: its `writeDerivative()` demands a
  mid-write cap this store cannot enforce (golden rule 3). `-images` therefore
  cannot target a Flysystem store; the layout only governs `delete()` and the
  quarantine set here.
- **Byte caps cannot be enforced mid-write here.** Checked before, verified
  after, object removed on violation. Do not "fix" this into a streaming
  counter — a remote PUT has no abort, and the pre-check plus `Upload`'s spool
  cap already bound the body.
- **`StreamWrapper::stream_close()` deliberately does nothing.** The stream
  belongs to the `Upload`, which promises `stream()` is readable again — closing
  it would make a retry after a failed write impossible.
- **The unit suite runs on `league/flysystem-memory`.** It cannot exercise
  presigned URLs, multipart upload or eventual consistency. Anything in those
  areas needs a case in `tests/Integration/`, which CI runs against SeaweedFS in its
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
- **CI workflows are SHA-pinned**, the SeaweedFS image by digest. Every
  `uses:` references a 40-char commit SHA with a `# vN` trailing comment; never
  revert to floating `@vN` tags. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit), plus
  `llms.txt`, `resources/skills/*/SKILL.md` and `examples/` if usage changed;
  update `CHANGELOG.md` when releasing.
- Re-run `make build` and, for anything touching S3 behaviour,
  `make test-integration` against SeaweedFS. Paste the output.
