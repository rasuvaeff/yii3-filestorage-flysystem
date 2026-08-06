<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Exposes a PSR-7 stream to Flysystem as a PHP resource.
 *
 * `FilesystemWriter::writeStream()` takes a resource; `Upload::stream()` gives a
 * `StreamInterface`. The obvious bridge — copy the stream into `php://temp` and
 * hand that over — doubles the I/O of every upload and, for anything larger
 * than memory, writes the whole file to disk a second time before it goes
 * anywhere. This is the standard alternative: a user stream wrapper that reads
 * through, so the bytes are copied once, by Flysystem, straight to the adapter.
 *
 * `detach()` would be cheaper still and is wrong: `Upload` promises that
 * `stream()` returns a rewound stream *every* time, and a detached stream
 * cannot honour that for the next caller.
 *
 * @internal
 */
final class StreamWrapper
{
    public const string PROTOCOL = 'rasuvaeff-filestorage';

    /** @var resource|null */
    public $context;

    /** Set by {@see stream_open()}, which PHP calls before anything else. */
    private ?StreamInterface $stream = null;

    /**
     * @return resource
     */
    public static function wrap(StreamInterface $stream)
    {
        self::register();

        $context = stream_context_create([self::PROTOCOL => ['stream' => $stream]]);
        $resource = @fopen(self::PROTOCOL . '://stream', 'rb', false, $context);

        if ($resource === false) {
            throw new RuntimeException('Could not open the upload stream as a resource');
        }

        return $resource;
    }

    public static function register(): void
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        // Read-only by construction: the upload is the source, never the target.
        if (!str_contains($mode, 'r')) {
            return false;
        }

        // PHP assigns `$context` from the outside, so it is read into a local
        // and narrowed there rather than across a guard on the property.
        $context = $this->context;
        $stream = \is_resource($context)
            ? stream_context_get_options($context)[self::PROTOCOL]['stream'] ?? null
            : null;

        if (!$stream instanceof StreamInterface) {
            return false;
        }

        $this->stream = $stream;
        $openedPath = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        return $this->stream?->read($count) ?? '';
    }

    public function stream_eof(): bool
    {
        return $this->stream?->eof() ?? true;
    }

    public function stream_tell(): int
    {
        return $this->stream?->tell() ?? 0;
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        if (!$this->stream instanceof \Psr\Http\Message\StreamInterface || !$this->stream->isSeekable()) {
            return false;
        }

        $this->stream->seek($offset, $whence);

        return true;
    }

    /**
     * @return array<int|string, int|false>
     */
    public function stream_stat(): array
    {
        $size = $this->stream?->getSize();

        // `size` is the only field anything downstream reads; the rest are
        // present because `fstat()` callers index into the numeric half.
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0o100_444, 'nlink' => 0,
            'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => $size ?? 0,
            'atime' => 0, 'mtime' => 0, 'ctime' => 0,
            'blksize' => -1, 'blocks' => -1,
        ];
    }

    public function stream_close(): void
    {
        // The stream belongs to the Upload, which may be read again — closing
        // it here would make a retry after a failed write impossible.
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
