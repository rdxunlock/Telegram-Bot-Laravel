<?php

namespace App\Http;

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\SubstituteBindings;

class Kernel extends HttpKernel
{
    protected $middleware = [
//        EncryptCookies::class,
//        StartSession::class,
        TrustProxies::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];
    protected $middlewarePriority = [
//        EncryptCookies::class,
//        StartSession::class,
        TrustProxies::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];
    protected $middlewareGroups = [
        'api' => [
            SubstituteBindings::class,
        ],
    ];
    protected $middlewareAliases = [
    ];
}
