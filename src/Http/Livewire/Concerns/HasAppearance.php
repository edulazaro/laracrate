<?php

namespace EduLazaro\Laracrate\Http\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * How an upload component looks, for this rendering only. The look itself is
 * resources/css/laracrate.css: a theme only sets variables, so everything here ends up
 * as variables or classes on the component's root.
 *
 *   theme     a built-in theme or your own ([data-wire-theme="…"] in your CSS). Without
 *             it the component takes the theme of the page (`data-wire-theme` on an
 *             ancestor), as the wire* packages do.
 *   rounded   none, sm, md, lg, xl, 2xl, 3xl or full
 *   bordered  false removes the borders
 *   shadow    true or false, whatever the theme says
 *   size      sm, md or lg
 *   class     classes for the root, to target this one from your CSS
 *   style     inline style for the root, e.g. "--wire-radius: 0"
 */
trait HasAppearance
{
    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public ?string $rounded = null;

    #[Locked]
    public ?bool $bordered = null;

    #[Locked]
    public ?bool $shadow = null;

    #[Locked]
    public ?string $size = null;

    #[Locked]
    public ?string $class = null;

    #[Locked]
    public ?string $style = null;

    /** @return array{theme: ?string, classes: string, style: string} */
    protected function appearance(): array
    {
        $variables = [];

        if (($radius = self::radius($this->rounded)) !== null) {
            $variables[$this->roundedVariable()] = $radius;
        }

        if ($this->bordered === false) {
            foreach (['border-width', 'drop-border-width', 'card-border', 'tile-border', 'icon-button-border'] as $border) {
                $variables["--wire-laracrate-{$border}"] = '0';
            }
        }

        if ($this->shadow === true) {
            $variables['--wire-laracrate-shadow'] = '0 1px 3px rgb(0 0 0 / .1), 0 1px 2px rgb(0 0 0 / .06)';
        }

        if ($this->shadow === false) {
            foreach (['shadow', 'drop-shadow', 'drop-hover-shadow', 'dragover-shadow', 'card-shadow', 'button-shadow', 'button-hover-shadow'] as $shadow) {
                $variables["--wire-laracrate-{$shadow}"] = 'none';
            }
        }

        $style = implode('; ', array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($variables), $variables));

        if ($this->style) {
            $style = ltrim($style . '; ' . $this->style, '; ');
        }

        return [
            'theme'   => $this->theme,
            'classes' => trim(implode(' ', array_filter([
                in_array($this->size, ['sm', 'lg'], true) ? 'is-' . $this->size : null,
                $this->class,
            ]))),
            'style'   => $style,
        ];
    }

    /** What `rounded` rounds: the whole component, or only the preview in the uploaders. */
    protected function roundedVariable(): string
    {
        return '--wire-laracrate-radius';
    }

    private static function radius(?string $rounded): ?string
    {
        return match ($rounded) {
            'none'  => '0',
            'sm'    => '.125rem',
            'md'    => '.375rem',
            'lg'    => '.5rem',
            'xl'    => '.75rem',
            '2xl'   => '1rem',
            '3xl'   => '1.5rem',
            'full'  => '9999px',
            default => null,
        };
    }
}
