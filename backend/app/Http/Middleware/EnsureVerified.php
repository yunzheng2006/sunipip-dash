<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\VerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !($user instanceof Customer)) {
            return $next($request);
        }

        $svc = app(VerificationService::class);

        if ($svc->isRequired() && !$svc->isVerified($user)) {
            return response()->json([
                'message' => '请先完成实名认证',
                'error_code' => 'VERIFICATION_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
