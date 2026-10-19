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

    /**
     * The collection's rules as a component shows them and the file picker filters by:
     * the accepted mime types and extensions, the largest size, and the kind of file it
     * takes (image, video, audio, document, or mixed). The server checks them again on
     * every upload.
     */
    public static function describe(Model $fileable, string $collection): array
    {
        $manager = app(StorageManager::class);
        $types = [];

        foreach ($fileable->getCollectionConfig($collection)['types'] ?? [] as $key => $value) {
            $types[] = is_int($key) ? $value : $key;
        }

        $mimes = [];
        $extensions = [];
        $max = 0;

        foreach ($types as $type) {
            $config = $manager->getTypeConfig($collection, $type, $fileable->getMorphClass());
            array_push($mimes, ...($config['accepted_mime_types'] ?? []));
            array_push($extensions, ...array_map('strtolower', $config['accepted_extensions'] ?? []));
            $max = max($max, (int) ($config['max_file_size'] ?? 0));
        }

        return [
            'accept'      => implode(',', array_unique($mimes)) ?: null,
            'extensions'  => array_values(array_unique($extensions)),
            'max_size_kb' => $max ?: null,
            'kind'        => count($types) === 1 ? $types[0] : 'mixed',
        ];
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
