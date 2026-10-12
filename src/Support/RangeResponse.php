<?php

namespace EduLazaro\Laracrate\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file honouring the HTTP `Range` header (206 Partial Content).
 *
 * Players need it: without ranges a browser cannot seek in an audio or video,
 * cannot start a video whose index (`moov`) sits at the end until the whole file
 * has arrived, and Safari refuses to play video at all.
 *
 * Only the requested bytes are read: an S3 ranged GET on R2/S3, a seek on local
 * disks. Content already in memory (a decrypted file) is sliced.
 */
final class RangeResponse
{
    private const CHUNK = 1048576;

    /**
     * Response for `$key` on `$disk`, or for `$content` when the bytes are already
     * in memory. `$headers` carries Content-Type, Content-Disposition and the like.
     */
    public static function make(Request $request, Filesystem $disk, string $key, array $headers, ?string $content = null): StreamedResponse
    {
        $size = $content !== null ? strlen($content) : (int) $disk->size($key);
        $range = self::parse($request->header('Range'), $size);

        if ($range === false) {
            return new StreamedResponse(null, 416, [
                'Content-Range' => "bytes */{$size}",
                'Accept-Ranges' => 'bytes',
            ]);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = max(0, $end - $start + 1);

        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Length'] = (string) $length;

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $partial = $range !== null && $length < $size;

        return new StreamedResponse(function () use ($disk, $key, $content, $start, $end, $length, $partial) {
            if ($content !== null) {
                echo substr($content, $start, $length);
                return;
            }

            if ($length === 0) {
                return;
            }

            $stream = self::open($disk, $key, $start, $end, $partial);
            $remaining = $length;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(self::CHUNK, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }

            fclose($stream);
        }, $range !== null ? 206 : 200, $headers);
    }

    /**
     * The requested byte span of a single `bytes=` range, inclusive. Null when there
     * is no usable header (serve the whole file), false when it cannot be satisfied.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parse(?string $header, int $size): array|false|null
    {
        // Multiple ranges are allowed to be answered with the whole file.
        if (! $header || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        // Suffix range: the last N bytes.
        if ($m[1] === '') {
            $last = (int) $m[2];

            return $last === 0 || $size === 0 ? false : [max(0, $size - $last), $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        return $start >= $size || $start > $end ? false : [$start, $end];
    }

    /**
     * A stream positioned at `$start`: a ranged GET on S3/R2, a seek elsewhere, or
     * skipping bytes when the stream cannot seek.
     *
     * @return resource
     */
    private static function open(Filesystem $disk, string $key, int $start, int $end, bool $partial)
    {
        if ($partial && $disk instanceof AwsS3V3Adapter) {
            $result = $disk->getClient()->getObject([
                'Bucket' => $disk->getConfig()['bucket'],
                'Key'    => $disk->path($key),
                'Range'  => "bytes={$start}-{$end}",
            ]);

            return $result['Body']->detach();
        }

        $stream = $disk->readStream($key);

        if ($start > 0) {
            if (stream_get_meta_data($stream)['seekable']) {
                fseek($stream, $start);
            } else {
                for ($skip = $start; $skip > 0 && ! feof($stream);) {
                    $skip -= strlen((string) fread($stream, min(self::CHUNK, $skip)));
                }
            }
        }

        return $stream;
    }
}
