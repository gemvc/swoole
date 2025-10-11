<?php
/**
 * GEMVC Apache Bootstrap
 * 
 * This is the main entry point for Apache-based GEMVC applications.
 * All HTTP requests are routed through this file via .htaccess rewrite rules.
 */

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load Composer autoloader
require PROJECT_ROOT . '/vendor/autoload.php';

// Load environment variables
Gemvc\Helper\ProjectHelper::loadEnv();

// Create request handler
$request = new Gemvc\Http\ApacheRequest();

// Get the requested API endpoint
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/index.php', '', $uri);
$uri = trim($uri, '/');

// Default to Index if no URI specified
if (empty($uri)) {
    $uri = 'Index';
}

// Convert URI to API class (e.g., 'user' -> 'User', 'user/profile' -> 'User')
$parts = explode('/', $uri);
$apiName = ucfirst(strtolower($parts[0]));

// Build API class name
$apiClass = "App\\Api\\{$apiName}";

// Check if API class exists
if (!class_exists($apiClass)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Not Found',
        'message' => "API endpoint not found: {$apiName}"
    ]);
    exit;
}

try {
    // Instantiate and execute API
    $api = new $apiClass($request);
    
    // Get HTTP method
    $method = strtolower($_SERVER['REQUEST_METHOD']);
    
    // Call appropriate method based on HTTP verb
    if (method_exists($api, $method)) {
        $response = $api->$method();
    } elseif (method_exists($api, 'handle')) {
        $response = $api->handle();
    } else {
        throw new Exception("Method {$method} not supported for {$apiName}");
    }
    
    // Send response
    if ($response instanceof Gemvc\Http\ResponseInterface) {
        $response->send();
    } else {
        // Default JSON response
        header('Content-Type: application/json');
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}

