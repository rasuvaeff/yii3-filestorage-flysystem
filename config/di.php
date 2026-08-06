<?php

declare(strict_types=1);

use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;

/** @var array $params */

// This package owns exactly one key: the physical store. Core binds the facade,
// `-db` binds the metadata half, and `yiisoft/config` allows one vendor package
// per key — two backends claiming StoreInterface is a `Duplicate key` error by
// design, and it means "choose one".
//
// FilesystemOperator is NOT bound here. Bucket, credentials and adapter are
// application configuration, and guessing them is not something a package can
// do. "Zero app config" means this family contributes no duplicate keys; it
// cannot mean inventing a physical target.
//
// TemporaryUrlOptionsInterface is also left to the application: whether
// presigned URLs can carry a delivery policy depends on the adapter, and
// unbound means no presigned URLs — which is the safe answer, not a broken one.
return [
    StoreInterface::class => static fn (
        FilesystemOperator $filesystem,
        StreamFactoryInterface $streamFactory,
        ?TemporaryUrlOptionsInterface $temporaryUrlOptions = null,
    ): StoreInterface => new FlysystemStore(
        name: (string) $params['rasuvaeff/yii3-filestorage-flysystem']['name'],
        filesystem: $filesystem,
        streamFactory: $streamFactory,
        temporaryUrlOptions: $temporaryUrlOptions,
    ),
];
