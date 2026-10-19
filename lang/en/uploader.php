<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploader strings
    |--------------------------------------------------------------------------
    |
    | Strings used by <livewire:laracrate-uploader>, instant and deferred.
    | Override from the app via
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

    // "Max reached" state (deferred, when the slot quota is exhausted)
    'max_reached_title'   => 'No more files can be added',
    'max_reached_unit'    => 'file(s)',

    // Built-in slot picker
    'slot_placeholder'    => 'Unclassified',
    'slot_required_hint'  => 'Pick an option to upload files',

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
    'in_queue'            => 'in queue',
    'retry'               => 'Retry',
    'view'                => 'View',
    'processing'          => 'Processing',
    'processing_dots'     => 'Processing...',
    'loading'             => 'Loading...',

    // Errors
    'failed'              => 'Error',
    'failed_long'         => 'Processing failed',

    // Uploads: refusals and tray
    'grant_invalid'       => 'This page has expired. Reload it to keep uploading.',
    'target_refused'      => 'You cannot upload files here.',
    'not_received'        => 'The file did not reach the storage. Try again.',
    'rejected' => [
        'type'            => 'This type of file is not accepted here.',
        'size'            => 'It exceeds the maximum size (:size MB).',
        'slot_extension'  => '":slot" only accepts :allowed.',
        'slot_full'       => '":slot" already has the maximum number of files (:limit).',
        'active'          => 'This type of file cannot be uploaded.',
        'too_many'        => 'No more files can be uploaded here.',
        'missing'         => 'The file did not reach the storage. Try again.',
        'generic'         => 'The file could not be saved.',
    ],
    'network'             => 'The connection was lost.',
    'stalled'             => 'The upload stalled.',
    'tray' => [
        'uploading'       => 'Uploading :count file|Uploading :count files',
        'saving'          => 'Saving…',
        'done'            => ':count uploaded|:count uploaded',
        'failed'          => ':count could not be uploaded|:count could not be uploaded',
        'retry'           => 'Retry',
        'dismiss'         => 'Dismiss',
        'cancel'          => 'Cancel',
        'collapse'        => 'Collapse',
        'expand'          => 'Expand',
        'close'           => 'Close',
        'leave_warning'   => 'Uploads are in progress. Leaving now will cancel them.',
    ],
];
