# Examples

Runnable scripts. Each is self-contained: it wires the package by hand, so the
whole flow reads in one file without a framework in the way.

```bash
composer install
php examples/store-in-memory.php
```

| Script | Shows | Needs a server? |
|---|---|---|
| [`store-in-memory.php`](store-in-memory.php) | The whole store contract against Flysystem's in-memory adapter: write, read, inventory, delete-the-directory — and the honest `null` from a store that cannot mint URLs | No |
| [`s3-presigned-urls.php`](s3-presigned-urls.php) | Presigned URLs that carry a delivery policy, and content-addressed writes converging on one object | Yes — an S3-compatible endpoint, e.g. MinIO |

The scripts use `league/flysystem-memory`, `league/flysystem-aws-s3-v3` and
`nyholm/psr7` because all three are development dependencies here. Any
Flysystem adapter and any PSR-17 implementation work; the package names none.

Start MinIO for the second script:

```bash
docker run -d --name minio -p 9000:9000 \
  -e MINIO_ROOT_USER=minioadmin -e MINIO_ROOT_PASSWORD=minioadmin \
  quay.io/minio/minio server /data
until curl -fs http://127.0.0.1:9000/minio/health/live >/dev/null; do sleep 1; done

FILESTORAGE_S3_ENDPOINT=http://127.0.0.1:9000 php examples/s3-presigned-urls.php
```
