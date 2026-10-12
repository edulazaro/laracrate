<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Support\RangeResponse;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Players ask for byte ranges: without 206 responses a browser cannot seek, cannot
 * start a video whose index sits at the end, and Safari does not play video at all.
 */
class RangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '0123456789abcdefghij';

    private function videoFile(string $access = 'stream'): File
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = File::create([
            'slug'          => (string) Str::ulid(),
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
            'path'          => 'test',
            'name'          => Str::random(8) . '.mp4',
            'original_name' => 'hearing.mp4',
            'extension'     => 'mp4',
            'mime_type'     => 'video/mp4',
            'size'          => strlen(self::BODY),
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::VIDEO,
            'access'        => $access,
        ]);
        Storage::disk('media')->put($file->key, self::BODY);
        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        return $file;
    }

    private function path(string $route, File $file): string
    {
        $url = URL::temporarySignedRoute($route, now()->addMinutes(5), ['file' => $file->slug]);

        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    public function test_without_range_the_whole_file_is_served_and_ranges_are_announced(): void
    {
        $response = $this->get($this->path('laracrate.files.preview', $this->videoFile()));

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Length', (string) strlen(self::BODY));
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_a_range_returns_only_those_bytes(): void
    {
        $response = $this->withHeader('Range', 'bytes=5-9')
            ->get($this->path('laracrate.files.preview', $this->videoFile()));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 5-9/20');
        $response->assertHeader('Content-Length', '5');
        $this->assertSame('56789', $response->streamedContent());
    }

    public function test_open_ended_and_suffix_ranges(): void
    {
        $file = $this->videoFile();

        $this->assertSame('fghij', $this->withHeader('Range', 'bytes=15-')
            ->get($this->path('laracrate.files.preview', $file))->streamedContent());

        $this->assertSame('hij', $this->withHeader('Range', 'bytes=-3')
            ->get($this->path('laracrate.files.preview', $file))->streamedContent());
    }

    public function test_a_range_past_the_end_is_not_satisfiable(): void
    {
        $response = $this->withHeader('Range', 'bytes=50-60')
            ->get($this->path('laracrate.files.preview', $this->videoFile()));

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */20');
    }

    public function test_only_the_first_range_counts_as_a_download(): void
    {
        $file = $this->videoFile();

        $this->withHeader('Range', 'bytes=0-')->get($this->path('laracrate.files.stream', $file))->assertStatus(206);
        $this->withHeader('Range', 'bytes=10-')->get($this->path('laracrate.files.stream', $file))->assertStatus(206);
        $this->withHeader('Range', 'bytes=15-')->get($this->path('laracrate.files.stream', $file))->assertStatus(206);

        $this->assertSame(1, $file->fresh()->downloads_count);
    }

    public function test_local_disk_serving_honours_ranges(): void
    {
        $response = $this->withHeader('Range', 'bytes=10-12')
            ->get($this->path('laracrate.local.serve', $this->videoFile('signed')));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 10-12/20');
        $this->assertSame('abc', $response->streamedContent());
    }

    public function test_range_parsing(): void
    {
        $this->assertNull(RangeResponse::parse(null, 20));
        $this->assertNull(RangeResponse::parse('bytes=0-1,5-6', 20));
        $this->assertSame([0, 19], RangeResponse::parse('bytes=0-', 20));
        $this->assertSame([18, 19], RangeResponse::parse('bytes=18-99', 20));
        $this->assertSame([0, 19], RangeResponse::parse('bytes=-50', 20));
        $this->assertFalse(RangeResponse::parse('bytes=20-', 20));
        $this->assertFalse(RangeResponse::parse('bytes=-0', 20));
    }
}
