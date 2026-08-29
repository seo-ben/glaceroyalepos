<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \App\Http\Middleware\BlockIpMiddleware::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ignorer le rapport des erreurs 404 courantes pour éviter de saturer les logs sous attaque de scans
        $exceptions->dontReport([
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        ]);

        // Cacher les détails des erreurs SQL / Base de données en production pour éviter la divulgation d'informations
        $exceptions->render(function (\Throwable $e, $request) {
            if (!config('app.debug') && $request->is('api/*')) {
                if ($e instanceof \Illuminate\Database\QueryException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Une erreur de base de données interne est survenue.'
                    ], 500);
                }
            }
        });

        // Intercepter proprement les erreurs de jeton CSRF invalides ou expirés (évite les erreurs 500)
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Session expirée ou jeton CSRF invalide.'
                ], 419);
            }
            return response('Accès non autorisé (CSRF Mismatch).', 419);
        });
    })->create();
