<?php

declare(strict_types=1);

use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
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
//
// ObjectLayoutInterface likewise: unbound is DirectoryLayout, the frozen
// default. An application that chose FlatSiblingLayout binds it once, before
// the store holds data.
//
// The path generator (core binds RandomPathGenerator) is checked against the
// layout here, at boot: a generator emitting flat keys under DirectoryLayout
// makes deleting one file delete its neighbours (review 1.0 A1). The content-
// addressed key generator is not checked here — this binding is the plain,
// unique-only store; `-db`'s deduplication checks it where it meets the store.
return [
    StoreInterface::class => static function (
        FilesystemOperator $filesystem,
        StreamFactoryInterface $streamFactory,
        ?TemporaryUrlOptionsInterface $temporaryUrlOptions = null,
        ?ObjectLayoutInterface $layout = null,
        ?PathGeneratorInterface $paths = null,
    ) use ($params): StoreInterface {
        $settings = $params['rasuvaeff/yii3-filestorage-flysystem'];
        $store = new FlysystemStore(
            name: (string) $settings['name'],
            filesystem: $filesystem,
            streamFactory: $streamFactory,
            temporaryUrlOptions: $temporaryUrlOptions,
            quarantinePrefix: isset($settings['quarantinePrefix']) ? (string) $settings['quarantinePrefix'] : null,
            layout: $layout,
            orderedListing: (bool) ($settings['orderedListing'] ?? false),
            publicBucket: (bool) ($settings['publicBucket'] ?? false),
        );

        if ($paths !== null) {
            LayoutCompatibility::assertPaths($paths, $store, $streamFactory);
        }

        return $store;
    },
];
