<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
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
}
