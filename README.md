# rasuvaeff/yii3-filestorage-flysystem

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-flysystem/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-flysystem/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-flysystem/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

The physical half of [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage),
backed by Flysystem: S3, GCS, Azure, FTP, a ZIP archive — anything with an
adapter. One store class for all of them, with capabilities reported as results
rather than claimed as interfaces.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

**Status: `0.x`.** The API may still change while the web package is built
against it.

## Requirements

- PHP 8.3 – 8.6
- `league/flysystem` ^3.35.3 (v2 is not supported)
- `rasuvaeff/yii3-filestorage` ^0.10
- A PSR-17 implementation, and a Flysystem adapter of your choice

## Installation

```bash
composer require rasuvaeff/yii3-filestorage-flysystem
```

This package binds `StoreInterface`. Core binds the facade;
`rasuvaeff/yii3-filestorage-db` binds the metadata half. You bind
`FilesystemOperator` — bucket, credentials and adapter are your configuration,
and no package can guess them.

```php
// config/common/di/filestorage.php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

return [
    FilesystemOperator::class => static fn (S3Client $client): FilesystemOperator => new Filesystem(
        new AwsS3V3Adapter($client, 'my-bucket'),
    ),
];
```

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-flysystem' => [
        // appears in File::$storeName; changing it after files exist orphans
        // every row that names the old one
        'name' => 'flysystem',
        // where filestorage:gc parks objects before purging or restoring them;
        // null (the default) means gc cannot delete from this store
        'quarantinePrefix' => '.quarantine',
        // true for S3 and S3-compatible services, GCS, Azure: the inventory is
        // streamed instead of sorted once per gc pass; see "Listing order"
        'orderedListing' => true,
        // true when the bucket serves objects without credentials; only
        // filestorage:check reads it, see "Public bucket"
        'publicBucket' => false,
    ],
];
```

The wiring checks the bound `PathGeneratorInterface` (core binds
`RandomPathGenerator`) against the layout when the store is built: a generator
that emits flat keys (`group/ab/cd/<name>.jpg`) under the default
`DirectoryLayout` would make deleting one file delete its neighbours, so it
fails at boot with `InvalidConfigException` instead. Bind
`ObjectLayoutInterface` to `FlatSiblingLayout` for such keys.

## What it does and does not claim

| Capability | Status |
|---|---|
| `StoreInterface` | Always. Write, read, stream, delete, exists, size, last-modified |
| `MaintenanceStoreInterface` | Always. Cursor-based inventory with timestamps — one listing per `gc` pass — and a quarantine, for `gc` / `verify` / `stat` |
| `StoreUrlProviderInterface` | Always implemented, **nullable in result**. A public or presigned URL when the adapter can mint one, `null` when it cannot |
| `PubliclyReadableStoreInterface` | Always, **answers the `publicBucket` flag** (default `false`) — see [Public bucket](#public-bucket) |
| `RangeReadableStoreInterface` | **Never** — see below |
| `ContentAddressableStoreInterface` | Only via `FlysystemContentAddressableStore`, and only with an explicit `AdapterSemantics` |

**Why nullable URLs rather than a conditional interface.** One `FlysystemStore`
has to wrap adapters that genuinely differ, and a PHP interface cannot be
implemented conditionally at runtime. So the class is the same everywhere and
the *answers* vary. In Flysystem 3 the URL methods are `@method` annotations —
"Will be added in 4.0" — not interface declarations, so an operator may not have
them at all; the store checks before calling rather than fataling.

**Why no `Range`.** Flysystem has no range primitive: `readStream()` returns the
whole object, and an S3 body is not seekable. Implementing the interface by
reading and discarding a prefix would advertise cheap seeking and deliver a full
download. Where an adapter *does* return a seekable stream,
`rasuvaeff/yii3-filestorage-web` detects that and serves the range anyway; where
it does not, a full `200` is correct. Presigned S3 URLs handle ranges in S3.

## Layout and garbage collection

```php
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;

$store = new FlysystemStore(
    name: 'flysystem',
    filesystem: $filesystem,
    streamFactory: $streamFactory,
    quarantinePrefix: '.quarantine',
    layout: new FlatSiblingLayout(), // default: DirectoryLayout
    orderedListing: true,            // S3, GCS, Azure; see "Listing order"
);
```

| Piece | Behaviour |
|---|---|
| `layout()` | `DirectoryLayout` unless configured. Chosen once per store: switching it on existing data orphans every derivative |
| `delete(File)` | `DirectoryLayout`: the key directory prefix, derivatives included. `FlatSiblingLayout`: `<base>.<ext>` and `<base>-*` in the same directory, found with one shallow listing. A path the layout refuses — a flat key such as `blobs/ab/cd/<sha>.jpg` under `DirectoryLayout`, whose directory is every neighbour's too — is a `StoreException`, and nothing is deleted |
| `objects()` | `StoredObject` with `lastModified` and `size` from the listing itself (S3 `ListObjectsV2`), no request per object. An adapter that omits the timestamp yields null, and `gc` never sweeps such an object. Never lists the quarantine prefix, nor a path with a segment starting with `.` (`.keep`, `.well-known/`) — no generator emits one |
| `quarantine()` | `move()` to `<prefix>/<token>/<path>` with private visibility — Flysystem's S3 copy would otherwise carry a public ACL into the quarantine. A move that fails puts the members already moved back first (they get the default visibility, like a restore); one that cannot go back stays under the entry, named in the exception, and the next `gc` restores it while a row references it |
| `restore()` | Put-if-absent per object: a key re-published meanwhile is kept. A `fileExists()` then `move()`, and on S3 a move is a copy plus a delete: **not atomic**. Correct for what `gc` restores — content-addressed keys, where a racing writer published the same bytes. A restored object gets the filesystem's default visibility, like a fresh write |
| `purge()` / `quarantined()` | Delete an entry; list entries in token (time) order |
| `quarantineProblem()` | A message when no prefix is configured — `gc` then refuses to delete from the store |

### Listing order

`gc` pages through `objects($after, 500)` and `quarantined($after, 500)`. A
page that starts where the previous one ended continues the same listing, so a
whole pass costs **one** listing of the bucket (one `ListObjectsV2` per 1000
keys), not one per page; any other cursor — a run resumed with `--after`, a new
process — starts a fresh listing and skips forward.

| `orderedListing` | Inventory | Memory |
|---|---|---|
| `false` (default) | One deep listing per pass, sorted once | Proportional to the bucket, once per pass |
| `true` | One deep listing per pass, streamed | Constant |

Declare `true` only for an adapter that lists keys in `strcmp` (byte) order:
S3 and S3-compatible services, GCS, Azure — not a local or FTP adapter. A wrong
`true` cannot lose objects silently: every consecutive pair is compared, and the
first key out of order ends the pass with a `StoreException`
(`… does not list in strcmp order …`).

The bucket belongs to the store. Anything under it that is not a store object —
another application's files — is inventory to `gc --orphans`; give the store a
bucket of its own, or a prefix of one (`AwsS3V3Adapter`'s `$prefix` argument).

**The quarantine prefix.** A path whose first segment starts with `.` or `_`
(`.quarantine`, `_trash/gc`). Group and scope names start with a letter or digit,
so no generated key can fall inside the prefix or contain it; `write()` and
`putIfAbsent()` refuse such a key anyway. It lives in the same bucket, because a
move cannot cross filesystems — so on a public bucket, **exclude the prefix from
the public read policy**. The store cannot inspect a bucket policy and does not
claim to.

## Presigned URLs and delivery policy

A presigned URL bypasses your application entirely, so whatever headers the
object store attaches *are* the response. If a group's policy says a file must
arrive as an attachment with a known media type and the URL cannot carry that,
the honest answer is no URL — the caller then falls back to the proxy route,
which enforces the headers itself.

So the mapping is explicit, and **without it no presigned URL is issued**:

```php
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;

return [
    TemporaryUrlOptionsInterface::class => S3TemporaryUrlOptions::class,
];
```

`S3TemporaryUrlOptions` maps onto `get_object_options`, which
`AwsS3V3Adapter::temporaryUrl()` merges into the presigned `GetObject` — so
`ResponseContentType` and `ResponseContentDisposition` are signed into the URL
and S3 returns them whatever the object's stored metadata says. The filename is
emitted per RFC 6266: a quote-safe ASCII fallback plus a percent-encoded
`filename*`. For an adapter with no equivalent, write your own mapper or leave
it unbound.

**Active content is never signed `inline`.** An S3 response cannot carry
`X-Content-Type-Options: nosniff`, so an uploaded SVG or HTML file served inline
from a bucket on your domain is stored XSS. Every type in core's
`Mime\ActiveMediaTypes` (HTML, SVG, XML, script, PDF, …) is signed as
`attachment` whatever the group's policy says; types you add to `-web` can be
given here too:

```php
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;

TemporaryUrlOptionsInterface::class => static fn (): TemporaryUrlOptionsInterface
    => new S3TemporaryUrlOptions(ActiveMediaTypes::withExtra(['application/x-my-script'])),
```

CR, LF and NUL are stripped from the response type. `write()` also stores the
detected media type as the object's `ContentType`, which is what a public URL
serves — except a scriptable type (HTML, SVG, XML, script): core gives those
the inert `.bin` key, and the object keeps the type of that key,
`application/octet-stream`, so a public bucket serves it as a download.
`putIfAbsent()` takes no media type and leaves it to the adapter.

### Public bucket

If the bucket (or a CDN in front of it) serves objects without credentials,
every object is reachable by path whatever `allowDirectPublicUrl` says, and a
public URL cannot force a download. Say so with `publicBucket`:

```php
new FlysystemStore('flysystem', $filesystem, $streams, publicBucket: true);
// or, through the package's DI: params ['rasuvaeff/yii3-filestorage-flysystem']['publicBucket' => true]
```

Both stores then implement core's `PubliclyReadableStoreInterface` with
`isPubliclyReadable(): true` (`FlysystemContentAddressableStore` answers for the
store it wraps), and `filestorage:check` treats the store as public: it lists
the `public` capability and **fails every group that accepts active content**
(HTML, SVG, XML, script) — groups are not bound to stores, so each one could
land in the bucket. Keep such types out of `allowedMimeTypes` of every group.

The flag is your statement, not something the store probes: `publicUrl()`
answering is no evidence, because the S3 adapter builds an object URL for a
private bucket too. It changes nothing else — no URL, no visibility on write.
The default `false` leaves a public bucket invisible to `check`.

## Deduplication

Sharing one object between two logical files is only safe if publishing is
atomic and the content key is never rewritten. Flysystem cannot answer either
question — the guarantees differ per adapter, per configuration, sometimes per
bucket policy — so the application declares them, and the class refuses to exist
without both:

```php
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;

return [
    StoreInterface::class => static fn (
        FilesystemOperator $filesystem,
        StreamFactoryInterface $streams,
    ): StoreInterface => new FlysystemContentAddressableStore(
        store: new FlysystemStore(
            name: 's3',
            filesystem: $filesystem,
            streamFactory: $streams,
            temporaryUrlOptions: new S3TemporaryUrlOptions(),
            quarantinePrefix: '.quarantine', // without one gc cannot collect blobs
            orderedListing: true,
        ),
        semantics: AdapterSemantics::guaranteed(),
    ),
];
```

| Flag | True when |
|---|---|
| `atomicVisibility` | A reader never sees a partially uploaded body. True for S3 and S3-compatible services; false for a plain FTP/SFTP adapter writing in place |
| `immutableContentKeys` | Nothing but this package writes under the content-addressed prefix, and a key is never rewritten with different bytes |

The wrapper uses the wrapped store's own `FilesystemOperator`, so the two cannot
differ. The content-addressed key generator is checked against the store's
layout where they meet — in `-db`'s deduplication factory — not here.

There is no permissive default. An installation that cannot promise both keeps
the plain `FlysystemStore` binding and stays unique-only — fully functional,
just not deduplicating. Without atomic visibility, `fileExists()` then
`write()` is precisely the race that hands a second writer a half-uploaded
object and calls it a cache hit.

`putIfAbsent()` reuses existing bytes only after a length check. A length that
disagrees with the upload is a hard error: the key *is* the hash, so a mismatch
means something outside this package wrote it.

The guarantee is **atomic publication of identical content**, not a single
creator. Two writers that arrive together both see the key absent, both upload
the same bytes, and both get `created: true`; a reader in between sees one
whole object or none. `created` is therefore advisory — `false` means the bytes
were already there, `true` may be reported to more than one concurrent writer —
and nothing deletes bytes on its basis: the ledger's reservations and
references decide when the collector may take an object. An exclusive first
write (S3 `If-None-Match: *`) is not reachable through Flysystem 3's write
config, and would hold for one adapter only.

## Byte caps

A remote `PUT` cannot be aborted halfway, so the group's `maxBytes` is enforced
in two places instead of during the copy: a known-oversized upload is refused
before any I/O, and the stored object's actual size is verified afterwards and
the object removed if the body under-reported. `Upload` has already bounded the
body once, when it spooled a non-seekable stream. Either refusal is core's
`UploadSizeException` (catch `SizeLimitExceededException` for a 413).

`putIfAbsent()` checks the same way but does **not** remove an over-cap object
it wrote: another writer may already reference that content key. The ledger's
grace period and the collector reclaim it if nothing commits against it.

## Examples

Runnable and self-contained — see [`examples/`](examples/). The S3 one needs an
endpoint; SeaweedFS in Docker is enough.

## Development

No PHP or Composer on the host; everything runs in Docker.

```bash
make build             # validate, normalize, require-checker, cs, psalm, test
make test-integration  # against a real S3 endpoint; needs SeaweedFS reachable
make cs-fix
make mutation
make release-check
```

SeaweedFS for the integration suite (MinIO's images were deleted from Docker Hub
and quay.io now requires authentication):

```bash
docker run -d --name weed -p 8333:8333 \
  chrislusf/seaweedfs@sha256:4e61d15fd35994cb1e43e1e553dff106794841fd9a99ade2fc8c8bfce4d7872d \
  server -dir=/data -s3
until curl -fs http://127.0.0.1:8333/ >/dev/null; do sleep 1; done
make test-integration
```

`make test-integration` supplies `FILESTORAGE_S3_ENDPOINT`, `_KEY`, `_SECRET`
and `_BUCKET` itself, defaulting to that container, so it never skips: with no
gateway reachable the S3 client fails to connect and Testo reports the affected
tests **aborted**. A bare `composer test:integration` with no
`FILESTORAGE_S3_ENDPOINT` set reports them **skipped** instead. Neither is a
passing run — check the word, not the colour: `passed` means the gateway was
really reached, `skipped` means nothing was tested. One test,
`aQuarantinedObjectIsPrivate`, skips by itself on SeaweedFS, which reports no
object ACLs; it runs against AWS or MinIO.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
