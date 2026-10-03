# NativePHP PDF Viewer

`blutrixx/nativephp-pdf-viewer` is a NativePHP Mobile plugin that provides a reusable PDF preview and share component for Laravel apps. It gives an app a consistent PDF viewing UI while letting the app keep ownership of how each document is loaded and authorized.

This plugin previews and shares existing PDFs. It does not create PDFs or replace the server-side PDF renderer used to produce them. It ships Laravel routes/configuration and a Vue component, but no platform bridge of its own: Android rendering is delegated to `blutrixx/nativephp-pdf-renderer`, and native sharing is delegated to `nativephp/mobile-share`.

## Rendering choices

| Mode | How it works | Platforms |
| --- | --- | --- |
| `auto` (default) | Uses the optional Blutrixx native renderer when its bridge can open the PDF; falls back to PDF.js otherwise. | Native rendering on Android; PDF.js fallback on Android and iOS. |
| `native` | Requires the optional Blutrixx native renderer and a working native bridge. | Android only. |
| `pdfjs` | Renders pages in the WebView using PDF.js. | Android and iOS. |

The native renderer is an optional Composer dependency because it is not required for PDF.js previews. It is a separate package from this viewer. Native share sheets are provided by `nativephp/mobile-share` and are available on the platforms supported by that plugin.

## Requirements

- PHP 8.2 or later
- Laravel 11, 12, or 13
- NativePHP Mobile 3.3.8 or later
- Node.js tooling for the host app's Vite build
- `axios` in the host app for the viewer's local Laravel requests
- `pdfjs-dist` in the host app when using `auto` or `pdfjs`
- `blutrixx/nativephp-pdf-renderer` when selecting `native` or when enabling Android native rendering in `auto`

## Installation

Install the plugin through Composer and add its frontend dependencies:

```bash
composer require blutrixx/nativephp-pdf-viewer
npm install axios pdfjs-dist
php artisan vendor:publish --tag=nativephp-pdf-viewer-config
```

To enable Android native page rendering, install `blutrixx/nativephp-pdf-renderer` in the app as well. Without it, `auto` uses PDF.js.

Composer auto-discovers the Laravel service provider. NativePHP also requires an explicit plugin allowlist entry in `app/Providers/NativeServiceProvider.php`:

```php
public function plugins(): array
{
    return [
        // Keep your other NativePHP plugin providers here.
        \Blutrixx\PdfViewer\PdfViewerServiceProvider::class,
    ];
}
```

Keep the existing NativePHP plugins in this array. The viewer's manifest currently declares no bridge functions because it delegates native work to the renderer and share plugins. If your app disables Composer package discovery, also add `Blutrixx\PdfViewer\PdfViewerServiceProvider::class` to its Laravel providers.

### Expose the Vue component to Vite

The PHP package ships `resources/js/PdfViewer.vue`; it does not publish or overwrite files in the host app. Add an alias to the app's `vite.config.js` (or `vite.config.ts`):

```js
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.js'], refresh: true }), vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@nativephp-pdf-viewer': fileURLToPath(new URL('./vendor/blutrixx/nativephp-pdf-viewer/resources/js', import.meta.url)),
        },
    },
})
```

Merge the alias into the existing Vite config rather than replacing its plugins or aliases. If Vite cannot resolve a symlinked or Composer-installed Vue file in your setup, copy the component into your app's source tree and import it from there; keep the package routes and config provided by Composer.

## Basic use

The component is controlled by `v-model:open`. Give it a loader that returns a `Blob`, `ArrayBuffer`, or typed array. The loader runs when the viewer opens, so it can make an authenticated request using the app's existing API client.

```vue
<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import PdfViewer from '@nativephp-pdf-viewer/PdfViewer.vue'

const previewOpen = ref(false)
const quotationId = ref('quotation-uuid')

async function loadQuotationPdf(): Promise<Blob> {
    const response = await axios.get(`/api/quotations/${quotationId.value}/pdf`, {
        responseType: 'blob',
    })

    return response.data
}
</script>

<template>
    <button type="button" @click="previewOpen = true">Preview quotation</button>

    <PdfViewer
        v-model:open="previewOpen"
        :load-document="loadQuotationPdf"
        filename="quotation-QT-2026-0042.pdf"
        title="Quotation"
        message="Here is your quotation."
        renderer="auto"
        @loaded="({ pageCount, renderer }) => console.log({ pageCount, renderer })"
        @error="(error) => console.error(error)"
    />
</template>
```

Your loader should return the PDF bytes only. The viewer does not assume a backend URL, endpoint shape, API token, or business module. The app's API remains responsible for access control and generating or retrieving the PDF.

## Props

| Prop | Type | Default | Description |
| --- | --- | --- | --- |
| `open` | `boolean` | required | Opens the full-screen viewer when true. Use with `v-model:open`. |
| `loadDocument` | `() => Promise<Blob \| ArrayBuffer \| ArrayBufferView>` | required | Loads the PDF bytes. Called each time the viewer opens and when `filename` changes while open. |
| `filename` | `string` | required | Display name and staged file name. The extension is normalized to `.pdf`. |
| `title` | `string` | `PDF document` | Accessible dialog label and native share title. |
| `message` | `string` | empty | Text passed to the native share sheet. |
| `renderer` | `'auto' \| 'native' \| 'pdfjs'` | `auto` | Renderer policy described above. |
| `endpoint` | `string` | `/native-pdf-viewer` | Base path for this package's local Laravel routes. |
| `shareable` | `boolean` | `true` | Shows or hides the native share action. |

## Events and exposed methods

| Name | Payload / behavior |
| --- | --- |
| `update:open` | Emits `false` when the close button is pressed. |
| `loaded` | Emits `{ pageCount, renderer }` after the first page is ready. `renderer` is `native` or `pdfjs`. |
| `error` | Emits an `Error` when loading, rendering, or sharing fails. A readable message is also shown in the viewer. |
| `reload()` | Re-runs `loadDocument` and reloads the current PDF. |
| `share()` | Opens the platform share sheet for the currently loaded PDF. |
| `goToPage(page)` | Displays a page number in the valid range. |

Exposed methods are available through a Vue template ref. Page navigation controls are built into the viewer.

## Theming

The viewer reads the active CSS custom properties from its host page, so a class-based dark-mode
toggle (for example, adding or removing `dark` on `<html>`) updates the viewer while it is open.
It uses `--background`, `--foreground`, `--card`, `--card-foreground`, `--border`, `--muted`,
`--muted-foreground`, `--primary`, `--primary-foreground`, and `--destructive`. Each property has
a light fallback for apps that do not define the token. The PDF page itself remains white to
preserve the document's paper appearance.

## Configuration

Publish `config/pdf-viewer.php` to customize the viewer's local routes and temporary storage:

| Key | Default | Description |
| --- | --- | --- |
| `route_prefix` | `native-pdf-viewer` | URI prefix for the staging, page-image, and sharing endpoints. |
| `middleware` | `['web']` | Laravel middleware applied to these local routes. Add the same auth middleware used by the host app when its local app routes require it. Keep `web` if the app relies on Laravel's session and CSRF handling. |
| `storage_disk` | `local` | Private Laravel filesystem disk used for staged PDFs. Avoid public or remotely mounted disks for native rendering. |
| `storage_path` | `native-pdf-viewer` | Directory under the configured disk. |
| `max_file_size_mb` | `20` | Maximum PDF size accepted by the local staging route. |
| `retention_hours` | `24` | Staged files older than this are deleted when another PDF is staged. |
| `native_page_width` | `1200` | Width used for Android-rendered page images, clamped to 320–2400 pixels. |

Set `endpoint` on the component when `route_prefix` is changed. The configured prefix is registered at Laravel boot, so clear cached routes/configuration after changing it in a deployed build.

## How it works

1. The app calls `loadDocument()` and provides the PDF bytes.
2. PDF.js can display those bytes directly in the WebView. Native rendering and sharing first stage a private copy through the package's same-app Laravel routes.
3. On Android, the optional native renderer creates a PNG image for the requested page. Otherwise PDF.js renders the page in the WebView.
4. Sharing passes the staged local file path to NativePHP's share plugin.

Temporary files are stored under Laravel's configured local storage, never in a public directory. Cleanup runs when a new file is staged; apps that preview PDFs infrequently can remove the configured directory as part of their own scheduled cleanup. The source PDF and generated page images are removed together when an expired document is cleaned up.

## Limitations

- `native` rendering requires Android and the separate `blutrixx/nativephp-pdf-renderer` NativePHP plugin. iOS uses PDF.js through `auto`.
- `pdfjs-dist` must be installed by the host app. It is dynamically loaded only for PDF.js rendering.
- The package does not fetch protected PDFs from your backend. Provide a loader that uses your app's authentication and returns the bytes.
- The package does not create PDFs, annotate them, fill forms, print, or provide text search. Use a PDF generation package on the backend for document creation.
- Native sharing requires `nativephp/mobile-share` and is intended to run inside a NativePHP Mobile app.
- The staging endpoint receives base64-encoded JSON, so the configured PDF limit should remain below PHP's request body limits. Raise the app's request limits if you deliberately increase `max_file_size_mb`.

## Troubleshooting

### Vite reports that `pdfjs-dist` cannot be resolved

Run `npm install pdfjs-dist` in the host app and rebuild its frontend bundle. PDF.js is provided by the host app's npm dependency tree, not Composer.

### Native rendering is unavailable

Install and register `blutrixx/nativephp-pdf-renderer`, include its NativePHP plugin in the mobile build, and run on Android. Use `renderer="auto"` to let the viewer fall back to PDF.js on other platforms.

### Staging returns 419 or 401

Check the configured local middleware and the app's CSRF/session setup. If the app adds auth middleware, make sure the NativePHP WebView has the same authenticated session when it calls the package endpoints. The viewer sends the page's `csrf-token` meta value when present.

### The document is rejected as too large

Lower the source PDF size or raise `max_file_size_mb` together with the PHP request size limits. Base64 adds about one third to the JSON request size.

## Development

This plugin is distributed through Composer, including its Vue component and NativePHP manifest. No npm build step is required inside this repository.

```bash
composer validate --no-check-publish
find src routes config -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

MIT. See [LICENSE](LICENSE).
