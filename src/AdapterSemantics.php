<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem;

use InvalidArgumentException;

/**
 * What the operator you configured actually promises about concurrent writes.
 *
 * Deduplication makes two logical files share one physical object, and that is
 * only safe if publishing those bytes is atomic and the content key is never
 * rewritten by anything else. Flysystem cannot answer either question: a
 * `FilesystemOperator` is one PHP interface over S3, FTP, a ZIP archive and an
 * in-memory array, and the guarantees differ per adapter, per configuration,
 * sometimes per bucket policy.
 *
 * So the answer is declared here, by whoever wired the adapter, and
 * {@see FlysystemContentAddressableStore} refuses to exist without it. That is
 * the whole point: capability follows the class you configured, not a runtime
 * probe. The tempting probe — `fileExists()` then `write()` — is exactly the
 * race the ledger exists to prevent, with two writers each observing "absent"
 * and one of them reading the other's half-written object.
 *
 * Both flags must be true. A permissive default that still constructed would
 * make the safe path opt-*out*, and the failure it hides is silent corruption
 * discovered months later.
 *
 * @api
 */
final readonly class AdapterSemantics
{
    /**
     * @param bool $atomicVisibility An object becomes visible whole or not at
     *        all — a reader never sees a partially uploaded body. True for S3
     *        and S3-compatible services; false for a plain FTP or SFTP adapter
     *        writing in place, and for a local adapter without staging.
     * @param bool $immutableContentKeys Nothing but this package writes under
     *        the content-addressed prefix, and it never rewrites a key. False
     *        the moment a bucket is shared with a process that can overwrite
     *        arbitrary paths, or a lifecycle rule rewrites objects in place.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public bool $atomicVisibility,
        public bool $immutableContentKeys,
    ) {
        if (!$atomicVisibility || !$immutableContentKeys) {
            throw new InvalidArgumentException(
                'Deduplication requires an adapter with both atomic visibility and immutable content keys. '
                . 'Declare AdapterSemantics::guaranteed() only for a store that has them — otherwise keep the '
                . 'plain FlysystemStore binding, which stays unique-only and is always safe',
            );
        }
    }

    /**
     * Reads as an assertion at the call site, which is what it is.
     */
    public static function guaranteed(): self
    {
        return new self(atomicVisibility: true, immutableContentKeys: true);
    }
}
