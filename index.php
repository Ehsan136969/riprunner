<?php
// Root entrypoint for Liara/Apache
// Boot the PHP app from /php (routes.php is the main router)

// Ensure vendor dependencies are available from the bundled archive.
$vendorAutoload = __DIR__ . '/php/vendor/autoload.php';
if (file_exists($vendorAutoload) === false) {
    $vendorZip = __DIR__ . '/vendor-php-8.3.3.zip';
    if (file_exists($vendorZip) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($vendorZip) === true) {
            $zip->extractTo(__DIR__ . '/php');
            $zip->close();
        }
    }
}

chdir(__DIR__ . '/php');
require __DIR__ . '/php/routes.php';
