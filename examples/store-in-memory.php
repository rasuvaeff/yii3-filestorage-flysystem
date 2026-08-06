<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$filesystem = new Filesystem(new InMemoryFilesystemAdapter());
$store = new FlysystemStore(name: 'flysystem', filesystem: $filesystem, streamFactory: $factory);

// 1. Write. The path comes from the generator; the store never invents one.
$result = $store->write(
    upload: Upload::fromStream($factory->createStream("id,name\n1,Ada\n"), 'people.csv', $factory),
    groupName: 'documents',
    pathGenerator: new RandomPathGenerator(),
    mediaType: 'text/csv',
);

echo "stored at {$result->relativePath}\n";
echo "  size       {$result->size} bytes\n";
echo "  externalId {$result->externalId}\n";

// A File is what the rest of the package passes around. In an application the
// facade builds it and the repository stores it; here it is by hand.
$file = File::create(
    id: 'file-1',
    storeName: 'flysystem',
    groupName: 'documents',
    relativePath: $result->relativePath,
    originalName: 'people.csv',
    size: $result->size,
    createdAt: new DateTimeImmutable(),
    mimeType: 'text/csv',
);

// 2. Read.
echo "exists     " . ($store->exists($file) ? 'yes' : 'no') . "\n";
echo "size       " . $store->size($file) . "\n";
echo "contents   " . trim((string) $store->stream($file)?->getContents()) . "\n";

// 3. URLs. This adapter has no generator, and the honest answer is null —
//    the caller falls back to the application proxy.
$policy = new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: true);
var_dump($store->publicUrl($file));
var_dump($store->temporaryUrl($file, new DateTimeImmutable('+1 hour'), DeliveryOptions::fromFile($file, $policy)));

// 4. Inventory, for maintenance commands. Paged and resumable by path.
$filesystem->write('documents/other/original.txt', 'unrelated');
foreach ($store->objects(limit: 10) as $object) {
    echo "inventory  {$object->relativePath}\n";
}

// 5. Delete removes the file's whole directory — derivatives included, or a
//    thumbnail written beside the original would outlive it forever.
$filesystem->write(dirname($result->relativePath) . '/thumb.webp', 'preview');
$store->delete($file);

echo "after delete, original gone:  " . ($store->exists($file) ? 'no' : 'yes') . "\n";
echo "after delete, thumbnail gone: "
    . ($filesystem->fileExists(dirname($result->relativePath) . '/thumb.webp') ? 'no' : 'yes') . "\n";

// The unrelated object is untouched.
$store->deleteObject(new StoredObjectId('documents/other/original.txt'));
echo "unrelated object removed on request\n";
