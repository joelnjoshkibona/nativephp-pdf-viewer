<?php

namespace Blutrixx\PdfViewer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Share;
use Throwable;

class PdfViewerController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pdf_base64' => ['required', 'string'],
            'filename' => ['required', 'string', 'max:255'],
            'renderer' => ['sometimes', 'string', 'in:auto,native,pdfjs'],
        ]);

        $encoded = $validated['pdf_base64'];
        $maxBytes = max(1, (int) config('pdf-viewer.max_file_size_mb', 20)) * 1024 * 1024;
        if (strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4) {
            return response()->json(['message' => "The PDF must be no larger than ".config('pdf-viewer.max_file_size_mb', 20).' MB.'], 413);
        }

        $contents = base64_decode($encoded, true);
        if ($contents === false || strlen($contents) > $maxBytes || ! str_starts_with($contents, '%PDF-')) {
            return response()->json(['message' => 'The supplied data is not a valid PDF or exceeds the configured size limit.'], 422);
        }

        $this->removeExpiredFiles();

        $document = (string) Str::uuid();
        $directory = trim(config('pdf-viewer.storage_path', 'native-pdf-viewer'), '/').'/'.$document;
        $filename = $this->safeFilename($validated['filename']);
        $relativePath = $directory.'/'.$filename;
        $disk = Storage::disk(config('pdf-viewer.storage_disk', 'local'));
        $disk->makeDirectory($directory);
        if (! $disk->put($relativePath, $contents)) {
            return response()->json(['message' => 'Could not stage this PDF on the device.'], 500);
        }

        $requestedRenderer = $validated['renderer'] ?? 'auto';
        $native = $requestedRenderer === 'pdfjs' ? null : $this->nativeRenderer();
        if ($requestedRenderer === 'native' && $native === null) {
            $disk->delete($relativePath);

            return response()->json(['message' => 'Native PDF rendering is unavailable. Install Blutrixx NativePHP PDF Renderer and use Android.'], 422);
        }

        if ($native !== null) {
            try {
                $pageCount = $native->getPageCount($disk->path($relativePath));
                if ($pageCount > 0) {
                    return response()->json(['id' => $document, 'renderer' => 'native', 'page_count' => $pageCount]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($requestedRenderer === 'native') {
            $disk->delete($relativePath);

            return response()->json(['message' => 'The native PDF renderer could not open this document.'], 422);
        }

        return response()->json(['id' => $document, 'renderer' => 'pdfjs', 'page_count' => null]);
    }

    public function renderPage(string $document, int $page): JsonResponse
    {
        $renderer = $this->nativeRenderer();
        if ($renderer === null) {
            return response()->json(['message' => 'Native PDF rendering is unavailable.'], 501);
        }

        $disk = Storage::disk(config('pdf-viewer.storage_disk', 'local'));
        $relativePath = $this->documentPath($document);
        if (! $disk->exists($relativePath)) {
            return response()->json(['message' => 'This staged PDF has expired. Reopen the document to try again.'], 404);
        }

        try {
            $pageCount = $renderer->getPageCount($disk->path($relativePath));
            if ($page < 1 || $page > $pageCount) {
                return response()->json(['message' => 'The requested page does not exist.'], 404);
            }

            $width = min(2400, max(320, (int) config('pdf-viewer.native_page_width', 1200)));
            $outputDirectory = dirname($disk->path($relativePath)).'/pages';
            if (! is_dir($outputDirectory)) {
                mkdir($outputDirectory, 0700, true);
            }
            $outputPath = $outputDirectory.'/'.$document.'-'.$page.'.png';
            // The native Android bridge expects a one-based page number.
            $result = $renderer->renderPage($disk->path($relativePath), $page, $width, $outputPath);
            $imagePath = $result['cachePath'] ?? $result['path'] ?? $outputPath;
            if (! is_file($imagePath) || ! is_readable($imagePath)) {
                return response()->json(['message' => 'The native renderer did not produce a page image.'], 500);
            }

            return response()->json([
                'page' => $page,
                'page_count' => $pageCount,
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'image' => 'data:image/png;base64,'.base64_encode(file_get_contents($imagePath)),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Could not render this PDF page on the device.'], 500);
        }
    }

    public function share(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $disk = Storage::disk(config('pdf-viewer.storage_disk', 'local'));
        $relativePath = $this->documentPath($document);
        if (! $disk->exists($relativePath)) {
            return response()->json(['message' => 'This staged PDF has expired. Reopen the document to try again.'], 404);
        }

        Share::file(
            $validated['title'] ?? 'Share PDF',
            $validated['message'] ?? '',
            $disk->path($relativePath),
        );

        return response()->json(['status' => true]);
    }

    private function nativeRenderer(): ?object
    {
        $class = 'Blutrixx\\PdfRenderer\\PdfRenderer';

        return class_exists($class) ? app($class) : null;
    }

    private function documentPath(string $document): string
    {
        $directory = trim(config('pdf-viewer.storage_path', 'native-pdf-viewer'), '/').'/'.$document;
        $disk = Storage::disk(config('pdf-viewer.storage_disk', 'local'));
        $files = glob(rtrim($disk->path($directory), '/').'/*.pdf') ?: [];

        return $files ? $directory.'/'.basename($files[0]) : $directory.'/document.pdf';
    }

    private function safeFilename(string $filename): string
    {
        $name = pathinfo(basename($filename), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-_');

        return ($name !== '' ? $name : 'document').'.pdf';
    }

    private function removeExpiredFiles(): void
    {
        $disk = Storage::disk(config('pdf-viewer.storage_disk', 'local'));
        $directory = trim(config('pdf-viewer.storage_path', 'native-pdf-viewer'), '/');
        $root = $disk->path($directory);
        if (! is_dir($root)) {
            return;
        }

        $expiresBefore = now()->subHours(max(1, (int) config('pdf-viewer.retention_hours', 24)))->timestamp;
        foreach (glob($root.'/*/*.pdf') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $expiresBefore) {
                $disk->deleteDirectory($directory.'/'.basename(dirname($path)));
            }
        }
    }
}
