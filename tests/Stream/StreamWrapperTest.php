<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Stream;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageFlysystem\Stream\StreamWrapper;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\UnseekableStream;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\UnsizedStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StreamWrapper::class)]
final class StreamWrapperTest
{
    public function readsThroughToThePsrStream(): void
    {
        $resource = StreamWrapper::wrap((new Psr17Factory())->createStream('hello world'));

        Assert::same(stream_get_contents($resource), 'hello world');

        fclose($resource);
    }

    /**
     * Flysystem reads in chunks, and the wrapper has to keep its place between
     * them — a wrapper that restarted would produce an object made of repeated
     * first chunks.
     */
    public function keepsItsPlaceAcrossReads(): void
    {
        $resource = StreamWrapper::wrap((new Psr17Factory())->createStream('abcdef'));

        Assert::same(fread($resource, 2), 'ab');
        Assert::same(ftell($resource), 2);
        Assert::same(fread($resource, 2), 'cd');
        Assert::same(stream_get_contents($resource), 'ef');
        Assert::true(feof($resource));

        fclose($resource);
    }

    /**
     * Some adapters stat the resource before uploading — S3 uses the size to
     * decide between a single PUT and a multipart upload.
     */
    public function reportsTheSizeThroughFstat(): void
    {
        $resource = StreamWrapper::wrap((new Psr17Factory())->createStream('hello'));
        $stat = fstat($resource);

        Assert::same($stat['size'] ?? null, 5);
        // A read-only regular file, and zeroes for everything a stream has no
        // answer for. Callers index into the numeric half, so the shape is as
        // much a contract as the size is.
        Assert::same($stat['mode'] ?? null, 0o100_444);
        Assert::same($stat['blksize'] ?? null, -1);
        Assert::same($stat['blocks'] ?? null, -1);
        foreach (['dev', 'ino', 'nlink', 'uid', 'gid', 'rdev', 'atime', 'mtime', 'ctime'] as $field) {
            Assert::same($stat[$field] ?? null, 0, "unexpected {$field}");
        }

        fclose($resource);
    }

    /**
     * A stream that cannot report its length still has to stat: the SDK reads
     * `size` to choose between a single PUT and a multipart upload, and a
     * missing key there is a TypeError rather than a fallback.
     */
    public function anUnsizedStreamStatsAsZero(): void
    {
        $resource = StreamWrapper::wrap(new UnsizedStream((new Psr17Factory())->createStream('hello')));

        Assert::same(fstat($resource)['size'] ?? null, 0);

        fclose($resource);
    }

    /**
     * Write modes are refused: the upload is the source, never the target, and
     * a wrapper that accepted `w` would silently discard everything written.
     */
    public function openingForWritingIsRefused(): void
    {
        $stream = (new Psr17Factory())->createStream('hello');
        StreamWrapper::register();
        $context = stream_context_create([StreamWrapper::PROTOCOL => ['stream' => $stream]]);

        Assert::false(@fopen(StreamWrapper::PROTOCOL . '://stream', 'wb', false, $context));
    }

    /**
     * A non-seekable body — the shape `Upload` spools precisely because most
     * things downstream cannot cope — must report that rather than pretend.
     */
    public function seekingIsRefusedWhenTheStreamCannot(): void
    {
        $resource = StreamWrapper::wrap(new UnseekableStream((new Psr17Factory())->createStream('abcdef')));

        Assert::same(@fseek($resource, 3), -1);

        fclose($resource);
    }

    public function seeksWhenTheUnderlyingStreamCan(): void
    {
        $resource = StreamWrapper::wrap((new Psr17Factory())->createStream('abcdef'));

        Assert::same(fseek($resource, 3), 0);
        Assert::same(stream_get_contents($resource), 'def');

        fclose($resource);
    }

    /**
     * Closing the resource must not close the upload's stream: `Upload`
     * promises `stream()` is readable again next time, and a retry after a
     * failed write depends on it.
     */
    public function closingTheResourceLeavesTheUploadStreamUsable(): void
    {
        $stream = (new Psr17Factory())->createStream('hello');
        $resource = StreamWrapper::wrap($stream);

        fclose($resource);

        $stream->rewind();
        Assert::same($stream->getContents(), 'hello');
    }

    /**
     * Registration is idempotent — several stores in one process each wrap
     * their uploads, and `stream_wrapper_register()` fails on a duplicate.
     */
    public function registrationIsIdempotent(): void
    {
        StreamWrapper::register();
        StreamWrapper::register();

        Assert::true(\in_array(StreamWrapper::PROTOCOL, stream_get_wrappers(), true));
    }

    /**
     * Opened without the context the factory supplies, there is no stream to
     * read — and answering with an empty file would be worse than refusing.
     */
    public function openingWithoutAStreamInTheContextFails(): void
    {
        StreamWrapper::register();

        Assert::false(@fopen(StreamWrapper::PROTOCOL . '://stream', 'rb'));
    }
}
