<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Crypt;

/**
 * Cifra un binario con la clave de la app (Laravel Crypt). Usado en
 * CreateFileAction cuando la colección tiene encrypt=true, antes de subir
 * al backend.
 */
class EncryptFileAction extends Action
{
    public function handle(string $binary): string
    {
        return Crypt::encryptString(base64_encode($binary));
    }
}
