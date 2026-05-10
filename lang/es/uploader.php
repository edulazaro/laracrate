<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploader strings
    |--------------------------------------------------------------------------
    |
    | Textos de los componentes <livewire:laracrate-uploader> y
    | <livewire:laracrate-uploader-deferred>. Override desde la app vía
    | `php artisan vendor:publish --tag=laracrate-translations`.
    |
    */

    // Estado vacío (instant)
    'upload'              => 'Subir archivo',
    'choose'              => 'Elegir',
    'drag_or_click'       => 'Arrastra o haz clic',
    'drag_or_click_long'  => 'Arrastra un archivo o haz clic',
    'max_size'            => 'máx :size MB',
    'max_size_capital'    => 'Máx. :size MB',

    // Estado vacío (deferred)
    'select'              => 'Seleccionar archivo',

    // Estado con file
    'replace'             => 'Reemplazar',
    'delete'              => 'Eliminar',
    'delete_short'        => 'Borrar',
    'delete_confirm'      => '¿Borrar este archivo?',
    'drop_to_replace'     => 'Suelta para reemplazar',
    'file_label'          => 'Archivo',

    // Estado staged (deferred)
    'pending'             => 'Pendiente de subir',
    'pending_short'       => 'Pendiente',
    'submit'              => 'Subir',
    'cancel'              => 'Cancelar',

    // Estados de proceso
    'uploading'           => 'Subiendo...',
    'preparing'           => 'Preparando...',
    'processing'          => 'Procesando',
    'processing_dots'     => 'Procesando...',
    'loading'             => 'Cargando...',

    // Errores
    'failed'              => 'Error',
    'failed_long'         => 'Error al procesar',
];
