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

3. **No presigned URL without a `TemporaryUrlOptionsInterface`.** A URL that
   cannot carry the delivery policy would serve an uploaded HTML file inline
   from your bucket. Returning null sends the caller to the proxy route, which
   enforces the headers itself.

4. **Deduplication requires both `AdapterSemantics` flags and has no default.**
   Never probe with `fileExists()` then `write()` — that is the exact race that
   hands a second writer a half-uploaded object.

5. **`putIfAbsent()` verifies length before reuse and never overwrites.** The
   key is the hash of the content; a mismatch means a foreign writer.

6. **Do not turn the byte cap into a mid-write counter.** A remote PUT has no
   abort: check before, verify after, remove on violation.

7. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

8. **Verification is mandatory.** `make build`, plus `make test-integration`
   against MinIO for anything touching URLs, uploads or dedup.

## Gotchas

- An empty-string URL is not a URL — it resolves to the current page.
- `delete(File)` removes the file's *directory* prefix, so derivatives written
  beside the original go with it. Deleting only the object leaks them.
- `StreamWrapper::stream_close()` intentionally leaves the upload's stream open;
  `Upload` promises `stream()` is readable again, and retries depend on it.
- The unit suite uses the in-memory adapter, which has no URL generator, no
  multipart upload and no signatures. It proves the store's logic and nothing
  about S3 — put those cases in `tests/Integration/`.
- `FlysystemStore` is `final`; the content-addressable variant delegates to it
  rather than extending it.
