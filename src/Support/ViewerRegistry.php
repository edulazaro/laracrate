<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * What the viewer (laracrate::viewer) shows of a file that is not an image, audio or
 * video. An app or a package registers a preview for a mime type; the callback gets the
 * File and returns its HTML and, optionally, where to edit it, or null when there is
 * nothing to show:
 *
 *   app(ViewerRegistry::class)->html('application/x-note', fn (File $file) => [
 *       'html' => $note->html,
 *       'edit' => route('notes.edit', $file->slug),
 *   ]);
 *
 * The viewer shows that HTML in a sandboxed iframe, with no scripts.
 */
final class ViewerRegistry
{
    /** @var array<string, callable> */
    private array $html = [];

    public function html(string $mime, callable $resolver): self
    {
        $this->html[$mime] = $resolver;

        return $this;
    }

    /** @return array{html: string, edit?: string|null, download?: string|null}|null */
    public function previewFor(File $file): ?array
    {
        $resolver = $this->html[$file->mime_type] ?? null;

        return $resolver ? $resolver($file) : null;
    }
}
