<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Pipeline\Steps\Video\GenerateVideoVariantsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\TranscodeVideoStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The video type's `variants` are mp4 copies named by the app, next to the untouched
 * original; `'transcode' => true` replaces the original. Needs ffmpeg.
 */
class VideoVariantsTest extends TestCase
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

    /** A one-second 64x48 clip; yuv444p is H.264 that browsers do not play. */
    private function clip(array $videoConfig, string $pixFmt): File
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
            'types'  => ['video' => $videoConfig],
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
            'width'         => 64,
            'height'        => 48,
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

    public function test_a_variant_keeps_the_original_and_adds_a_copy_with_its_name(): void
    {
        $file = $this->clip(['variants' => ['web' => []]], 'yuv444p');
        $original = Storage::disk('media')->get($file->key);

        (new GenerateVideoVariantsStep())->handle($file);

        $file->refresh();
        $this->assertSame($original, Storage::disk('media')->get($file->key));

        $web = $file->variant('web');
        $this->assertNotSame($file->id, $web->id);
        $this->assertSame('web', $web->variant);
        $this->assertSame('video/mp4', $web->mime_type);
        $this->assertSame('h264,yuv420p', $this->probe($web));
    }

    public function test_every_declared_variant_is_generated_within_its_size(): void
    {
        $file = $this->clip(['variants' => ['web', 'small' => ['max_height' => 24]]], 'yuv444p');

        (new GenerateVideoVariantsStep())->handle($file);

        $this->assertSame(['small', 'web'], $file->children()->orderBy('variant')->pluck('variant')->all());
        $this->assertSame(24, $file->refresh()->variant('small')->height);
    }

    public function test_a_variant_is_skipped_when_the_original_plays_everywhere_and_fits(): void
    {
        $file = $this->clip(['variants' => ['web' => []]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $this->assertSame(0, $file->children()->count());
        $this->assertSame($file->id, $file->refresh()->variant('web')->id);
    }

    public function test_a_smaller_variant_is_made_even_when_the_original_plays_everywhere(): void
    {
        $file = $this->clip(['variants' => ['small' => ['max_height' => 24]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $this->assertSame(1, $file->children()->where('variant', 'small')->count());
    }

    public function test_regenerating_replaces_the_previous_copy(): void
    {
        $file = $this->clip(['variants' => ['web' => []]], 'yuv444p');

        (new GenerateVideoVariantsStep())->handle($file);
        (new GenerateVideoVariantsStep())->handle($file->refresh());

        $this->assertSame(1, $file->children()->where('variant', 'web')->count());
    }

    public function test_transcode_replaces_the_original(): void
    {
        $file = $this->clip(['transcode' => true], 'yuv444p');

        (new TranscodeVideoStep())->handle($file);

        $file->refresh();
        $this->assertSame(0, $file->children()->count());
        $this->assertSame('h264,yuv420p', $this->probe($file));
    }

    public function test_the_steps_apply_only_to_what_the_collection_declares(): void
    {
        $file = $this->clip(['variants' => ['web' => []]], 'yuv444p');

        $this->assertTrue((new GenerateVideoVariantsStep())->supports($file));
        $this->assertFalse((new TranscodeVideoStep())->supports($file));
    }

    public function test_an_image_variant_is_a_frame_at_its_moment(): void
    {
        $file = $this->clip(['variants' => ['portada' => ['type' => 'image', 'frame_at' => '50%', 'width' => 32]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $portada = $file->refresh()->variant('portada');
        $this->assertSame('image/jpeg', $portada->mime_type);
        $this->assertSame(FileType::IMAGE, $portada->type);
        $this->assertSame(32, $portada->width);
        $this->assertEqualsWithDelta(0.5, $portada->metadata['at'], 0.05);
    }

    public function test_a_frame_makes_its_own_variants(): void
    {
        // Image variants use Intervention; GD is enough here.
        config()->set('laracrate.image.driver', extension_loaded('imagick') ? 'imagick' : 'gd');

        $file = $this->clip(['variants' => ['portada' => [
            'type'     => 'image',
            'variants' => ['mini' => ['width' => 16]],
        ]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $mini = $file->refresh()->variant('portada.mini');
        $this->assertSame('mini', $mini->variant);
        $this->assertSame(16, $mini->width);
    }

    public function test_frames_is_one_strip_that_says_how_to_cut_it(): void
    {
        $file = $this->clip(['variants' => ['escenas' => ['type' => 'frames', 'frames' => 4, 'width' => 16]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $strip = $file->refresh()->variant('escenas');
        $this->assertSame(1, $file->children()->count());
        $this->assertSame(FileType::IMAGE, $strip->type);
        $this->assertSame(\EduLazaro\Laracrate\Enums\FileMode::FRAMES, $strip->mode);
        $this->assertSame(64, $strip->width);
        $this->assertEquals(['frames' => 4, 'columns' => 4, 'rows' => 1, 'width' => 16, 'height' => 12],
            array_intersect_key($strip->metadata, array_flip(['frames', 'columns', 'rows', 'width', 'height'])));
        $this->assertEqualsWithDelta([0.125, 0.375, 0.625, 0.875], $strip->metadata['at'], 0.01);
    }

    public function test_a_teaser_joins_moments_spread_evenly(): void
    {
        $file = $this->clip(['variants' => ['teaser' => ['type' => 'video', 'clips' => 2, 'seconds' => 0.2]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $teaser = $file->refresh()->variant('teaser');
        $this->assertSame('video/mp4', $teaser->mime_type);
        $this->assertSame('h264,yuv420p', $this->probe($teaser));
        $this->assertCount(2, $teaser->metadata['clips']);
        $this->assertEqualsWithDelta([[0.15, 0.2], [0.65, 0.2]], $teaser->metadata['clips'], 0.01);
    }

    public function test_a_generator_picks_the_moments_of_a_teaser(): void
    {
        $file = $this->clip(['variants' => ['teaser' => ['type' => 'video', 'generator' => FixedMoments::class]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $this->assertEqualsWithDelta([[0.1, 0.3]], $file->refresh()->variant('teaser')->metadata['clips'], 0.001);
    }

    public function test_a_teaser_makes_its_smaller_copy_from_itself(): void
    {
        $file = $this->clip(['variants' => ['teaser' => [
            'type'     => 'video',
            'clips'    => 1,
            'seconds'  => 0.5,
            'variants' => ['mini' => ['max_height' => 24]],
        ]]], 'yuv420p');

        (new GenerateVideoVariantsStep())->handle($file);

        $mini = $file->refresh()->variant('teaser.mini');
        $this->assertSame('mini', $mini->variant);
        $this->assertSame($file->variant('teaser')->id, $mini->parent_id);
        $this->assertSame(24, $mini->height);
    }

    public function test_the_1x_settings_fail_with_what_to_change(): void
    {
        $file = $this->clip(['transcode' => 'variant'], 'yuv420p');

        $this->expectExceptionMessage("'transcode' => 'variant'");
        (new GenerateVideoVariantsStep())->supports($file);
    }
}

class FixedMoments implements \EduLazaro\Laracrate\Contracts\MomentsGenerator
{
    public function generate(File $video, array $spec): array
    {
        return [['at' => 0.1, 'seconds' => 0.3], ['at' => 99]];
    }
}
