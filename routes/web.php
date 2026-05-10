<?php

use EduLazaro\Laracrate\Http\Controllers\FileStatusController;
use EduLazaro\Laracrate\Http\Controllers\Local\LocalServeController;
use EduLazaro\Laracrate\Http\Controllers\Local\LocalUploadController;
use EduLazaro\Laracrate\Http\Controllers\MultipartUploadController;
use EduLazaro\Laracrate\Http\Controllers\PresignedUploadController;
use EduLazaro\Laracrate\Http\Controllers\StreamFileController;
use Illuminate\Support\Facades\Route;

$prefix     = config('laracrate.stream.route_prefix', 'laracrate/files');
$middleware = config('laracrate.stream.middleware', ['web', 'auth']);
$namePrefix = config('laracrate.stream.route_name_prefix', 'laracrate.files');

// Streaming + descarga firmada del paquete (sensitive collections).
Route::middleware($middleware)
    ->prefix($prefix)
    ->name($namePrefix . '.')
    ->group(function () {
        Route::get('{file:slug}/stream',   [StreamFileController::class, 'stream'])->name('stream');
        Route::get('{file:slug}/preview',  [StreamFileController::class, 'preview'])->name('preview');
        Route::get('{file:slug}/download', [StreamFileController::class, 'download'])->name('download');
    });

// Endpoints internos del Local driver — equivalentes a presigned PUT y GET.
Route::middleware('signed')
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
    });

// Multipart upload (archivos > 100 MB) directos a S3/R2.
Route::middleware(config('laracrate.multipart.middleware', config('laracrate.uploads.middleware', ['web', 'auth'])))
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
