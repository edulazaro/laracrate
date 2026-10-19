<?php

namespace EduLazaro\Laracrate\Uploads;

use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The 'presign' mode of the upload components.
 *
 * The component signs the whole batch while it is on the page, and the server keeps,
 * for each temp/ key it signed, where that object goes. The upload queue then puts the
 * bytes and registers each object by its key alone (`POST /laracrate/uploads/register`),
 * so an upload still finishes after a wire:navigate and the target never comes from the
 * browser. What is kept is the component's grant, which only works for the same user in
 * the same browser session, so a key that leaks cannot be registered by anyone else.
 */
final class PresignedUploads
{
    private const PREFIX = 'laracrate:presigned:';

    /**
     * Signs each file for the grant's target, or says why the target refuses it.
     *
     * @param  array<int, mixed>  $files  [{ name, mime, size }] as the browser describes them
     * @param  int|null  $room  how many of them the target takes now; null for all
     * @return array<int, array{key: string, url: string, method: string, headers: array}|array{error: string}>
     */
    public static function sign(UploadGrant $grant, array $files, ?int $room = null): array
    {
        $minutes = (int) config('laracrate.uploads.presign_minutes', 120);
        $token = $grant->token();
        $signed = [];

        foreach (array_values($files) as $index => $file) {
            $name = is_string($file['name'] ?? null) && $file['name'] !== '' ? Str::limit($file['name'], 255, '') : 'upload';
            $mime = is_string($file['mime'] ?? null) && $file['mime'] !== '' ? $file['mime'] : 'application/octet-stream';
            $size = max(0, (int) ($file['size'] ?? 0));

            if ($room !== null && $index >= $room) {
                $signed[] = ['error' => __('laracrate::uploader.rejected.too_many')];
                continue;
            }

            if ($refusal = UploadRules::refusal($grant, $mime, $size, $name, auth()->user())) {
                $signed[] = ['error' => $refusal];
                continue;
            }

            $disk = $grant->disk();
            $key = 'temp/' . Str::ulid() . '_' . UploadRules::safeName($name);
            $url = app(StorageManager::class)->presignedUpload($disk, $key, $mime, null, $minutes);

            Cache::put(self::cacheKey($key), [
                'grant' => $token,
                'disk'  => $disk,
                'name'  => $name,
                'mime'  => $mime,
            ], now()->addMinutes($minutes + 60));

            $signed[] = [
                'key'     => $key,
                'url'     => $url['url'],
                'method'  => $url['method'] ?? 'PUT',
                'headers' => $url['headers'] ?? ['Content-Type' => $mime],
            ];
        }

        return $signed;
    }

    /**
     * What sign() kept for this key: the grant token, the disk, the name and the type.
     *
     * @return array{grant: string, disk: string, name: string, mime: string}|null
     */
    public static function find(string $key): ?array
    {
        return Cache::get(self::cacheKey($key));
    }

    /** Once registered, or refused for good, a key cannot be registered again. */
    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }
}
