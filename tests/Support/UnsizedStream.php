<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A seekable stream that will not say how long it is.
 *
 * This is what makes the second half of the byte cap reachable: with a known
 * size the store refuses before writing, so the post-write verification — the
 * part that matters for a body that under-reports — would never run.
 *
 * @internal
 */
final readonly class UnsizedStream implements StreamInterface
{
    public function __construct(private StreamInterface $stream) {}

    #[Override]
    public function getSize(): ?int
    {
        return null;
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
