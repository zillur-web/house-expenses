<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        abort_unless($u->hasRole('admin') || ($u->member && $u->member->active), 403, 'Your account is inactive.');

        return $next($request);
    }
}
