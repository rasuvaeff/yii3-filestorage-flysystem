# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
