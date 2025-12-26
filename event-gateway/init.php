<?php

require_once __DIR__ . '/vendor/autoload.php';

$namespaceDirs = [
    'Services\\' => realpath(__DIR__ . '/../Services'),
    'Filters\\' => realpath(__DIR__ . '/app/Filters'),
];

foreach ($namespaceDirs as $prefix => $directory) {
    if ($directory === false) {
        continue;
    }
    spl_autoload_register(static function ($class) use ($prefix, $directory) {
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $directory . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

use SDPMlab\Anser\Service\ServiceList;

ServiceList::addLocalService(
    name: "ProductionService",
    address: "127.0.0.1",
    port: 8081,
    isHttps: false
);

ServiceList::addLocalService(
    name: "UserService",
    address: "127.0.0.1",
    port: 8080,
    isHttps: false
);

ServiceList::addLocalService(
    name: "OrderService",
    address: "127.0.0.1",
    port: 8082,
    isHttps: false
);

$logDir = __DIR__ . DIRECTORY_SEPARATOR . "Logs";
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
define("LOG_PATH", $logDir . DIRECTORY_SEPARATOR);
