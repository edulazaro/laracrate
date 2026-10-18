<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * What the viewer (laracrate::viewer) shows of a file: its name, its type and a link
 * to read it, made now. The viewer asks when it opens and again when a link runs out
 * halfway (a long recording outlives a signed URL), so no link in the HTML has to last.
 *
 * Access is the file's own (PolicyRegistry). There is no route signature: the request
 * comes from the page, with the session.
 */
class ViewerController extends Controller
{
    public const TYPES = ['image', 'audio', 'video'];

    public function __invoke(Request $request, File $file): JsonResponse
    {
        abort_unless($file->canView($request->user()), 403);

        $type = $file->type?->value;

        if (! in_array($type, self::TYPES, true)) {
            return response()->json(['message' => __('laracrate::viewer.unsupported')], 422);
        }

        // The copy a browser shows: the configured image variant, the video playback
        // copy. Both fall back to the file itself when it has none.
        $shown = match ($type) {
            'image' => $file->variant((string) config('laracrate.viewer.image_variant', 'display')),
            'video' => $file->playback(),
            default => $file,
        };

        $url = $shown->url();

        if ($url === null) {
            return response()->json(['message' => __('laracrate::viewer.failed')], 503);
        }

        return response()->json([
            'slug'     => $file->slug,
            'name'     => $file->title ?: ($file->original_name ?: $file->name),
            'type'     => $type,
            'mime'     => $shown->mime_type,
            'url'      => $url,
            'download' => $file->downloadUrl(),
        ]);
    }
}
