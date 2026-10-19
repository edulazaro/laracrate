<?php

use EduLazaro\Laracrate\Http\Controllers\FileStatusController;
use EduLazaro\Laracrate\Http\Controllers\HandoffController;
use EduLazaro\Laracrate\Http\Controllers\Local\LocalServeController;
use EduLazaro\Laracrate\Http\Controllers\Local\LocalUploadController;
use EduLazaro\Laracrate\Http\Controllers\MultipartUploadController;
use EduLazaro\Laracrate\Http\Controllers\PresignedUploadController;
use EduLazaro\Laracrate\Http\Controllers\StreamFileController;
use EduLazaro\Laracrate\Http\Controllers\UploadController;
use EduLazaro\Laracrate\Http\Controllers\UploadTargetController;
use EduLazaro\Laracrate\Http\Controllers\ViewerController;
use Illuminate\Support\Facades\Route;

$prefix     = config('laracrate.stream.route_prefix', 'laracrate/files');
$middleware = config('laracrate.stream.middleware', ['web', 'auth']);
$namePrefix = config('laracrate.stream.route_name_prefix', 'laracrate.files');

// Files served through PHP (stream, download) and the viewer's data, behind the session.
Route::middleware($middleware)
    ->prefix($prefix)
    ->name($namePrefix . '.')
    ->group(function () {
        Route::get('{file:slug}/stream',   [StreamFileController::class, 'stream'])->name('stream');
        Route::get('{file:slug}/download', [StreamFileController::class, 'download'])->name('download');
        // What the viewer shows, with a link made at request time. See ViewerController.
        Route::get('{file:slug}/viewer', ViewerController::class)->name('viewer');
        // Handoff: open in an editor of another site, and the page it saves the copy into.
        Route::get('{file:slug}/handoff/{editor}', [HandoffController::class, 'open'])->name('handoff');
        Route::get('{file:slug}/handoff/{editor}/save', [HandoffController::class, 'save'])->name('handoff.save');
    });

// Endpoints internos del Local driver — equivalentes a presigned PUT y GET.
// SubstituteBindings resolves {file:slug}: without it the controller got an empty File.
Route::middleware(['signed', \Illuminate\Routing\Middleware\SubstituteBindings::class])
    ->prefix('_laracrate/local')
    ->name('laracrate.local.')
    ->group(function () {
        Route::post('upload', [LocalUploadController::class, 'store'])->name('upload');
        Route::get('serve/{file:slug}', [LocalServeController::class, 'serve'])->name('serve');
    });

// Presigned uploads para R2/S3. La app define autorización vía middleware.
Route::middleware(config('laracrate.uploads.middleware', ['web', 'auth']))
    ->prefix(config('laracrate.uploads.route_prefix', 'laracrate/uploads'))
    ->name('laracrate.uploads.')
    ->group(function () {
        Route::post('presign', [PresignedUploadController::class, 'presign'])->name('presign');
        Route::delete('{disk}/{encodedKey}', [PresignedUploadController::class, 'cancel'])->name('cancel');

        // Grant based: the components and the upload tray. See UploadController.
        Route::post('sign', [UploadController::class, 'sign'])->name('sign');
        Route::post('confirm', [UploadController::class, 'confirm'])->name('confirm');
        Route::post('receive', [UploadController::class, 'receive'])->name('receive');
        Route::post('register', [UploadController::class, 'register'])->name('register');
        Route::post('remove', [UploadController::class, 'remove'])->name('remove');

        // The targets the app declares, for the React and Svelte components. See UploadTargets.
        Route::post('targets/{target}', UploadTargetController::class)->name('target');
    });

// Multipart upload (archivos > 100 MB) directos a S3/R2.
// `??` and not config()'s default: the shipped config sets this key to null, and a
// key that exists returns its null, which left these routes with no middleware at all.
Route::middleware(config('laracrate.multipart.middleware') ?? config('laracrate.uploads.middleware') ?? ['web', 'auth'])
    ->prefix(config('laracrate.multipart.route_prefix', 'laracrate/multipart'))
    ->name('laracrate.multipart.')
    ->group(function () {
        Route::post('init',                   [MultipartUploadController::class, 'init'])->name('init');
        Route::post('{multipart}/parts',      [MultipartUploadController::class, 'reissueParts'])->name('parts');
        Route::post('{multipart}/complete',   [MultipartUploadController::class, 'complete'])->name('complete');
        Route::delete('{multipart}',          [MultipartUploadController::class, 'abort'])->name('abort');
    });

// Polling de estado de procesamiento. Single + batch.
Route::middleware(config('laracrate.status.middleware', ['web', 'auth']))
    ->prefix(config('laracrate.status.route_prefix', 'laracrate/files'))
    ->name('laracrate.files.')
    ->group(function () {
        Route::get('{file:slug}/status', [FileStatusController::class, 'show'])->name('status');
        Route::post('status',            [FileStatusController::class, 'batch'])->name('status.batch');
    });
