<?php

namespace App\Routing;

use Tree6bee\Framework\Routing\Router as BaseRouter;

class Router extends BaseRouter
{
    /**
     * 获取路由定义
     */
    protected function getRouteDefinition()
    {
        if (PHP_SAPI != 'cli') {
            require __DIR__ . '/../routes/web.php';
        }

        parent::getRouteDefinition();
    }

    /**
     * 路由默认 module controller action
     */
    protected $defRouteVar = [
        'module'        => 'im',
        'controller'    => 'index',
        'action'        => 'index',
    ];
}
