<?php

use App\Http\Middleware\EnforceTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'rol' => \App\Http\Middleware\EnsureRole::class,
            'activo' => \App\Http\Middleware\EnsureUserActive::class,
            'tenant' => EnforceTenant::class,
            'feature' => \App\Http\Middleware\EnsureFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // T11.6: la ligadura de modelos de la ruta corre antes que `tenant`, así que un id que no existe llegaba como 404 y uno de
        // otra empresa como 403: eso le dice a un usuario de un cliente qué ids existen en otra empresa. Al usuario confinado a su
        // empresa se le responde lo mismo en los dos casos. Solo cuando el id que no se encontró es el de la URL: un `findOrFail`
        // de un controlador y las rutas que no existen siguen siendo 404.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $previa = $e->getPrevious();
            if (! $request->is('api/*') || ! $previa instanceof ModelNotFoundException) {
                return null;
            }
            $usuario = $request->user();
            $enLaUrl = array_map('strval', $request->route()?->originalParameters() ?? []);
            if (! $usuario?->estaConfinadoAEmpresa() || ! array_intersect(array_map('strval', $previa->getIds()), $enLaUrl)) {
                return null;
            }

            return response()->json(['message' => EnforceTenant::MENSAJE], 403);
        });
    })->create();
