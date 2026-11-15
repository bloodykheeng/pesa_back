<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Reverb channels for the admin's live signals (docs/realtime.md): POST /api/admin/broadcasting/auth with the
    // admin's Sanctum token, never a session cookie.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/admin', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'self.or.staff' => \App\Http\Middleware\EnsureSelfOrStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A missing record (e.g. a link from an old notification to a deleted order) says so in
        // plain words instead of "No query results for model [App\Models\User] 312".
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() && $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['message' => 'This record no longer exists. It may have been deleted.'], 404);
            }
        });

        // A delete the database refuses because other records still point at the row (RESTRICT foreign keys,
        // docs/foreign-key-conventions.md) reads as 409 in plain words, never a 500 with raw SQL. Errno 1451 is only
        // "a parent row is still referenced"; a child insert failing its own key is 1452 and keeps default handling.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if (($e->errorInfo[1] ?? null) === 1451 && ($request->expectsJson() || $request->is('api/*'))) {
                return response()->json([
                    'message' => 'This is still used by other records (orders, payments, products...), so it was not deleted. Make it inactive instead, or remove those records first.',
                ], 409);
            }

            return null;
        });
    })->create();
