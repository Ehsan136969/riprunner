<?php
// Root entrypoint for Liara/Apache
// Boot the PHP app from /php (routes.php is the main router)

// Ensure vendor dependencies are available from the bundled archive.
$vendorAutoload = __DIR__ . '/php/vendor/autoload.php';
if (file_exists($vendorAutoload) === false) {
    $vendorZip = __DIR__ . '/vendor-php-8.3.3.zip';
    if (file_exists($vendorZip) && class_exists('ZipArchive')) {
        $extractBase = __DIR__ . '/php/vendor';
        if (!is_dir($extractBase)) {
            @mkdir($extractBase, 0775, true);
        }

        $extractTarget = $extractBase;
        if (!is_writable($extractBase)) {
            $extractTarget = rtrim(sys_get_temp_dir(), '/\\') . '/riprunner_vendor';
            if (!is_dir($extractTarget)) {
                @mkdir($extractTarget, 0775, true);
            }
        }

        if (is_dir($extractTarget) && is_writable($extractTarget)) {
            $zip = new ZipArchive();
            if ($zip->open($vendorZip) === true) {
                $zip->extractTo($extractTarget);
                $zip->close();
            }
        }
    }
    $nestedAutoload = __DIR__ . '/php/vendor/vendor/autoload.php';
    if (file_exists($vendorAutoload) === false && file_exists($nestedAutoload)) {
        @copy($nestedAutoload, $vendorAutoload);
    }
}

if (file_exists($vendorAutoload) === false) {
    $tempAutoload = rtrim(sys_get_temp_dir(), '/\\') . '/riprunner_vendor/vendor/autoload.php';
    if (file_exists($tempAutoload)) {
        $vendorAutoload = $tempAutoload;
    }
}

chdir(__DIR__ . '/php');
require __DIR__ . '/php/routes.php';
