<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        if (!$origin) {
            return $next($request);
        }

        $allowed = config('cors.allowed_origins', []);
        if (empty($allowed) || !in_array($origin, $allowed)) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', config('cors.allowed_methods', [])));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', config('cors.allowed_headers', [])));
        $response->headers->set('Access-Control-Expose-Headers', implode(', ', config('cors.exposed_headers', [])));
        $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 86400));

        if (config('cors.supports_credentials')) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
