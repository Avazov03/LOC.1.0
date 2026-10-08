<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness probe for the load balancer and Docker. /up only proves PHP boots; this also checks the database and cache.
 * Returns component names and ok/fail only, never connection details.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health:ping', 'pong', 10);
                if (Cache::get('health:ping') !== 'pong') {
                    throw new \RuntimeException('cache read-back failed');
                }
            }),
        ];
        $healthy = ! in_array('fail', $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $exception) {
            report($exception);

            return 'fail';
        }
    }
}
