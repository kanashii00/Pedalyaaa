<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            // Dev Tunnels terminates TLS and forwards the request to the local
            // PHP server as http://localhost. Trust the complete standard
            // X-Forwarded set so Laravel reconstructs the original public
            // host, port and HTTPS scheme when generating asset() and url().
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->alias([
            'device.auth' => \App\Http\Middleware\DeviceAuth::class,
            'bicycle.auth' => \App\Http\Middleware\BicycleDeviceAuth::class,
            'role' => \App\Http\Middleware\RoleCheck::class,
            'rental.sync' => \App\Http\Middleware\SyncRentalLifecycle::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
