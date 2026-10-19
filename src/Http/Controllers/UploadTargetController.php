<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Uploads\UploadRules;
use EduLazaro\Laracrate\Uploads\UploadTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * An upload target the app declared (UploadTargets), for the React and Svelte
 * components: a fresh grant, with what they show around it.
 *
 *   POST /laracrate/uploads/targets/{target}   { params: { venue: 12 } }
 *
 * Answers the grant and its scope, the collection's rules (accept, extensions,
 * max_size_kb, kind) and, for a target that replaces its file, the current one.
 */
class UploadTargetController extends Controller
{
    public function __invoke(Request $request, UploadTargets $targets, string $target): JsonResponse
    {
        $data = $request->validate(['params' => 'nullable|array']);

        abort_unless($targets->has($target), 404);

        $grant = $targets->grant($target, $request->user(), $data['params'] ?? []);

        if (! $grant) {
            return response()->json(['message' => __('laracrate::uploader.target_refused')], 403);
        }

        $fileable = $grant->fileable();

        return response()->json([
            'grant'   => $grant->token(),
            'scope'   => $grant->scope(),
            'replace' => $grant->replace,
            'rules'   => UploadRules::describe($fileable, $grant->collection),
            'file'    => $grant->replace ? $fileable->file($grant->collection)?->toComponentArray() : null,
        ]);
    }
}
