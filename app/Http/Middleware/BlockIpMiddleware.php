<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\BlockedIp;

class BlockIpMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if (BlockedIp::where('ip_address', $ip)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé : Votre adresse IP a été bloquée.'
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
