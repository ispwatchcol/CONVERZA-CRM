<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tipos de media que se descargan automáticamente
    |--------------------------------------------------------------------------
    |
    | Solo estos tipos se descargan y almacenan. Los demás guardan solo
    | metadata (media_id, mime, filename) para ahorrar espacio.
    | Opciones: image, audio, video, document
    |
    */
    'download_types' => array_filter(array_map(
        'trim',
        explode(',', env('MEDIA_DOWNLOAD_TYPES', 'image,audio')),
    )),

    /*
    |--------------------------------------------------------------------------
    | Compresión de imágenes
    |--------------------------------------------------------------------------
    */
    'compress_images' => env('MEDIA_COMPRESS_IMAGES', true),
    'max_width'       => (int) env('MEDIA_MAX_WIDTH', 1200),
    'jpeg_quality'    => (int) env('MEDIA_JPEG_QUALITY', 75),

    /*
    |--------------------------------------------------------------------------
    | Limpieza automática de medios antiguos
    |--------------------------------------------------------------------------
    |
    | Archivos más antiguos que estos días se eliminan del disco.
    | La metadata en la DB se conserva para referencia.
    |
    */
    'cleanup_days' => (int) env('MEDIA_CLEANUP_DAYS', 90),

];
