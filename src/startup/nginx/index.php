<?php
require_once 'vendor/autoload.php';

use Gemvc\Core\Bootstrap;
use Gemvc\Http\ApacheRequest; // Nginx uses similar request handling to Apache
use Gemvc\Http\NoCors;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');

// Apply CORS headers using the apache method (compatible with Nginx)
NoCors::apache();

// Nginx with PHP-FPM uses similar request handling to Apache
$webserver = new ApacheRequest();
$bootstrap = new Bootstrap($webserver->request);
