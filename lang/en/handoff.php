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

    'unsupported'   => 'This editor does not open this kind of file.',
    'popup_blocked' => 'The browser blocked the editor window. Allow pop-ups for this site and try again.',
    'open_failed'   => 'The file could not be sent to the editor.',

    'title'         => 'Save a copy',
    'waiting'       => 'Waiting for the file from :editor…',
    'not_opened'    => 'This page opens from the editor, when you save there.',
    'untrusted'     => 'This page only takes files from :editor.',
    'from'          => 'From :host',
    'next_to'       => 'Saved as a copy next to “:name”',
    'in_folder'     => 'in the folder “:folder”',
    'name'          => 'Name',
    'save'          => 'Save copy',
    'cancel'        => 'Cancel',
    'saving'        => 'Saving… :percent %',
    'saved'         => 'Saved. You can close this window.',
    'failed'        => 'It could not be saved: :reason',
    'too_large'     => 'The file is too large to save here.',
    'not_accepted'  => 'This kind of file cannot be saved here.',
    'edited'        => 'edited',

];
