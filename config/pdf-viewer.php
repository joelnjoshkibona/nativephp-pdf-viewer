<?php

return [
    /* Local routes used to stage, render, and share PDFs on the device. */
    'route_prefix' => 'native-pdf-viewer',
    'middleware' => ['web'],

    /* Files are stored on the app's private local disk, never on a public disk. */
    'storage_disk' => 'local',
    'storage_path' => 'native-pdf-viewer',

    /* Maximum PDF size accepted by the local staging route. */
    'max_file_size_mb' => 20,

    /* Staged source PDFs and rendered pages older than this are removed on upload. */
    'retention_hours' => 24,

    /* Upper bound for each native-rendered page image. */
    'native_page_width' => 1200,
];
