<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Http\Livewire\Concerns\HasAppearance;
use EduLazaro\Laracrate\Tests\TestCase;

/**
 * What the appearance props of the upload components put on their root: the theme,
 * classes, and variables in the style attribute.
 */
class AppearanceTest extends TestCase
{
    private function rendered(array $props = []): object
    {
        $component = new class {
            use HasAppearance;

            public function root(): array
            {
                return $this->appearance();
            }
        };

        foreach ($props as $name => $value) {
            $component->{$name} = $value;
        }

        return $component;
    }

    public function test_without_props_it_takes_the_theme_of_the_page(): void
    {
        $this->assertSame(['theme' => null, 'classes' => '', 'style' => ''], $this->rendered()->root());
    }

    public function test_the_theme_prop_names_this_component_theme(): void
    {
        $this->assertSame('neon', $this->rendered(['theme' => 'neon'])->root()['theme']);
    }

    public function test_rounded_sets_the_radius_and_none_is_zero(): void
    {
        $this->assertSame('--wire-laracrate-radius: 0', $this->rendered(['rounded' => 'none'])->root()['style']);
        $this->assertSame('--wire-laracrate-radius: 9999px', $this->rendered(['rounded' => 'full'])->root()['style']);
        $this->assertSame('', $this->rendered(['rounded' => 'huge'])->root()['style']);
    }

    public function test_bordered_false_removes_every_border(): void
    {
        $style = $this->rendered(['bordered' => false])->root()['style'];

        foreach (['border-width', 'drop-border-width', 'card-border', 'tile-border', 'icon-button-border'] as $border) {
            $this->assertStringContainsString("--wire-laracrate-{$border}: 0", $style);
        }
    }

    public function test_shadow_false_removes_the_shadows_a_theme_sets(): void
    {
        $style = $this->rendered(['shadow' => false])->root()['style'];

        $this->assertStringContainsString('--wire-laracrate-drop-shadow: none', $style);
        $this->assertStringContainsString('--wire-laracrate-button-shadow: none', $style);
    }

    public function test_size_class_and_style_are_for_this_rendering(): void
    {
        $root = $this->rendered(['size' => 'sm', 'class' => 'dropzone-signature', 'rounded' => 'lg', 'style' => '--wire-accent: red'])->root();

        $this->assertSame('is-sm dropzone-signature', $root['classes']);
        $this->assertSame('--wire-laracrate-radius: .5rem; --wire-accent: red', $root['style']);
    }
}
