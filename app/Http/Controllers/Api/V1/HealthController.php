<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/v1/health
     *
     * Confirms the Laravel application is running and, separately, whether
     * the configured database connection is reachable. Deliberately does
     * NOT query any domain table - none exist until STEP 03 - it only
     * opens a raw PDO connection.
     *
     * Note: the framework's own built-in `/up` route (registered via
     * `health: '/up'` in bootstrap/app.php) also exists and is untouched -
     * it just confirms the app booted, with no DB check. This endpoint is
     * the richer, project-specific one the frontend/monitoring should use.
     */
    public function __invoke(): JsonResponse
    {
        $databaseConnected = true;
        $databaseMessage = 'connected';

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $databaseConnected = false;
            $databaseMessage = config('app.debug') ? $e->getMessage() : 'unreachable';
        }

        return ApiResponse::success([
            'app' => config('app.name'),
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'database' => [
                'connected' => $databaseConnected,
                'message' => $databaseMessage,
            ],
        ], 'Service is running.');
    }
}
