<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ($user->status !== 'active' || ($request->hasSession() && $request->session()->get('account_version') !== $user->session_version))) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'Sua sessão expirou. Entre novamente.'], 401);
        }

        return $next($request);
    }
}
