<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Uploads\UploadGrant;
use EduLazaro\Laracrate\Uploads\UploadRules;
use EduLazaro\Laracrate\Uploads\UploadTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * An upload target the app declared (UploadTargets), for the React and Svelte
 * components. Both ask the target's callback, so a user it refuses gets a 403 either way.
 *
 *   GET  /laracrate/uploads/targets/{target}?params[venue]=12   what the component shows when it mounts
 *   POST /laracrate/uploads/targets/{target}  { params }        the grant, once the user picks files
 *
 * The first answers the scope (to find the uploads still running), the collection's
 * rules (accept, extensions, max_size_kb, kind) and, for a target that replaces its
 * file, the current one; no grant. The second answers the grant and its scope.
 */
class UploadTargetController extends Controller
{
    public function show(Request $request, UploadTargets $targets, string $target): JsonResponse
    {
        $grant = $this->resolve($request, $targets, $target);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        $fileable = $grant->fileable();

        return response()->json([
            'scope'   => $grant->scope(),
            'replace' => $grant->replace,
            'rules'   => UploadRules::describe($fileable, $grant->collection),
            'file'    => $grant->replace ? $fileable->file($grant->collection)?->toComponentArray() : null,
        ]);
    }

    public function grant(Request $request, UploadTargets $targets, string $target): JsonResponse
    {
        $grant = $this->resolve($request, $targets, $target);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        return response()->json([
            'grant' => $grant->token(),
            'scope' => $grant->scope(),
        ]);
    }

    private function resolve(Request $request, UploadTargets $targets, string $target): UploadGrant|JsonResponse
    {
        $data = $request->validate(['params' => 'nullable|array']);

        abort_unless($targets->has($target), 404);

        return $targets->grant($target, $request->user(), $data['params'] ?? [])
            ?? response()->json(['message' => __('laracrate::uploader.target_refused')], 403);
    }
}
