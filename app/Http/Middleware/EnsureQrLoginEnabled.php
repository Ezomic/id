<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\QrLoginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQrLoginEnabled
{
    public function __construct(private readonly QrLoginPolicy $policy) {}

    /**
     * With the global switch off the endpoints do not exist as far as a
     * caller can tell. The actions check it again, so a route added without
     * this middleware is still refused.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->policy->enabled(), 404);

        return $next($request);
    }
}
