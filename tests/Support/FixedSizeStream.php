<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that declares a size independent of its actual content — the
 * ordinary case for a well-behaved upload (its `Content-Length` header), used
 * here to prove that a declared size is trusted rather than re-measured.
 *
 * @internal
 */
final readonly class FixedSizeStream implements StreamInterface
{
    public function __construct(
        private StreamInterface $stream,
        private int $declaredSize,
    ) {}

    #[Override]
    public function getSize(): int
    {
        return $this->declaredSize;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->stream->__toString();
    }

    #[Override]
    public function close(): void
    {
        $this->stream->close();
    }

    #[Override]
    public function detach()
    {
        return $this->stream->detach();
    }

    #[Override]
    public function tell(): int
    {
        return $this->stream->tell();
    }

    #[Override]
    public function eof(): bool
    {
        return $this->stream->eof();
    }

    #[Override]
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    #[Override]
    public function rewind(): void
    {
        $this->stream->rewind();
    }

    #[Override]
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    #[Override]
    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    #[Override]
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    #[Override]
    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    #[Override]
    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
