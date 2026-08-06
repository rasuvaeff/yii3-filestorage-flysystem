# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Initial development. Not released.

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
