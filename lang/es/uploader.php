<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploader strings
    |--------------------------------------------------------------------------
    |
    | Textos de <livewire:laracrate-uploader>, inmediato y deferred.
    | Override desde la app vía
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

    // Estado "max alcanzado" (deferred, cuando se agota la cuota del slot)
    'max_reached_title'   => 'No puedes añadir más archivos',
    'max_reached_unit'    => 'archivo(s)',

    // Selector de slot integrado
    'slot_placeholder'    => 'Sin clasificar',
    'slot_required_hint'  => 'Elige una opción para poder subir archivos',

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
    'in_queue'            => 'en cola',
    'retry'               => 'Reintentar',
    'view'                => 'Ver',
    'processing'          => 'Procesando',
    'processing_dots'     => 'Procesando...',
    'loading'             => 'Cargando...',

    // Errores
    'failed'              => 'Error',
    'failed_long'         => 'Error al procesar',

    // Subidas: rechazos y bandeja
    'grant_invalid'       => 'Esta página ha caducado. Recárgala para seguir subiendo.',
    'target_refused'      => 'No puedes subir archivos aquí.',
    'not_received'        => 'El archivo no llegó al almacenamiento. Inténtalo de nuevo.',
    'rejected' => [
        'type'            => 'Este tipo de archivo no se admite aquí.',
        'size'            => 'Supera el tamaño máximo (:size MB).',
        'slot_extension'  => '«:slot» solo admite :allowed.',
        'slot_full'       => '«:slot» ya tiene el máximo de archivos (:limit).',
        'active'          => 'Este tipo de archivo no se puede subir.',
        'too_many'        => 'No se pueden subir más archivos aquí.',
        'missing'         => 'El archivo no llegó al almacenamiento. Inténtalo de nuevo.',
        'generic'         => 'No se ha podido guardar el archivo.',
    ],
    'network'             => 'Se cortó la conexión.',
    'stalled'             => 'La subida se quedó parada.',
    'tray' => [
        'uploading'       => 'Subiendo :count archivo|Subiendo :count archivos',
        'saving'          => 'Guardando…',
        'done'            => ':count subido|:count subidos',
        'failed'          => ':count no se ha podido subir|:count no se han podido subir',
        'retry'           => 'Reintentar',
        'dismiss'         => 'Quitar',
        'cancel'          => 'Cancelar',
        'collapse'        => 'Plegar',
        'expand'          => 'Desplegar',
        'close'           => 'Cerrar',
        'leave_warning'   => 'Hay subidas en curso. Si sales ahora, se cancelarán.',
    ],
];
