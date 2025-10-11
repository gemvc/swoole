<?php

namespace App\Api;

use Gemvc\Core\ApiService;
use Gemvc\Http\JsonResponse;
use Gemvc\Http\Request;

/**
 * Index API
 * 
 * Main entry point for GEMVC Apache application
 */
class Index extends ApiService
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
    }

    /**
     * Handle GET requests
     * 
     * @return JsonResponse
     */
    public function get(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Welcome to GEMVC Apache API',
            'version' => '1.0.0',
            'webserver' => 'Apache',
            'endpoints' => [
                '/user' => 'User management',
                '/api-docs' => 'API documentation'
            ]
        ]);
    }

    /**
     * Handle POST requests
     * 
     * @return JsonResponse
     */
    public function post(): JsonResponse
    {
        $data = $this->request->getBody();
        
        return new JsonResponse([
            'message' => 'POST request received',
            'data' => $data
        ]);
    }
}

