<?php
declare(strict_types=1);

/**
 * StockPulse — Front Controller & Custom Router (public/index.php)
 * ---------------------------------------------------------------------------
 * Single entry point for all page requests. A tiny closure-based router
 * maps (HTTP method, URL path) pairs to controller actions, keeping URL
 * handling out of the views and models.
 * ---------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Set to '1' while developing.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Marks this include chain as "page mode" for StockController.php.
define('STOCKPULSE_FRONT_CONTROLLER', true);
define('APP_ROOT', dirname(__DIR__));

/**
 * Base URL of the public folder (empty string when the docroot IS public/).
 * Views use it to build correct asset URLs regardless of sub-folder depth.
 */
$scriptDir = str_replace('\\', '/', (string) dirname($_SERVER['SCRIPT_NAME'] ?? ''));
define('BASE_URL', $scriptDir === '/' ? '' : rtrim($scriptDir, '/'));

/* ---------------------------------------------------------------------- */
/* Lightweight autoloader (PSR-4 style, explicit class map)              */
/* ---------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    $map = [
        'Database'        => APP_ROOT . '/config/Database.php',
        'StockModel'      => APP_ROOT . '/models/StockModel.php',
        'StockController' => APP_ROOT . '/controllers/StockController.php',
    ];
    if (isset($map[$class]) && is_file($map[$class])) {
        require_once $map[$class];
    }
});

$controller = new StockController();

/* ---------------------------------------------------------------------- */
/* Custom Router                                                          */
/* ---------------------------------------------------------------------- */
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri    = $_SERVER['REQUEST_URI'] ?? '/';
$path          = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');

// Normalize the path to be relative to the front controller (public/).
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir));
}
$path = trim($path, '/');

$routes = [
    'GET' => [
        ''          => fn () => $controller->renderDashboard(),
        'dashboard' => fn () => $controller->renderDashboard(),
    ],
    'POST' => [
        // The same JSON actions are also served by the direct
        // controllers/StockController.php?action=... endpoint used by Ajax.
        'transfer' => fn () => $controller->handleAjax('transfer'),
        'login'    => fn () => $controller->handleAjax('login'),
        'logout'   => fn () => $controller->handleAjax('logout'),
    ],
];

if (isset($routes[$requestMethod][$path])) {
    $routes[$requestMethod][$path]();
    exit;
}

http_response_code(404);
echo '<!doctype html><html><head><meta charset="utf-8"><title>404 Not Found</title></head>'
    . '<body><h1>404 — Page not found</h1><p>The requested resource does not exist.</p></body></html>';
