---
name: rasuvaeff-yii3-filestorage-flysystem
description: >-
  Flysystem object store for rasuvaeff/yii3-filestorage — FlysystemStore with
  nullable public and presigned URLs, FlysystemContentAddressableStore guarded
  by AdapterSemantics, the S3 delivery-options mapper, and the stream wrapper
  that uploads without buffering. Use when writing, reviewing or debugging S3,
  GCS or other object-store file handling in a project that has this package
  installed.
---

# rasuvaeff/yii3-filestorage-flysystem

One store class over every Flysystem adapter, with capabilities reported as
results. Namespace `Rasuvaeff\Yii3FilestorageFlysystem\`. Full API reference:
`llms.txt`.

## Safety rules — verify these on every change

1. **Never add an interface this store cannot honour for every adapter.**
   Capability is a *result* here: a null URL, an explicit `AdapterSemantics`, an
   absent `Range` interface. An interface the class implements only sometimes
   puts the lie inside the type system.

2. **Check `method_exists()` before calling `publicUrl()`/`temporaryUrl()`.** In
   Flysystem 3 they are `@method` docblock annotations, not interface
   declarations — an operator may not have them, and the failure is a fatal, not
   a catchable exception.

3. **No presigned URL without a `TemporaryUrlOptionsInterface`, and never an
   inline one for active content.** A URL that cannot carry the delivery policy
   would serve an uploaded HTML file inline from your bucket; returning null
   sends the caller to the proxy route. `S3TemporaryUrlOptions` forces
   `attachment` for every `ActiveMediaTypes` type (an S3 response cannot carry
   `nosniff`); it takes core's configured instance (required, autowired — core
   binds it from `activeMediaTypes.extra`/`allowInline`), never a list of its own. A public bucket must be declared (`publicBucket: true` / param
   `publicBucket`): the store then implements core's
   `PubliclyReadableStoreInterface` as public and `filestorage:check` fails
   every group accepting active types. Undeclared, `check` cannot see it.

4. **Deduplication requires both `AdapterSemantics` flags and has no default.**
   Without atomic visibility, `fileExists()` then `write()` is the exact race
   that hands a second writer a half-uploaded object.

5. **`putIfAbsent()` verifies length before reuse and never overwrites with
   different bytes.** The key is the hash of the content; a mismatch means a
   foreign writer. Concurrent writers may both publish the same bytes and both
   get `created: true` — the flag is advisory, so never delete on its basis.

6. **Do not turn the byte cap into a mid-write counter.** A remote PUT has no
   abort: check before, verify after, remove on violation (for a unique
   `write()`; `putIfAbsent()` leaves an over-cap object to the ledger grace
   period and `gc`, since another writer may already reference it).

7. **The quarantine prefix is reserved.** Never list it in `objects()`, never
   write or restore into it, always move into it with private visibility.
   `restore()` is check-then-move: not atomic on S3, correct only because the
   keys it restores are content hashes. A failed `quarantine()` puts back what
   it already moved (core 0.10 contract). A stored media type that fails RFC
   9110 grammar is never cleaned: presign it as octet-stream attachment, store
   no `ContentType`.

8. **One listing per `gc` pass, never one per page.** `objects()` and
   `quarantined()` continue the parked listing when the cursor is the previous
   page's last key. `orderedListing: true` (S3, GCS, Azure only) streams and
   verifies `strcmp` order pair by pair — a wrong flag must stay a
   `StoreException`, never become a silently shorter inventory. Not for S3
   Express directory buckets. Resuming in a new process re-lists up to the
   cursor (no `StartAfter`): run a pass in one process; a parked listing
   expires after an hour idle.

9. **The path generator must fit the layout.** DI calls
   `LayoutCompatibility::assertPaths()`; a flat key under `DirectoryLayout` is
   refused by the layout and `delete()` turns that into a `StoreException`
   (deleting nothing) — never catch it into a directory delete.

10. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

11. **Verification is mandatory.** `make build`, plus `make test-integration`
   against SeaweedFS for anything touching URLs, uploads, listing or dedup.

## Gotchas

- An empty-string URL is not a URL — it resolves to the current page.
- `delete(File)` removes what the layout names — under `DirectoryLayout` the
  file's *directory* prefix, under `FlatSiblingLayout` the `<base>-*` siblings —
  so derivatives go with it. Deleting only the object leaks them.
- Without `quarantinePrefix`, `filestorage:gc` cannot delete from the store.
- `objects()` takes `lastModified` from the listing; null means "never sweep",
  so do not fill it with "now" or 0. It never lists a path with a `.`-segment.
- `FlysystemContentAddressableStore(store, semantics)` uses the wrapped store's
  operator; `orderedListing` belongs to `FlysystemStore`, not `AdapterSemantics`.
- `write()` stores the detected type as `ContentType`, but never a scriptable
  one (SVG, HTML, …): those keep the type of their inert `.bin` key.
- A group's byte cap is core's `UploadSizeException`
  (`SizeLimitExceededException`), both before and after the write.
- `StreamWrapper::stream_close()` intentionally leaves the upload's stream open;
  `Upload` promises `stream()` is readable again, and retries depend on it.
- The unit suite uses the in-memory adapter, which has no URL generator, no
  multipart upload and no signatures. It proves the store's logic and nothing
  about S3 — put those cases in `tests/Integration/`.
- `FlysystemStore` is `final`; the content-addressable variant delegates to it
  rather than extending it.
