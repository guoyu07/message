<?php

namespace App\Middleware;

use Closure;
use Tree6bee\Framework\Foundation\Application;

/**
 * Class StartSession
 *
 */
class StartSession
{
    public function handle(Application $app, Closure $next)
    {
        return $next($this->startSession($app));
    }

    protected function startSession(Application $app)
    {
        session_name($app->config('session_name'));
        session_start();
        return $app;
    }
}
