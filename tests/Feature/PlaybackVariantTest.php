<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Pipeline\Steps\Video\TranscodeVideoStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `'transcode' => 'variant'` keeps the original and adds a browser-safe `playback`
 * copy; `'transcode' => true` still replaces the original. Needs ffmpeg.
 */
class PlaybackVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! trim((string) shell_exec('command -v ffmpeg'))) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        Storage::fake('media');
    }

    /** A one-second clip; yuv444p is H.264 that browsers do not play. */
    private function clip(string $transcode, string $pixFmt): File
    {
        $path = sys_get_temp_dir() . '/laracrate_clip_' . Str::random(8) . '.mp4';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i testsrc=duration=1:size=64x48:rate=10 -c:v libx264 -pix_fmt %s %s 2>&1',
            $pixFmt,
            escapeshellarg($path)
        ));
        $binary = file_get_contents($path);
        @unlink($path);

        config()->set('laracrate.collections.clips', [
            'disk'   => 'media',
            'access' => 'signed',
            'types'  => ['video' => ['transcode' => $transcode === 'true' ? true : $transcode]],
        ]);

        $file = File::create([
            'disk'          => 'media',
            'path'          => 'clips/' . Str::random(8) . '.mp4',
            'name'          => 'clip.mp4',
            'original_name' => 'clip.mp4',
            'extension'     => 'mp4',
            'mime_type'     => 'video/mp4',
            'size'          => strlen($binary),
            'context'       => 'media',
            'collection'    => 'clips',
            'type'          => FileType::VIDEO,
            'access'        => 'signed',
        ]);
        Storage::disk('media')->put($file->key, $binary);

        // As the queued job gets it: with the column defaults loaded.
        return $file->fresh();
    }

    private function probe(File $file): string
    {
        $path = sys_get_temp_dir() . '/laracrate_probe_' . Str::random(8) . '.mp4';
        file_put_contents($path, Storage::disk('media')->get($file->key));
        $out = trim((string) shell_exec(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,pix_fmt -of csv=p=0 %s',
            escapeshellarg($path)
        )));
        @unlink($path);

        return $out;
    }

    public function test_variant_mode_keeps_the_original_and_adds_a_playback_copy(): void
    {
        $file = $this->clip('variant', 'yuv444p');
        $original = Storage::disk('media')->get($file->key);

        (new TranscodeVideoStep())->handle($file);

        $file->refresh();
        $this->assertSame($original, Storage::disk('media')->get($file->key));

        $playback = $file->playback();
        $this->assertNotSame($file->id, $playback->id);
        $this->assertSame('playback', $playback->variant);
        $this->assertSame('video/mp4', $playback->mime_type);
        $this->assertSame('h264,yuv420p', $this->probe($playback));
    }

    public function test_variant_mode_skips_a_video_that_already_plays_everywhere(): void
    {
        $file = $this->clip('variant', 'yuv420p');

        (new TranscodeVideoStep())->handle($file);

        $this->assertSame(0, $file->children()->count());
        $this->assertSame($file->id, $file->refresh()->playback()->id);
    }

    public function test_regenerating_replaces_the_previous_playback_copy(): void
    {
        $file = $this->clip('variant', 'yuv444p');

        (new TranscodeVideoStep())->handle($file);
        (new TranscodeVideoStep())->handle($file->refresh());

        $this->assertSame(1, $file->children()->where('variant', 'playback')->count());
    }

    public function test_true_still_replaces_the_original(): void
    {
        $file = $this->clip('true', 'yuv444p');

        (new TranscodeVideoStep())->handle($file);

        $file->refresh();
        $this->assertSame(0, $file->children()->count());
        $this->assertSame('h264,yuv420p', $this->probe($file));
    }
}
