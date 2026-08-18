<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         | NOTE: `root` is public_path('storage') — uploads are written STRAIGHT
         | into public/storage, there is no symlink to storage/app/public. So
         | `php artisan storage:link --force` REPLACES that directory with a
         | symlink and destroys every uploaded receipt. Never run it here.
         |
         | `url` is the browsable prefix, which is not always APP_URL.'/storage':
         | on a host whose document root is the project root rather than
         | `public/`, the same file is served at `/public/storage/…`.
         |
         | The knob for that is ASSET_URL (`ASSET_URL=https://host/public`), NOT
         | APP_URL — APP_URL also drives password-reset and signed-route links.
         | Deriving this disk from ASSET_URL keeps `Storage::disk('public')->url()`
         | (dashboard attachments) and `asset('storage/…')` (the mobile modules'
         | images) pointing at the SAME place; setting only one of them fixes
         | half the app and leaves the other half 404ing.
         |
         | FILESYSTEM_PUBLIC_URL stays available for the case where uploads move
         | behind a CDN/S3 host of their own. The base is rtrim'd so a trailing
         | slash cannot produce `https://host//storage/…`, which some web
         | servers (Hostinger) 404 outright.
         */
        'public' => [
            'driver' => 'local',
            'root' => public_path('storage'),
            'url' => env('FILESYSTEM_PUBLIC_URL', rtrim((string) (env('ASSET_URL') ?: env('APP_URL')), '/').'/storage'),
            'visibility' => 'public',
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
