<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;

require __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('FILESTORAGE_S3_ENDPOINT');
if ($endpoint === false || $endpoint === '') {
    echo "Set FILESTORAGE_S3_ENDPOINT to an S3-compatible endpoint. See examples/README.md.\n";

    exit(0);
}

$bucket = (string) (getenv('FILESTORAGE_S3_BUCKET') ?: 'filestorage-example');
$client = new S3Client([
    'version' => 'latest',
    'region' => (string) (getenv('FILESTORAGE_S3_REGION') ?: 'us-east-1'),
    'endpoint' => $endpoint,
    // MinIO addresses buckets as path segments, not as subdomains
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => (string) (getenv('FILESTORAGE_S3_KEY') ?: 'minioadmin'),
        'secret' => (string) (getenv('FILESTORAGE_S3_SECRET') ?: 'minioadmin'),
    ],
]);
$client->doesBucketExist($bucket) or $client->createBucket(['Bucket' => $bucket]);

$factory = new Psr17Factory();
$filesystem = new Filesystem(new AwsS3V3Adapter($client, $bucket));

// The mapper is what makes presigned URLs usable: without it the store returns
// null, because it cannot promise the response headers a policy asks for.
$store = new FlysystemStore(
    name: 's3',
    filesystem: $filesystem,
    streamFactory: $factory,
    temporaryUrlOptions: new S3TemporaryUrlOptions(),
);

$result = $store->write(
    upload: Upload::fromStream($factory->createStream('quarterly numbers'), 'report.pdf', $factory),
    groupName: 'documents',
    pathGenerator: new RandomPathGenerator(),
    mediaType: 'application/pdf',
);

$file = File::create(
    id: 'file-1',
    storeName: 's3',
    groupName: 'documents',
    relativePath: $result->relativePath,
    originalName: 'quarterly report.pdf',
    size: $result->size,
    createdAt: new DateTimeImmutable(),
    mimeType: 'application/pdf',
);

$options = DeliveryOptions::fromFile(
    file: $file,
    policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: true),
);
$url = $store->temporaryUrl($file, new DateTimeImmutable('+10 minutes'), $options);

echo "presigned URL:\n  {$url}\n\n";

// The policy is signed into the URL, so S3 returns it on the response — a
// download, with the original filename, whatever the object's stored metadata
// happens to say.
$body = file_get_contents((string) $url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
foreach ($http_response_header ?? [] as $header) {
    if (stripos($header, 'content-disposition') === 0 || stripos($header, 'content-type') === 0) {
        echo "response: {$header}\n";
    }
}
echo "body:     {$body}\n\n";

// Deduplication, against an adapter whose semantics the application declares.
$dedup = new FlysystemContentAddressableStore(
    store: $store,
    filesystem: $filesystem,
    semantics: AdapterSemantics::guaranteed(),
);

$hash = hash('sha256', 'shared bytes');
$key = new StoredObjectId("sha/{$hash[0]}{$hash[1]}/{$hash[2]}{$hash[3]}/{$hash}/original");

$first = $dedup->putIfAbsent(Upload::fromStream($factory->createStream('shared bytes'), 'a.bin', $factory), $key);
$second = $dedup->putIfAbsent(Upload::fromStream($factory->createStream('shared bytes'), 'b.bin', $factory), $key);

echo "first put created:  " . ($first->created ? 'yes' : 'no') . "\n";
echo "second put created: " . ($second->created ? 'yes' : 'no') . " (reused, so the ledger adds a reference)\n";

$store->delete($file);
$dedup->deleteObject($key);
echo "cleaned up\n";
