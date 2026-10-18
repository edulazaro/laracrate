<?php

namespace EduLazaro\Laracrate\Support;

/**
 * Files a browser runs instead of showing: HTML, SVG, scripts, XML, PHP.
 *
 * Served from a public bucket or a public local disk, they would put a page of the
 * uploader's choosing on the app's own storage domain. Uploads refuse them unless a
 * collection lists the MIME in its `accepted_mime_types` on purpose.
 */
final class ActiveContent
{
    private const MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'application/x-javascript', 'application/ecmascript',
        'application/x-httpd-php', 'application/x-php', 'text/x-php', 'application/x-sh',
    ];

    private const EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl',
        'js', 'mjs', 'php', 'phtml', 'phar', 'sh',
    ];

    public static function is(?string $mime, ?string $fileName): bool
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));
        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        return in_array($mime, self::MIMES, true) || in_array($extension, self::EXTENSIONS, true);
    }
}
