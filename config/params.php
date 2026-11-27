<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-filestorage-flysystem' => [
        // The store's name, as it appears in File::$storeName and in
        // StorageInterface::add(storeName:). Changing it after files exist
        // orphans every row that names the old one.
        'name' => 'flysystem',
        // Where filestorage:gc parks objects before purging or restoring them,
        // e.g. '.quarantine': a prefix of the same filesystem whose first
        // segment starts with "." or "_", and which no public bucket policy
        // may expose. Null leaves the store without one, and gc then refuses
        // to delete from it (filestorage:check warns).
        'quarantinePrefix' => null,
        // Whether the adapter lists paths in strcmp order: true for S3 and
        // S3-compatible services, GCS and Azure. The inventory is then
        // streamed instead of sorted once per gc pass; a wrong true is caught
        // on the first out-of-order key (StoreException), never a short pass.
        'orderedListing' => false,
        // Whether the bucket answers every object's URL without credentials
        // (a public-read policy or ACL). Only filestorage:check reads it: it
        // then fails any group that accepts active content (HTML, SVG, XML),
        // because a public URL cannot force a download. Changes no behaviour.
        'publicBucket' => false,
    ],
];
