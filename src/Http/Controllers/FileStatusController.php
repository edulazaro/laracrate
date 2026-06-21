<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint for the client to query the processing status of one or several
 * files. Intended for polling after an async upload.
 *
 *   GET  /laracrate/files/{file:slug}/status   -> a single file
 *   POST /laracrate/files/status               -> batch (several slugs)
 *
 * Each response includes:
 *   - status:   pending | processing | completed | failed
 *   - ready:    bool (true if completed)
 *   - url:      final URL if ready, null otherwise
 *   - variants: { name => url } of the variants already generated
 *   - preview:  URL of the 'preview.thumbnail' variant if it exists
 *   - error:    processing_error if status=failed
 */
class FileStatusController extends Controller
{
    /** Return the processing status of a single file. */
    public function show(Request $request, File $file): JsonResponse
    {
        if (!$file->canView($request->user())) {
            abort(403);
        }

        return response()->json($this->payload($file));
    }

    /** Return the processing status of several files by slug. */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slugs'   => 'required|array|min:1|max:200',
            'slugs.*' => 'required|string|max:50',
        ]);

        $files = File::whereIn('slug', $data['slugs'])
            ->whereNull('parent_id')
            ->get();

        $user = $request->user();
        $results = [];

        foreach ($files as $file) {
            if (!$file->canView($user)) continue;
            $results[$file->slug] = $this->payload($file);
        }

        return response()->json($results);
    }

    /** Build the status payload for a single file. */
    protected function payload(File $file): array
    {
        $status = $file->processing_status;

        $ready  = $status === ProcessingStatus::COMPLETED || $status === null;
        $failed = $status === ProcessingStatus::FAILED;

        return [
            'slug'     => $file->slug,
            'status'   => $status?->value ?? ProcessingStatus::COMPLETED->value,
            'ready'    => $ready,
            'url'      => $ready ? $file->url() : null,
            'preview'  => $ready ? $file->preview_link : null,
            'variants' => $ready ? $this->collectVariants($file) : [],
            'error'    => $failed ? $file->processing_error : null,
        ];
    }

    /**
     * Name->URL map of the first-level variants/children.
     * For nested previews (preview.thumbnail) the app navigates with
     * $file->variant() if it needs to; here we give the flat ones.
     */
    protected function collectVariants(File $file): array
    {
        $out = [];

        foreach ($file->children as $child) {
            if (!$child->variant) continue;

            $out[$child->variant] = $child->url();

            // If the child is a preview with its own variants, expose them
            // nested with dot notation: preview.thumbnail, preview.medium...
            foreach ($child->children as $grandchild) {
                if ($grandchild->variant) {
                    $out["{$child->variant}.{$grandchild->variant}"] = $grandchild->url();
                }
            }
        }

        return $out;
    }
}
