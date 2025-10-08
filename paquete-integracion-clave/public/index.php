<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../simplesamlphp/vendor/autoload.php';

use SPClave\Controllers\PageController;

$router = new PageController();

// Se obtiene la url y se limpian los parámetros GET en caso de tener alguno
$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'];

switch ($path) {
    case '/':
        $router->home();
        break;

    case '/login':
        $router->login();
        break;

    case '/profile':
        $router->profile();
        break;

    case '/error':
        $router->error();
        break;

    case '/logout':
        $router->logout();
        break;

    default:
        http_response_code(404);
        break;
}
