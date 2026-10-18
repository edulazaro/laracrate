<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Handoff: open a file in an editor of another site (laracrate.handoff.editors) and take
 * the result back as a copy next to it. The protocol (windows and postMessage, version 1)
 * is Auditius's src/lib/handoff/SPEC.md; this side is the app that opens the editor and
 * the target it saves into.
 *
 *   open()  what the page sends the editor: the file's name, a link to read it now, and
 *           where to save the copy when the user may add it.
 *   save()  the target page the editor opens to save. It receives the file and uploads it
 *           with the upload queue, under a grant for the original's place.
 */
class HandoffController extends Controller
{
    public function open(Request $request, File $file, string $editor): JsonResponse
    {
        $config = $this->editor($editor, $file);
        abort_unless($file->canView($request->user()), 403);

        $save = $file->canEdit($request->user())
            ? route('laracrate.files.handoff.save', ['file' => $file->slug, 'editor' => $editor])
            : null;

        return response()->json([
            'name'     => $this->displayName($file),
            'download' => $file->downloadUrl(),
            // An editor only saves to https (or localhost while developing): offering any
            // other URL would make it refuse the file altogether.
            'save'     => $save && $this->secure($save)
                ? ['url' => $save, 'formats' => array_values($config['formats'] ?? [])]
                : null,
        ]);
    }

    public function save(Request $request, File $file, string $editor): View
    {
        $config = $this->editor($editor, $file);
        abort_unless($file->canEdit($request->user()), 403);

        $grant = UploadGrant::for($file->fileable, $file->collection, folderId: $file->folder_id);
        $manager = app(StorageManager::class);

        // What the copy may be, by the collection's own rules, so the page can refuse with
        // the protocol's code before any byte moves. The upload checks them again.
        $limits = collect($config['types'])->mapWithKeys(function (string $type) use ($manager, $file) {
            $rules = $manager->getTypeConfig($file->collection, $type, $file->fileable_type);

            return [$type => [
                'mimes'    => array_values($rules['accepted_mime_types'] ?? []),
                'maxBytes' => isset($rules['max_file_size']) ? (int) $rules['max_file_size'] * 1024 : null,
            ]];
        })->all();

        return view('laracrate::handoff-save', [
            'file'   => $file,
            'name'   => $this->displayName($file),
            'folder' => $file->folder?->name,
            'editor' => ['name' => $config['name'] ?? $editor, 'origin' => $this->origin($config['url'])],
            'upload' => ['grant' => $grant->token(), 'scope' => $grant->scope()],
            'limits' => $limits,
        ]);
    }

    /** The editor's config, if it exists and opens this kind of file. */
    protected function editor(string $name, File $file): array
    {
        $config = config("laracrate.handoff.editors.{$name}");
        abort_unless(is_array($config) && ! empty($config['url']), 404);
        abort_unless(in_array($file->type?->value, $config['types'] ?? [], true), 422, __('laracrate::handoff.unsupported'));

        return $config;
    }

    protected function displayName(File $file): string
    {
        return (string) ($file->title ?: ($file->original_name ?: $file->name));
    }

    protected function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $port;
    }

    protected function secure(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';

        return ($parts['scheme'] ?? '') === 'https'
            || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || str_ends_with($host, '.localhost');
    }
}
