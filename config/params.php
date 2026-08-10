<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-filestorage-flysystem' => [
        // The store's name, as it appears in File::$storeName and in
        // StorageInterface::add(storeName:). Changing it after files exist
        // orphans every row that names the old one.
        'name' => 'flysystem',
    ],
];
