<?php
require_once 'vendor/autoload.php';

use Gemvc\Core\Bootstrap;
use Gemvc\Http\ApacheRequest;
use Gemvc\Http\NoCors;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');
// Apply CORS headers using the new apache method
NoCors::apache();
$webserver = new ApacheRequest();
$bootstrap = new Bootstrap($webserver->request);
