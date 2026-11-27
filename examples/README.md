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
| [`s3-presigned-urls.php`](s3-presigned-urls.php) | Presigned URLs that carry a delivery policy, and content-addressed writes converging on one object | Yes — an S3-compatible endpoint, e.g. SeaweedFS |

The scripts use `league/flysystem-memory`, `league/flysystem-aws-s3-v3` and
`nyholm/psr7` because all three are development dependencies here. Any
Flysystem adapter and any PSR-17 implementation work; the package names none.

Start an S3 gateway for the second script (SeaweedFS: MinIO's images were
deleted from Docker Hub and quay.io now requires authentication):

```bash
docker run -d --name weed -p 8333:8333 \
  chrislusf/seaweedfs@sha256:4e61d15fd35994cb1e43e1e553dff106794841fd9a99ade2fc8c8bfce4d7872d \
  server -dir=/data -s3
until curl -fs http://127.0.0.1:8333/ >/dev/null; do sleep 1; done

FILESTORAGE_S3_ENDPOINT=http://127.0.0.1:8333 php examples/s3-presigned-urls.php
```
