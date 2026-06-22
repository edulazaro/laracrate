<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

class VariantNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_returns_descendant_when_chain_resolves(): void
    {
        $root      = $this->makeRoot();
        $preview   = $this->makeChild($root, 'preview');
        $thumbnail = $this->makeChild($preview, 'thumbnail');

        $resolved = $root->variant('preview.thumbnail');

        $this->assertTrue($resolved->is($thumbnail));
    }

    public function test_variant_falls_back_to_closest_ancestor_when_chain_breaks(): void
    {
        $root    = $this->makeRoot();
        $preview = $this->makeChild($root, 'preview');
        // No 'thumbnail' child of preview.

        $resolved = $root->variant('preview.thumbnail');

        $this->assertTrue($resolved->is($preview));
    }

    public function test_variant_or_fail_returns_descendant_when_chain_resolves(): void
    {
        $root      = $this->makeRoot();
        $preview   = $this->makeChild($root, 'preview');
        $thumbnail = $this->makeChild($preview, 'thumbnail');

        $resolved = $root->variantOrFail('preview.thumbnail');

        $this->assertTrue($resolved->is($thumbnail));
    }

    public function test_variant_or_fail_throws_when_first_segment_missing(): void
    {
        $root = $this->makeRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/'preview' not found.*resolved up to: '<root>'/");

        $root->variantOrFail('preview.thumbnail');
    }

    public function test_variant_or_fail_throws_when_chain_breaks_mid_path(): void
    {
        $root    = $this->makeRoot();
        $preview = $this->makeChild($root, 'preview');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/'thumbnail' not found.*resolved up to: 'preview'/");

        $root->variantOrFail('preview.thumbnail');
    }

    protected function makeRoot(): File
    {
        return File::create([
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => 'sample.jpg',
            'mime_type'         => 'image/jpeg',
            'size'              => 1,
            'collection'        => 'gallery',
            'type'              => FileType::IMAGE,
            'access'            => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);
    }

    protected function makeChild(File $parent, string $variant): File
    {
        return File::create([
            'parent_id'         => $parent->id,
            'variant'           => $variant,
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => "{$variant}.jpg",
            'mime_type'         => 'image/jpeg',
            'size'              => 1,
            'collection'        => 'gallery',
            'type'              => FileType::IMAGE,
            'access'            => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);
    }
}
