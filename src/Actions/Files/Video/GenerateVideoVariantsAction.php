<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Actions\Files\Video\Concerns\EncodesBrowserVideo;
use EduLazaro\Laracrate\Contracts\MomentsGenerator;
use EduLazaro\Laracrate\Enums\FileMode;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\Variants;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates the video type's `variants` next to the untouched original, each under the
 * name the app gives it. Its `type` says what it is:
 *
 *   'web',                                                     // video: an mp4 copy
 *   'sd'      => ['max_height' => 480, 'bitrate_kbps' => 800],
 *   'teaser'  => ['type' => 'video', 'clips' => 5, 'seconds' => 1, 'audio' => false],
 *   'portada' => ['type' => 'image', 'frame_at' => '00:00:03', 'width' => 1280],
 *   'escenas' => ['type' => 'frames', 'frames' => 6, 'width' => 320],     // an image, mode `frames`
 *
 * A video variant with `clips` or a `generator` is a teaser: those moments joined, with
 * no audio unless `audio` is true. A `generator` (a MomentsGenerator) picks the moments
 * of a teaser or of frames; without one they are spread evenly. `frame_at` takes a time
 * ('00:00:03'), seconds (3.5) or a share of the length ('20%'). A variant's own
 * `variants` are made from it: `teaser.mini`, `portada.thumbnail`.
 *
 * A plain copy is skipped when the original already plays in every browser and fits its
 * size: variant() then falls back to the original. Regenerating replaces the variants.
 *
 * Requires ffmpeg on the path.
 */
class GenerateVideoVariantsAction extends Action
{
    use EncodesBrowserVideo;

    public function handle(File $file, array $variants): File
    {
        $variants = Variants::normalize($variants);

        if (!$file->isVideo() || $variants === []) {
            return $file;
        }

        try {
            app(StorageManager::class)->withLocalCopy($file, function (string $inPath) use ($file, $variants) {
                foreach ($variants as $name => $spec) {
                    // Regenerating replaces the previous one.
                    $file->children()->where('variant', $name)->first()?->forceDelete();

                    $variant = match ($spec['type'] ?? 'video') {
                        'image'  => $this->frame($file, $inPath, $name, $spec),
                        'frames' => $this->frames($file, $inPath, $name, $spec),
                        default  => isset($spec['clips']) || isset($spec['generator'])
                            ? $this->teaser($file, $inPath, $name, $spec)
                            : $this->copy($file, $inPath, $name, $spec),
                    };

                    if ($variant && !empty($spec['variants'])) {
                        $this->nested($variant, Variants::normalize($spec['variants']));
                    }
                }
            });
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to generate video variants', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $file;
    }

    /** An mp4 copy within the variant's size and bitrate. */
    protected function copy(File $file, string $inPath, string $name, array $spec): ?File
    {
        [$maxWidth, $maxHeight, $bitrate] = $this->bounds($spec);

        if ($this->fits($file, $maxWidth, $maxHeight) && $this->playsEverywhere($inPath)) {
            return null;
        }

        $binary = $this->encode($inPath, $maxWidth, $maxHeight, $bitrate);

        return $binary === null ? null : $this->store($file, $name, $binary, 'mp4', 'video/mp4', FileType::VIDEO);
    }

    /** The moments of the video joined into a short mp4. */
    protected function teaser(File $file, string $inPath, string $name, array $spec): ?File
    {
        $duration = $this->durationOf($file, $inPath);
        $moments  = $this->moments($file, $spec, $duration, (int) ($spec['clips'] ?? 5), (float) ($spec['seconds'] ?? 1));

        if ($moments === []) {
            return null;
        }

        [$maxWidth, $maxHeight, $bitrate] = $this->bounds($spec);
        $audio   = (bool) ($spec['audio'] ?? false) && $this->hasAudio($inPath);
        $toneMap = $this->isHdr($inPath) && $this->hasZscale();

        $graph  = [];
        $inputs = '';
        foreach ($moments as $i => $moment) {
            $graph[] = sprintf('[0:v]trim=start=%.3F:duration=%.3F,setpts=PTS-STARTPTS[v%d]', $moment['at'], $moment['seconds'], $i);
            $inputs .= "[v{$i}]";

            if ($audio) {
                $graph[] = sprintf('[0:a]atrim=start=%.3F:duration=%.3F,asetpts=PTS-STARTPTS[a%d]', $moment['at'], $moment['seconds'], $i);
                $inputs .= "[a{$i}]";
            }
        }
        $graph[] = sprintf('%sconcat=n=%d:v=1:a=%d[joined]%s', $inputs, count($moments), $audio ? 1 : 0, $audio ? '[audio]' : '');
        $graph[] = '[joined]' . $this->videoFilter($maxWidth, $maxHeight, $toneMap) . '[out]';

        $binary = $this->ffmpeg(sprintf(
            '-i %s -filter_complex %s -map "[out]" %s -c:v libx264 -preset fast -b:v %dk%s -movflags +faststart',
            escapeshellarg($inPath),
            escapeshellarg(implode(';', $graph)),
            $audio ? '-map "[audio]" -c:a aac' : '-an',
            $bitrate,
            $toneMap ? ' ' . $this->bt709() : ''
        ), 'mp4');

        return $binary === null ? null : $this->store($file, $name, $binary, 'mp4', 'video/mp4', FileType::VIDEO, [
            'clips' => array_map(fn (array $moment) => [$moment['at'], $moment['seconds']], $moments),
        ]);
    }

    /** One frame as a jpg. */
    protected function frame(File $file, string $inPath, string $name, array $spec): ?File
    {
        $duration = $this->durationOf($file, $inPath);
        $at       = $this->position($spec['frame_at'] ?? '00:00:01', $duration);

        // A moment past the end (one second into a shorter clip) takes the last frame.
        if ($duration > 0) {
            $at = min($at, max(0.0, $duration - 0.1));
        }

        $binary = $this->grab($inPath, $at, $spec['width'] ?? null, $spec['height'] ?? null);

        return $binary === null ? null : $this->store($file, $name, $binary, 'jpg', 'image/jpeg', FileType::IMAGE, ['at' => $at]);
    }

    /**
     * A strip of frames in one jpg, side by side (or in `columns` per row). Its metadata
     * says how to cut it: frames, columns, rows, the width and height of each frame, and
     * the second each one was taken at.
     */
    protected function frames(File $file, string $inPath, string $name, array $spec): ?File
    {
        $duration = $this->durationOf($file, $inPath);
        $moments  = $this->moments($file, $spec, $duration, (int) ($spec['frames'] ?? 6), 0.0);
        $width    = isset($spec['height']) && !isset($spec['width']) ? null : (int) ($spec['width'] ?? 320);

        $dir = sys_get_temp_dir() . '/laracrate_frames_' . Str::random(16);
        @mkdir($dir);

        try {
            $taken = [];
            foreach ($moments as $moment) {
                $binary = $this->grab($inPath, $moment['at'], $width, $spec['height'] ?? null);

                if ($binary !== null) {
                    file_put_contents(sprintf('%s/frame_%03d.jpg', $dir, count($taken)), $binary);
                    $taken[] = $moment['at'];
                }
            }

            if ($taken === []) {
                return null;
            }

            [$frameWidth, $frameHeight] = @getimagesize("{$dir}/frame_000.jpg") ?: [null, null];
            $columns = max(1, min(count($taken), (int) ($spec['columns'] ?? count($taken))));
            $rows    = (int) ceil(count($taken) / $columns);

            $strip = $this->ffmpeg(sprintf(
                '-i %s -vf %s -frames:v 1 -q:v 2',
                escapeshellarg("{$dir}/frame_%03d.jpg"),
                escapeshellarg("tile={$columns}x{$rows}")
            ), 'jpg');
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }

        return $strip === null ? null : $this->store($file, $name, $strip, 'jpg', 'image/jpeg', FileType::IMAGE, [
            'frames'  => count($taken),
            'columns' => $columns,
            'rows'    => $rows,
            'width'   => $frameWidth,
            'height'  => $frameHeight,
            'at'      => $taken,
        ], FileMode::FRAMES);
    }

    /** The variant's own variants, made from it. */
    protected function nested(File $variant, array $variants): void
    {
        if ($variant->isVideo()) {
            static::create()->run(['file' => $variant, 'variants' => $variants]);

            return;
        }

        GenerateImageVariantsAction::create()->run(['file' => $variant, 'variants' => $variants]);
    }

    /**
     * The moments to take, each with its start and length: from the variant's generator,
     * or spread evenly when it has none or it gives nothing usable.
     *
     * @return list<array{at: float, seconds: float}>
     */
    protected function moments(File $file, array $spec, float $duration, int $count, float $seconds): array
    {
        if ($duration <= 0) {
            return [];
        }

        if (!empty($spec['generator'])) {
            try {
                $generator = app($spec['generator']);

                if ($generator instanceof MomentsGenerator) {
                    $moments = [];
                    foreach ($generator->generate($file, $spec) as $moment) {
                        $at = (float) ($moment['at'] ?? -1);

                        if ($at >= 0 && $at < $duration) {
                            $moments[] = ['at' => $at, 'seconds' => min((float) ($moment['seconds'] ?? $seconds), $duration - $at)];
                        }
                    }

                    if ($moments !== []) {
                        return $moments;
                    }
                }
            } catch (Throwable $e) {
                logger()->warning('Laracrate: the moments generator failed, spreading evenly', [
                    'file_id'   => $file->id,
                    'generator' => $spec['generator'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $count   = max(1, $count);
        $moments = [];
        for ($i = 0; $i < $count; $i++) {
            $center = ($i + 0.5) * $duration / $count;
            $at     = max(0.0, $center - $seconds / 2);
            $moments[] = ['at' => round($at, 3), 'seconds' => min($seconds, $duration - $at)];
        }

        return $moments;
    }

    /** Seconds from a time ('00:01:20'), a number of seconds, or a share of the length ('20%'). */
    protected function position(string|int|float $at, float $duration): float
    {
        $at = trim((string) $at);

        if (str_ends_with($at, '%')) {
            return round($duration * min(100, max(0, (float) $at)) / 100, 3);
        }

        if (str_contains($at, ':')) {
            $seconds = 0.0;
            foreach (explode(':', $at) as $part) {
                $seconds = $seconds * 60 + (float) $part;
            }

            return $seconds;
        }

        return (float) $at;
    }

    /** A frame at $at seconds as a jpg, scaled when asked. */
    protected function grab(string $inPath, float $at, ?int $width, ?int $height): ?string
    {
        $scale = match (true) {
            $width && $height => "scale={$width}:{$height}:force_original_aspect_ratio=decrease",
            (bool) $width     => "scale={$width}:-2",
            (bool) $height    => "scale=-2:{$height}",
            default           => null,
        };

        return $this->ffmpeg(sprintf(
            '-ss %.3F -i %s -frames:v 1%s -q:v 2',
            $at,
            escapeshellarg($inPath),
            $scale ? ' -vf ' . escapeshellarg($scale) : ''
        ), 'jpg');
    }

    /** @return array{int, int, int} max width, max height and bitrate (kbps) of a video variant */
    protected function bounds(array $spec): array
    {
        return [
            (int) ($spec['max_width']    ?? config('laracrate.video.max_width', 1920)),
            (int) ($spec['max_height']   ?? config('laracrate.video.max_height', 1920)),
            (int) ($spec['bitrate_kbps'] ?? config('laracrate.video.bitrate_kbps', 2500)),
        ];
    }

    /** Whether the original is already within the bounds, so a copy would only re-encode it. */
    protected function fits(File $file, int $maxWidth, int $maxHeight): bool
    {
        return $file->width && $file->height && $file->width <= $maxWidth && $file->height <= $maxHeight;
    }

    /** Store the binary as the file's variant called $name. */
    protected function store(File $file, string $name, string $binary, string $extension, string $mime, FileType $type, array $metadata = [], FileMode $mode = FileMode::DEFAULT): File
    {
        $fileName = Str::beforeLast($file->name, '.') . '_' . Str::slug($name) . '.' . $extension;
        $key      = $file->variantKey($fileName);

        app(StorageManager::class)->writeBinary($file->disk, $key, $binary, $mime);

        [$width, $height] = $type === FileType::IMAGE ? (@getimagesizefromstring($binary) ?: [null, null]) : [null, null];

        $variant = $file->createVariant($name, array_filter([
            'path'          => $key,
            'name'          => $fileName,
            'original_name' => $fileName,
            'extension'     => $extension,
            'mime_type'     => $mime,
            'size'          => strlen($binary),
            'type'          => $type,
            'mode'          => $mode,
            'width'         => $width,
            'height'        => $height,
            'metadata'      => $metadata ?: null,
        ], fn ($value) => $value !== null));

        if ($type === FileType::VIDEO) {
            ExtractVideoDimensionsAction::create()->run(['file' => $variant]);
        }

        return $variant;
    }
}
