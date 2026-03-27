<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Router;
use Dotenv\Dotenv;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$router = new Router();
$router->handle($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
