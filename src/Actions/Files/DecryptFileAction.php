<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Crypt;

/**
 * Downloads the encrypted binary from the backend and decrypts it. Used by
 * the StreamFileController when is_encrypted=true.
 */
class DecryptFileAction extends Action
{
    /** Read and decrypt the file's binary content. */
    public function handle(File $file): string
    {
        $cipher = app(StorageManager::class)->readBinary($file);
        return base64_decode(Crypt::decryptString($cipher));
    }
}
