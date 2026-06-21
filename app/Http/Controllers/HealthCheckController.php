<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class HealthCheckController extends Controller
{
    #[OA\Get(
        path: '/health',
        summary: 'Health check da API',
        description: 'Retorna o status de saúde da API verificando database, cache e storage.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'API saudável',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
                        new OA\Property(
                            property: 'services',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'database', type: 'string', example: 'ok'),
                                new OA\Property(property: 'cache', type: 'string', example: 'ok'),
                                new OA\Property(property: 'storage', type: 'string', example: 'ok'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 503,
                description: 'Serviço indisponível',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'degraded'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                    ]
                )
            )
        ]
    )]
    public function check()
    {
        $services = [];

        // Database check
        try {
            DB::connection()->getPdo();
            $services['database'] = 'ok';
        } catch (\Throwable $e) {
            $services['database'] = 'error';
        }

        // Cache check
        try {
            $testKey = 'health_check_test_' . time();
            Cache::put($testKey, 'ok', 10);
            $result = Cache::get($testKey);
            Cache::forget($testKey);
            $services['cache'] = ($result === 'ok') ? 'ok' : 'error';
        } catch (\Throwable $e) {
            $services['cache'] = 'error';
        }

        // Storage check
        try {
            $storagePath = base_path('public/db/json');
            if (!is_dir($storagePath)) {
                @mkdir($storagePath, 0755, true);
            }
            $services['storage'] = is_writable($storagePath) ? 'ok' : 'error';
        } catch (\Throwable $e) {
            $services['storage'] = 'error';
        }

        $allOk = !in_array('error', array_values($services), true);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => Carbon::now()->toIso8601String(),
            'version' => env('APP_VERSION', '1.0.0'),
            'services' => $services,
        ], $allOk ? 200 : 503);
    }
}
