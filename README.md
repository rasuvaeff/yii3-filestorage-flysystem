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

- PHP 8.3+
- `league/flysystem` ^3.29 (v2 is not supported)
- `rasuvaeff/yii3-filestorage` ^0.1
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
    ],
];
```

## What it does and does not claim

| Capability | Status |
|---|---|
| `StoreInterface` | Always. Write, read, stream, delete, exists, size, last-modified |
| `MaintenanceStoreInterface` | Always. Cursor-based inventory and idempotent object deletion, for `gc` / `verify` / `stat` |
| `StoreUrlProviderInterface` | Always implemented, **nullable in result**. A public or presigned URL when the adapter can mint one, `null` when it cannot |
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
        store: new FlysystemStore('s3', $filesystem, $streams, new S3TemporaryUrlOptions()),
        filesystem: $filesystem,
        semantics: AdapterSemantics::guaranteed(),
    ),
];
```

| Flag | True when |
|---|---|
| `atomicVisibility` | A reader never sees a partially uploaded body. True for S3 and S3-compatible services; false for a plain FTP/SFTP adapter writing in place |
| `immutableContentKeys` | Nothing but this package writes under the content-addressed prefix, and never rewrites a key |

There is no permissive default. An installation that cannot promise both keeps
the plain `FlysystemStore` binding and stays unique-only — fully functional,
just not deduplicating. The alternative everyone reaches for,
`fileExists()` then `write()`, is precisely the race that hands a second writer
a half-uploaded object and calls it a cache hit.

`putIfAbsent()` reuses existing bytes only after a length check, and reports
`created: false` so the ledger records a reference instead of a new blob. A
length that disagrees with the upload is a hard error: the key *is* the hash, so
a mismatch means something outside this package wrote it.

## Byte caps

A remote `PUT` cannot be aborted halfway, so the group's `maxBytes` is enforced
in two places instead of during the copy: a known-oversized upload is refused
before any I/O, and the stored object's actual size is verified afterwards and
the object removed if the body under-reported. `Upload` has already bounded the
body once, when it spooled a non-seekable stream.

## Examples

Runnable and self-contained — see [`examples/`](examples/). The S3 one needs an
endpoint; MinIO in Docker is enough.

## Development

No PHP or Composer on the host; everything runs in Docker.

```bash
make build             # validate, normalize, require-checker, cs, psalm, test
make test-integration  # against a real S3 endpoint; needs MinIO reachable
make cs-fix
make mutation
make release-check
```

MinIO for the integration suite:

```bash
docker run -d --name minio -p 9000:9000 \
  -e MINIO_ROOT_USER=minioadmin -e MINIO_ROOT_PASSWORD=minioadmin \
  quay.io/minio/minio server /data
until curl -fs http://127.0.0.1:9000/minio/health/live >/dev/null; do sleep 1; done
make test-integration
```

`make test-integration` supplies `FILESTORAGE_S3_ENDPOINT`, `_KEY`, `_SECRET`
and `_BUCKET` itself, defaulting to that container, so it never skips: with no
MinIO reachable the S3 client fails to connect and Testo reports the affected
tests **aborted**. A bare `composer test:integration` with no
`FILESTORAGE_S3_ENDPOINT` set reports them **skipped** instead. Neither is a
passing run — check the word, not the colour: `5 passed` means MinIO was really
reached, `5 skipped` means nothing was tested.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
