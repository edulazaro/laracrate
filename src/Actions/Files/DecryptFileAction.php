<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Crypt;

/**
 * Descarga el binario cifrado del backend y lo desencripta. Usado por el
 * StreamFileController cuando is_encrypted=true.
 */
class DecryptFileAction extends Action
{
    public function handle(File $file): string
    {
        $cipher = app(StorageManager::class)->readBinary($file);
        return base64_decode(Crypt::decryptString($cipher));
    }
}
