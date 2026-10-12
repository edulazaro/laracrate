<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use EduLazaro\Laracrate\Tests\TestCase;

class ExtractionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // The resolver keeps a static override; reset it so tests stay isolated.
        ExtractionResolver::setOverrideResolver(null);
        parent::tearDown();
    }

    private function fileIn(string $collection, FileType $type): File
    {
        return File::create([
            'disk'          => 'media',
            'path'          => uniqid('f') . '.bin',
            'name'          => 'f.bin',
            'original_name' => 'f.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => 1,
            'context'       => 'media',
            'collection'    => $collection,
            'type'          => $type,
            'access'        => 'public',
        ]);
    }

    public function test_extract_boolean_true_enables_every_type(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => true]);

        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::IMAGE)));
    }

    public function test_extract_array_filters_by_file_type(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => ['document']]);

        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
        $this->assertFalse(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::IMAGE)));
    }

    public function test_absent_extract_key_is_false(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public']);

        $this->assertFalse(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
    }

    public function test_legacy_extract_text_key_is_honored(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract_text' => true]);

        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
    }

    public function test_embed_boolean(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'embed' => true]);

        $this->assertTrue(ExtractionResolver::shouldEmbed($this->fileIn('docs', FileType::DOCUMENT)));
    }

    public function test_has_extra_matches_dotted_suffix(): void
    {
        config()->set('laracrate.collections.clips', ['disk' => 'media', 'access' => 'public', 'extract' => ['video.visual']]);
        $file = $this->fileIn('clips', FileType::VIDEO);

        $this->assertTrue(ExtractionResolver::hasExtra($file, 'video.visual'));
        $this->assertFalse(ExtractionResolver::hasExtra($file, 'video.audio'));
    }

    public function test_override_resolver_wins_over_config(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => false]);
        ExtractionResolver::setOverrideResolver(fn (File $f) => ['extract' => true]);

        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
    }

    public function test_string_modes_at_collection_level(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => 'always']);
        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::AUDIO)));

        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => 'never']);
        $this->assertFalse(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::AUDIO)));
    }

    public function test_type_level_mode_wins_over_collection_level(): void
    {
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public', 'extract' => true,
            'types' => ['audio' => ['max_file_size' => 1024, 'extract' => 'never']],
        ]);

        $this->assertTrue(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT)));
        $this->assertFalse(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::AUDIO)));
    }

    public function test_on_demand_runs_only_once_requested(): void
    {
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public', 'extract' => true, 'embed' => true,
            'types' => ['audio' => ['extract' => 'on_demand']],
        ]);
        $file = $this->fileIn('docs', FileType::AUDIO);

        $this->assertSame(ExtractionResolver::ON_DEMAND, ExtractionResolver::extractMode($file));
        $this->assertFalse(ExtractionResolver::shouldExtract($file));
        $this->assertFalse(ExtractionResolver::shouldEmbed($file));

        $file->metadata = [ExtractionResolver::REQUESTED_AT => now()->toIso8601String()];

        $this->assertTrue(ExtractionResolver::shouldExtract($file));
        $this->assertTrue(ExtractionResolver::shouldEmbed($file));
    }

    public function test_embed_does_not_force_extraction_the_type_excludes(): void
    {
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public', 'extract' => ['document'], 'embed' => true,
        ]);

        $this->assertFalse(ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::AUDIO)));
        $this->assertFalse(ExtractionResolver::shouldEmbed($this->fileIn('docs', FileType::AUDIO)));
    }

    public function test_embed_alone_still_drives_extraction(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'embed' => true]);
        $file = $this->fileIn('docs', FileType::DOCUMENT);

        $this->assertTrue(ExtractionResolver::shouldExtract($file));
        $this->assertTrue(ExtractionResolver::shouldEmbed($file));
    }

    public function test_visual_mode_on_the_video_type(): void
    {
        config()->set('laracrate.collections.clips', [
            'disk' => 'media', 'access' => 'public', 'extract' => true,
            'types' => ['video' => ['visual' => 'on_demand']],
        ]);
        $file = $this->fileIn('clips', FileType::VIDEO);

        $this->assertTrue(ExtractionResolver::shouldExtract($file));
        $this->assertFalse(ExtractionResolver::shouldDescribeVisual($file));
        $this->assertTrue(ExtractionResolver::isOnDemand($file));

        $file->metadata = [ExtractionResolver::REQUESTED_AT => now()->toIso8601String()];

        $this->assertTrue(ExtractionResolver::shouldDescribeVisual($file));
        $this->assertTrue(ExtractionResolver::hasExtra($file, 'video.visual'));
    }

    public function test_legacy_visual_extra_in_the_extract_list(): void
    {
        config()->set('laracrate.collections.clips', ['disk' => 'media', 'access' => 'public', 'extract' => ['document', 'video.visual']]);
        $this->assertTrue(ExtractionResolver::shouldDescribeVisual($this->fileIn('clips', FileType::VIDEO)));

        config()->set('laracrate.collections.clips', ['disk' => 'media', 'access' => 'public', 'extract' => ['video']]);
        $this->assertFalse(ExtractionResolver::shouldDescribeVisual($this->fileIn('clips', FileType::VIDEO)));
    }

    public function test_override_visual_extra_is_honored(): void
    {
        config()->set('laracrate.collections.clips', ['disk' => 'media', 'access' => 'public', 'extract' => true]);
        ExtractionResolver::setOverrideResolver(fn (File $f) => ['extract' => ['video.visual']]);

        $this->assertTrue(ExtractionResolver::shouldDescribeVisual($this->fileIn('clips', FileType::VIDEO)));
    }

    public function test_override_collection_level_wins_over_config_type_level(): void
    {
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public',
            'types' => ['audio' => ['extract' => 'on_demand']],
        ]);
        ExtractionResolver::setOverrideResolver(fn (File $f) => ['extract' => false]);

        $this->assertSame(ExtractionResolver::NEVER, ExtractionResolver::extractMode($this->fileIn('docs', FileType::AUDIO)));
    }

    public function test_override_of_one_type_keeps_the_rest_of_its_config(): void
    {
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public', 'extract' => true,
            'types' => ['audio' => ['max_file_size' => 1024], 'image' => ['max_file_size' => 2048]],
        ]);
        ExtractionResolver::setOverrideResolver(fn (File $f) => ['types' => ['audio' => ['extract' => 'on_demand']]]);
        $file = $this->fileIn('docs', FileType::AUDIO);

        $types = ExtractionResolver::effectiveConfig($file)['types'];

        $this->assertSame(['max_file_size' => 1024, 'extract' => 'on_demand'], $types['audio']);
        $this->assertSame(['max_file_size' => 2048], $types['image']);
        $this->assertSame(ExtractionResolver::ON_DEMAND, ExtractionResolver::extractMode($file));
    }

    public function test_invalid_mode_throws(): void
    {
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => 'sometimes']);

        $this->expectException(\InvalidArgumentException::class);

        ExtractionResolver::shouldExtract($this->fileIn('docs', FileType::DOCUMENT));
    }

    public function test_can_request_until_requested_and_again_after_a_failure(): void
    {
        config()->set('laracrate.embeddings.enabled', true);
        config()->set('laracrate.collections.docs', [
            'disk' => 'media', 'access' => 'public',
            'types' => ['audio' => ['extract' => 'on_demand'], 'document' => ['extract' => 'always']],
        ]);
        $audio = $this->fileIn('docs', FileType::AUDIO);

        $this->assertFalse(ExtractionResolver::canRequest($this->fileIn('docs', FileType::DOCUMENT)));
        $this->assertTrue(ExtractionResolver::canRequest($audio));

        $audio->metadata = [ExtractionResolver::REQUESTED_AT => now()->toIso8601String()];
        $audio->processing_status = ProcessingStatus::COMPLETED;
        $this->assertFalse(ExtractionResolver::canRequest($audio));

        $audio->processing_status = ProcessingStatus::FAILED;
        $this->assertTrue(ExtractionResolver::canRequest($audio));
    }

    public function test_cannot_request_with_embeddings_disabled(): void
    {
        config()->set('laracrate.embeddings.enabled', false);
        config()->set('laracrate.collections.docs', ['disk' => 'media', 'access' => 'public', 'extract' => 'on_demand']);

        $this->assertFalse(ExtractionResolver::canRequest($this->fileIn('docs', FileType::AUDIO)));
    }
}
