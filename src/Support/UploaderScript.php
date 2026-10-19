<?php

namespace EduLazaro\Laracrate\Support;

/**
 * The browser upload engine (resources/js/laracrate-uploader.js) as inline source,
 * followed by its configuration: the upload routes (the prefix is configurable) and
 * the messages in the app locale.
 *
 * Laracrate does not publish assets, so a view that needs the engine prints it inside
 * its own <script>. The engine guards itself, so printing it more than once per page
 * (several components, a navigation) defines window.Laracrate.uploads only once.
 */
final class UploaderScript
{
    private static ?string $engine = null;

    public static function source(): string
    {
        self::$engine ??= (string) file_get_contents(__DIR__ . '/../../resources/js/laracrate-uploader.js');

        $config = json_encode([
            'signUrl'    => route('laracrate.uploads.sign', absolute: false),
            'confirmUrl' => route('laracrate.uploads.confirm', absolute: false),
            'registerUrl' => route('laracrate.uploads.register', absolute: false),
            'messages'   => [
                'network' => __('laracrate::uploader.network'),
                'stalled' => __('laracrate::uploader.stalled'),
                'expired' => __('laracrate::uploader.grant_invalid'),
                'failed'  => __('laracrate::uploader.rejected.generic'),
                'leave'   => __('laracrate::uploader.tray.leave_warning'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return self::$engine . "\nwindow.Laracrate.uploads.configure({$config});\n";
    }
}
