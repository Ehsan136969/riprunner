<?php
// Root entrypoint for Liara/Apache
// Boot the PHP app from /php (routes.php is the main router)

chdir(__DIR__ . '/php');
require __DIR__ . '/php/routes.php';
