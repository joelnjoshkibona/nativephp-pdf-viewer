<?php

use Blutrixx\PdfViewer\Http\Controllers\PdfViewerController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('pdf-viewer.middleware', ['web']))
    ->prefix(config('pdf-viewer.route_prefix', 'native-pdf-viewer'))
    ->name('native-pdf-viewer.')
    ->group(function (): void {
        Route::post('/documents', [PdfViewerController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}/pages/{page}', [PdfViewerController::class, 'renderPage'])
            ->whereUuid('document')
            ->whereNumber('page')
            ->name('documents.pages.show');
        Route::post('/documents/{document}/share', [PdfViewerController::class, 'share'])
            ->whereUuid('document')
            ->name('documents.share');
    });
