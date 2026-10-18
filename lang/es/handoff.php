<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Handoff strings
    |--------------------------------------------------------------------------
    |
    | Texts of the handoff pieces (laracrate::handoff, laracrate::handoff-save).
    | Override them from the app with
    | `php artisan vendor:publish --tag=laracrate-translations`.
    |
    */

    'unsupported'   => 'Este editor no abre este tipo de archivo.',
    'popup_blocked' => 'El navegador ha bloqueado la ventana del editor. Permite las ventanas emergentes de este sitio y vuelve a intentarlo.',
    'open_failed'   => 'No se ha podido mandar el archivo al editor.',

    'title'         => 'Guardar una copia',
    'waiting'       => 'Esperando el archivo de :editor…',
    'not_opened'    => 'Esta página se abre desde el editor, al guardar allí.',
    'untrusted'     => 'Esta página solo recibe archivos de :editor.',
    'from'          => 'Desde :host',
    'next_to'       => 'Se guarda como copia junto a «:name»',
    'in_folder'     => 'en la carpeta «:folder»',
    'name'          => 'Nombre',
    'save'          => 'Guardar copia',
    'cancel'        => 'Cancelar',
    'saving'        => 'Guardando… :percent %',
    'saved'         => 'Guardado. Ya puedes cerrar esta ventana.',
    'failed'        => 'No se ha podido guardar: :reason',
    'too_large'     => 'El archivo es demasiado grande para guardarlo aquí.',
    'not_accepted'  => 'Este tipo de archivo no se puede guardar aquí.',
    'edited'        => 'editado',

];
