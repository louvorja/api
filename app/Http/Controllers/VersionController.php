<?php

namespace App\Http\Controllers;

class VersionController extends Controller
{
    /**
     * Returns API version information for client compatibility checks.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        return response()->json([
            'api_version' => config('version.version'),
            'min_client_version' => config('version.min_client_version'),
            'php_version' => PHP_VERSION,
            'lumen_version' => app()->version(),
        ]);
    }
}
