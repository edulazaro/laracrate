<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileMode;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Pipeline\Steps\Image\DetectSphereStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\GenerateImageVariantsStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A 360° photo is an image with mode `sphere`, told apart only where the collection's
 * image type takes that mode.
 */
class SphereModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('laracrate.collections.photos', [
            'disk'   => 'media',
            'access' => 'private',
            'types'  => ['image' => ['modes' => ['default', 'sphere'], 'variants' => ['thumbnail' => ['width' => 8]], 'preview' => 'thumbnail']],
        ]);
    }

    /** A small jpeg, with a GPano XMP packet when $projection is given. */
    private function photo(?string $projection = null): File
    {
        $image = imagecreatetruecolor(16, 8);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        if ($projection !== null) {
            $xmp  = "http://ns.adobe.com/xap/1.0/\0" . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
                . '<rdf:Description xmlns:GPano="http://ns.google.com/photos/1.0/panorama/" GPano:ProjectionType="' . $projection . '" GPano:UsePanoramaViewer="True"/>'
                . '</rdf:RDF></x:xmpmeta>';
            $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($xmp) + 2) . $xmp . substr($jpeg, 2);
        }

        $file = File::create([
            'disk'          => 'media',
            'path'          => 'photos/' . Str::random(8) . '.jpg',
            'name'          => 'photo.jpg',
            'original_name' => 'photo.jpg',
            'extension'     => 'jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => strlen($jpeg),
            'context'       => 'media',
            'collection'    => 'photos',
            'type'          => FileType::IMAGE,
            'access'        => 'private',
        ]);
        Storage::disk('media')->put($file->key, $jpeg);

        return $file->fresh();
    }

    public function test_a_photo_whose_xmp_says_equirectangular_is_a_sphere(): void
    {
        $file = $this->photo('equirectangular');

        (new DetectSphereStep())->handle($file);

        $this->assertSame(FileType::IMAGE, $file->fresh()->type);
        $this->assertSame(FileMode::SPHERE, $file->fresh()->mode);
    }

    public function test_any_other_photo_stays_an_image(): void
    {
        $plain = $this->photo();
        $wide  = $this->photo('cylindrical');

        (new DetectSphereStep())->handle($plain);
        (new DetectSphereStep())->handle($wide);

        $this->assertSame(FileMode::DEFAULT, $plain->fresh()->mode);
        $this->assertSame(FileMode::DEFAULT, $wide->fresh()->mode);
    }

    public function test_a_collection_that_only_takes_images_keeps_a_360_photo_as_an_image(): void
    {
        $this->assertTrue((new DetectSphereStep())->supports($this->photo('equirectangular')));

        config()->set('laracrate.collections.photos.types', ['image' => []]);

        $this->assertFalse((new DetectSphereStep())->supports($this->photo('equirectangular')));
    }

    public function test_a_sphere_is_processed_and_shown_as_an_image(): void
    {
        $file = $this->photo('equirectangular');
        $file->forceFill(['mode' => FileMode::SPHERE])->save();
        $thumbnail = File::create(array_merge($file->only(['disk', 'context', 'collection', 'access', 'mime_type', 'extension']), [
            'path' => 'photos/thumb.jpg', 'name' => 'thumb.jpg', 'original_name' => 'thumb.jpg', 'size' => 1,
            'type' => FileType::IMAGE, 'parent_id' => $file->id, 'variant' => 'thumbnail',
        ]));

        $this->assertTrue($file->isImage());
        $this->assertTrue((new GenerateImageVariantsStep())->supports($file));
        $this->assertTrue($file->preview()->is($thumbnail));
        // Still an image wherever images are listed.
        $this->assertSame(1, File::whereNull('parent_id')->where('type', 'image')->count());
    }
}
