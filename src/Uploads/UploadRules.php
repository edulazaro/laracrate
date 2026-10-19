<?php

namespace EduLazaro\Laracrate\Uploads;

use EduLazaro\Laracrate\Actions\Files\CreateFileAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Exceptions\UploadRejected;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\ActiveContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a file must meet before any byte moves, wherever the upload starts: the upload
 * endpoints (a grant) and the components signing in 'presign' mode.
 */
final class UploadRules
{
    /**
     * Why the target refuses this file, or null when it takes it: the collection and
     * slot rules, plus no runnable content unless the collection asks for it.
     */
    public static function refusal(UploadGrant $grant, string $mime, int $size, string $name, ?Model $user): ?string
    {
        $fileable = $grant->fileable();

        try {
            CreateFileAction::create()->preflight(
                $fileable,
                $grant->collection,
                $mime,
                $size,
                $name,
                $grant->slots,
                $grant->creator() ?? $user,
            );
        } catch (UploadRejected $e) {
            return $e->userMessage();
        }

        if (ActiveContent::is($mime, $name) && ! self::acceptsExplicitly($grant, $fileable, $mime)) {
            return __('laracrate::uploader.rejected.active');
        }

        return null;
    }

    /** The file name as it goes into an object key. */
    public static function safeName(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);

        return Str::limit($safe, 120, '') ?: 'upload';
    }

    private static function acceptsExplicitly(UploadGrant $grant, Model $fileable, string $mime): bool
    {
        $type = FileType::fromMime($mime)->value;
        $config = app(StorageManager::class)->getTypeConfig($grant->collection, $type, $fileable->getMorphClass());

        return in_array($mime, $config['accepted_mime_types'] ?? [], true);
    }
}
