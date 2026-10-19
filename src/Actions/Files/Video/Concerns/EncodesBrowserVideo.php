<?php

namespace EduLazaro\Laracrate\Actions\Files\Video\Concerns;

use Illuminate\Support\Str;

/**
 * Encodes a video as H.264 / AAC mp4 that every browser plays.
 *
 * The output is always 8-bit 4:2:0 (yuv420p), the only H.264 flavour every browser
 * plays. Without forcing it, ffmpeg keeps the input's pixel format: a 10-bit HDR clip
 * from a phone becomes H.264 High 10, which Safari and iOS refuse to play. HDR input
 * (PQ or HLG) is also tone-mapped to SDR BT.709 when ffmpeg has zscale, otherwise it
 * would play with washed-out colours.
 *
 * Requires ffmpeg and ffprobe on the path.
 */
trait EncodesBrowserVideo
{
    /** The encoded binary, scaled down within the bounds, or null when ffmpeg fails. */
    protected function encode(string $inPath, int $maxWidth, int $maxHeight, int $bitrate): ?string
    {
        $toneMap = $this->isHdr($inPath) && $this->hasZscale();

        return $this->ffmpeg(sprintf(
            '-i %s -vf %s -c:v libx264 -preset fast -b:v %dk%s -c:a aac -movflags +faststart',
            escapeshellarg($inPath),
            escapeshellarg($this->videoFilter($maxWidth, $maxHeight, $toneMap)),
            $bitrate,
            $toneMap ? ' ' . $this->bt709() : ''
        ), 'mp4');
    }

    /** Runs `ffmpeg -y {$arguments} <output>` and returns the output, or null when it fails. */
    protected function ffmpeg(string $arguments, string $extension): ?string
    {
        $outPath = sys_get_temp_dir() . '/laracrate_ffmpeg_' . Str::random(16) . '.' . $extension;
        $cmd = sprintf('ffmpeg -y %s %s 2>&1', $arguments, escapeshellarg($outPath));

        exec($cmd, $output, $code);

        if ($code !== 0 || !is_file($outPath)) {
            logger()->warning('Laracrate: ffmpeg failed', [
                'cmd' => $cmd, 'output' => array_slice($output, -20), 'code' => $code,
            ]);
            @unlink($outPath);
            return null;
        }

        $bin = file_get_contents($outPath);
        @unlink($outPath);

        return $bin === false ? null : $bin;
    }

    /** Colour flags of a tone-mapped output. */
    protected function bt709(): string
    {
        return '-colorspace bt709 -color_primaries bt709 -color_trc bt709';
    }

    /** Length of the video in seconds, from the file or, when it has none, from ffprobe. */
    protected function durationOf(\EduLazaro\Laracrate\Models\File $file, string $path): float
    {
        if ($file->duration) {
            return (float) $file->duration;
        }

        return (float) trim((string) shell_exec(sprintf(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 %s',
            escapeshellarg($path)
        )));
    }

    /** Whether the video has an audio stream. */
    protected function hasAudio(string $path): bool
    {
        return trim((string) shell_exec(sprintf(
            'ffprobe -v error -select_streams a -show_entries stream=index -of csv=p=0 %s',
            escapeshellarg($path)
        ))) !== '';
    }

    /**
     * Whether the video plays as it is in every browser: H.264 8-bit 4:2:0 (not HDR) in
     * MP4/MOV, with AAC or MP3 audio or none. Range requests take care of an index at
     * the end, so there is nothing a copy would add.
     */
    protected function playsEverywhere(string $path): bool
    {
        $probe = json_decode((string) shell_exec(sprintf(
            'ffprobe -v error -show_entries format=format_name:stream=codec_type,codec_name,pix_fmt,color_transfer -of json %s',
            escapeshellarg($path)
        )), true);

        if (! is_array($probe) || ! str_contains($probe['format']['format_name'] ?? '', 'mp4')) {
            return false;
        }

        foreach ($probe['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? null;
            $codec = $stream['codec_name'] ?? null;

            if ($type === 'video' && ($codec !== 'h264' || ($stream['pix_fmt'] ?? null) !== 'yuv420p'
                || in_array($stream['color_transfer'] ?? '', ['smpte2084', 'arib-std-b67'], true))) {
                return false;
            }

            if ($type === 'audio' && ! in_array($codec, ['aac', 'mp3'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scale down within the bounds to even dimensions (libx264 rejects odd ones), tone-map
     * HDR to SDR when asked, and end in yuv420p.
     */
    protected function videoFilter(int $maxWidth, int $maxHeight, bool $toneMap): string
    {
        $filters = [
            "scale='if(gt(iw,{$maxWidth}),{$maxWidth},iw)':'if(gt(ih,{$maxHeight}),{$maxHeight},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
        ];

        if ($toneMap) {
            $filters[] = 'zscale=t=linear:npl=100';
            $filters[] = 'format=gbrpf32le';
            $filters[] = 'zscale=p=bt709';
            $filters[] = 'tonemap=tonemap=hable:desat=0';
            $filters[] = 'zscale=t=bt709:m=bt709:r=tv';
        }

        $filters[] = 'format=yuv420p';

        return implode(',', $filters);
    }

    /**
     * Whether the first video stream uses an HDR transfer: PQ (HDR10, Dolby Vision) or
     * HLG (iPhone, broadcast).
     */
    protected function isHdr(string $path): bool
    {
        $transfer = trim((string) shell_exec(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=color_transfer -of csv=p=0 %s',
            escapeshellarg($path)
        )));

        return in_array($transfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /** zscale needs ffmpeg built with libzimg; without it HDR is converted but not tone-mapped. */
    protected function hasZscale(): bool
    {
        static $available = null;

        return $available ??= str_contains((string) shell_exec('ffmpeg -hide_banner -filters 2>/dev/null'), ' zscale ');
    }
}
