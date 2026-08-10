<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that refuses to seek.
 *
 * What an S3 read body looks like, and what `Upload` spools away before
 * anything downstream sees it — so the wrapper's own refusal to seek is only
 * observable here.
 *
 * @internal
 */
final readonly class UnseekableStream implements StreamInterface
{
    public function __construct(private StreamInterface $stream) {}

    #[Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[Override]
    public function getSize(): ?int
    {
        return $this->stream->getSize();
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
    public function detach(): mixed
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
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
