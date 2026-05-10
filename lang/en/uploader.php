<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploader strings
    |--------------------------------------------------------------------------
    |
    | Strings used by <livewire:laracrate-uploader> and
    | <livewire:laracrate-uploader-deferred>. Override from the app via
    | `php artisan vendor:publish --tag=laracrate-translations`.
    |
    */

    // Empty state (instant)
    'upload'              => 'Upload file',
    'choose'              => 'Choose',
    'drag_or_click'       => 'Drag or click',
    'drag_or_click_long'  => 'Drag a file or click',
    'max_size'            => 'max :size MB',
    'max_size_capital'    => 'Max. :size MB',

    // Empty state (deferred)
    'select'              => 'Select file',

    // File state
    'replace'             => 'Replace',
    'delete'              => 'Delete',
    'delete_short'        => 'Remove',
    'delete_confirm'      => 'Delete this file?',
    'drop_to_replace'     => 'Drop to replace',
    'file_label'          => 'File',

    // Staged state (deferred)
    'pending'             => 'Pending upload',
    'pending_short'       => 'Pending',
    'submit'              => 'Upload',
    'cancel'              => 'Cancel',

    // Processing
    'uploading'           => 'Uploading...',
    'preparing'           => 'Preparing...',
    'processing'          => 'Processing',
    'processing_dots'     => 'Processing...',
    'loading'             => 'Loading...',

    // Errors
    'failed'              => 'Error',
    'failed_long'         => 'Processing failed',
];
