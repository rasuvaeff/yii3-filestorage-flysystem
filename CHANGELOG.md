# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Pre-1.0 minor (planned 0.5.0): requires `rasuvaeff/yii3-filestorage` `^0.10`, and fixes the pre-1.0 review (2026-11-27).

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.10`; this release does not install with core 0.9. Stay on 0.4.0 for 0.9.
- **BC break:** `deleteObject()` is gone from `FlysystemStore` and `FlysystemContentAddressableStore` (core 0.10 removed it from `MaintenanceStoreInterface`). `objects()` yields core 0.10's `StoredObject` — read `$object->id->relativePath`.
- **BC break:** a group's `maxBytes` is now core's `UploadSizeException` (was `UploadTooLargeException`) from `write()` and `putIfAbsent()`, before and after the write. Catch `SizeLimitExceededException` for both.
- **BC break:** `orderedListing` moved from `AdapterSemantics` (constructor and `guaranteed()`) to `FlysystemStore(orderedListing: bool)` and the `orderedListing` param; `FlysystemStore` no longer takes `semantics`. A plain store can now declare a listing order without claiming deduplication guarantees (review #7).
- **BC break:** `FlysystemContentAddressableStore(store, semantics)` — the `filesystem` argument is gone; the wrapped store's own operator is used, so writes and reads can no longer go to two different operators (review #16).
- Fix: `objects()` and `quarantined()` re-listed the whole bucket (or every quarantine token) on every page — quadratic per `gc` pass (10,000 objects: 425,397 entries pulled without `orderedListing`, 232,971 with it). A page whose cursor is the previous page's last key now continues the same listing: one listing per pass (review #6, #8).
- Fix: a wrongly declared `orderedListing` silently skipped objects (the probe saw 1,067 of 10,000). Every consecutive pair is now compared, and the first key out of `strcmp` order is a `StoreException` (review #7).
- Fix: presigned S3 URLs served SVG/HTML `inline` from the bucket (review A5 / #2). With core 0.10 every `ActiveMediaTypes` type is `attachment`; `S3TemporaryUrlOptions(?ActiveMediaTypes $activeTypes)` applies the same rule with your list (`ActiveMediaTypes::withExtra([...])`) and strips CR/LF/NUL from the response type (#15). **Behaviour:** PDF is an active (plugin) type — a presigned PDF URL is now an attachment even for an `inline` group.
- Fix: a flat key under `DirectoryLayout` deleted its whole fan-out directory on `delete()` (review A1 / #4). Core 0.10's layout now refuses such a path; `delete()` turns the refusal into a `StoreException` and deletes nothing. DI checks the bound `PathGeneratorInterface` against the layout at boot (`LayoutCompatibility::assertPaths`, `InvalidConfigException`).
- Fix: `quarantine()` left already moved members in the quarantine when a later move failed; it now puts them back first (core 0.10 contract). A member that cannot go back is named in the exception and stays under the entry.
- Enh: `objects()` never lists a path with a segment starting with `.` (`.keep`, `.well-known/`).
- Enh: `write()` stores the detected media type as the object's S3 `ContentType` instead of letting Flysystem guess it again (#15) — except a scriptable type (`ActiveMediaTypes::scriptable()`), which core keys `.bin` and which stays typed by that key (`application/octet-stream`), so a public bucket never serves it inline.
- Docs: listing order, the bucket belonging to the store, `filestorage:check` not seeing a public bucket, `putIfAbsent()` leaving an over-cap object to `gc`, the dedup recipe with `quarantinePrefix` and `orderedListing`; SeaweedFS replaces MinIO in README, examples and the Makefile default endpoint (`127.0.0.1:8333`) (#14).
- Tests: integration cases for an SVG presigned URL, the stored `ContentType`, an ordered pass across `ListObjectsV2` pages, and the quarantine ACL (skipped on SeaweedFS, which reports no ACLs).

## 0.4.0 — 2026-11-27

Pre-1.0 minor: requires `rasuvaeff/yii3-filestorage` `^0.9`, whose store contract changed.

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.9`; this release does not install with core 0.8. Stay on 0.3.2 for 0.8.
- Enh #18: `layout()`; `FlysystemStore` takes `?ObjectLayoutInterface $layout` (default `DirectoryLayout`). `delete()` removes what the layout names: under `DirectoryLayout` the key directory as before; under `FlatSiblingLayout` the object and its `<base>-*` siblings, found with one shallow listing.
- Enh #18: `objects()` yields `StoredObject` with `lastModified` and `size` taken from the listing's `FileAttributes` — no request per object; an adapter that omits the timestamp gives null, and `gc` never sweeps such an object.
- Enh #18: two-phase deletion. `FlysystemStore` takes `?string $quarantinePrefix` (e.g. `.quarantine`, first segment starting with `.` or `_`, so no generated key can lie inside or around it); `quarantine()` moves objects to `<prefix>/<token>/<path>` with private visibility, `restore()` puts them back unless the key was re-published (check-then-move: not atomic on S3, correct for content-addressed keys), `purge()`, `quarantined()`, `quarantineProblem()`. The prefix is hidden from `objects()`, and `write()` / `putIfAbsent()` refuse keys inside it. `FlysystemContentAddressableStore` delegates all of it.
- **Behaviour:** without a quarantine prefix, `filestorage:gc` no longer deletes from the store, and `filestorage:check` warns. Set `quarantinePrefix` in the params (DI default `null`), and keep the prefix out of any public bucket policy.
- Fix: `delete()` of an object at the store root under `DirectoryLayout` removed the prefix `<name>/` instead of the object, leaving the file in place.
- DI: binds the store with an optional `ObjectLayoutInterface` and the new `quarantinePrefix` param.

## 0.3.2 — 2026-11-27

- Docs: `putIfAbsent()` guarantees atomic publication of identical content, not a single creator. Concurrent writers that both see the key absent both publish and both report `created: true`; the flag is advisory and nothing deletes on it (#16).
- Tests: a controlled interleaving pins the concurrent-writer outcome in the unit and S3 suites; the S3 suite issues a real `Range` request on a presigned URL.

## 0.3.1 — 2026-11-27

- Docs: the agent skill notes that `putIfAbsent()` leaves an over-cap object to the ledger grace period and `gc` instead of removing it.

## 0.3.0 — 2026-11-27

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.8.2`, so the whole family installs on one line of the core. This release no longer installs with core 0.1, 0.8.0 or 0.8.1; stay on 0.2.1 for those.

## 0.2.1 — 2026-11-27

- Requires `league/flysystem` `^3.35.3`: versions with security advisories are no longer allowed.

## 0.2.0 — 2026-11-24

- Enh #8: Add PHP 8.6 support. The constraint is now `8.3 - 8.6`, and
  `rasuvaeff/yii3-filestorage` is accepted on the `^0.8` line (released with
  `8.3 - 8.6`).

- Mutation testing now counts code the tests execute but do not name in `#[Covers]`
  (`composer mutation` runs Infection with `--with-uncovered`, plus the signature-default
  ignore and the visibility mutators switched off as in the package template); overall
  MSI 78% -> about 96% against `minMsi` 88. New tests: a pre-write refusal that never
  touches the filesystem, `StoreException` wrapping of failed writes, deletes and
  listings (message, code `0`, previous), operators without URL methods, `StreamWrapper`
  called without a stream and its `fstat()` shape, a failed `fopen()`, re-registration,
  a stalled read during measuring, and empty-body sizes. Behaviour-neutral source
  reformatting: `objects()`, `putIfAbsent()` and `AdapterSemantics::guaranteed()` take one
  parameter per line, and the `AdapterSemantics` refusal message is a single literal
  instead of a concatenation (identical text).
- Wire `AddNameToLiteralArgumentRector` into `rector.php`. The rule shipped in
  `require-dev` from the first release but was never listed in the config, so it
  had never run. Enabling it names the boolean literals passed to `fopen()` and
  `in_array()` in `Stream\StreamWrapper` and across the test suite. Internal
  only — no behaviour or public API change.

## 0.1.0 — 2026-08-10

First release. Tracks `rasuvaeff/yii3-filestorage` `0.x`: the API settles
together with core's while the family is built out.

- `FlysystemStore`: the store contract over any Flysystem adapter, with
  maintenance inventory and public/presigned URLs reported as nullable results
  rather than as interfaces that cannot vary at runtime.
- `FlysystemContentAddressableStore` and `AdapterSemantics`: deduplication, but
  only against an adapter whose atomic visibility and immutable content keys the
  application declares explicitly.
- `Url\TemporaryUrlOptionsInterface` with `Url\S3TemporaryUrlOptions`, so a
  presigned URL carries the group's delivery policy — or is not issued.
- `Stream\StreamWrapper`, which lets Flysystem read an upload straight through
  instead of copying it into `php://temp` first.
- A MinIO-gated integration suite covering presigned URLs, multipart uploads and
  content-addressed convergence, run in its own CI job.
