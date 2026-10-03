<?php

namespace Blutrixx\PdfViewer;

use Illuminate\Support\ServiceProvider;

class PdfViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pdf-viewer.php', 'pdf-viewer');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pdf-viewer.php' => config_path('pdf-viewer.php'),
        ], 'nativephp-pdf-viewer-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
