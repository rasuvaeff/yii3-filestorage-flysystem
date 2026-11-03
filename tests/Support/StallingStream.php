<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * An unsized, seekable stream whose first read comes back empty although the
 * end has not been reached — what a stalled source looks like to a reader that
 * counts bytes.
 *
 * @internal
 */
final class StallingStream implements StreamInterface
{
    private bool $stalled = false;

    public function __construct(private readonly StreamInterface $stream) {}

    #[Override]
    public function read(int $length): string
    {
        if (!$this->stalled) {
            $this->stalled = true;

            return '';
        }

        return $this->stream->read($length);
    }

    #[Override]
    public function eof(): bool
    {
        return $this->stalled && $this->stream->eof();
    }

    #[Override]
    public function rewind(): void
    {
        $this->stalled = false;
        $this->stream->rewind();
    }

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
