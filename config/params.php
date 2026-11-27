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
    ],
];
