<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin()) {
            return $next($request);
        }

        if (! $user?->organization?->isApproved()) {
            return response()->json([
                'message' => 'Sua organização precisa ser aprovada antes de publicar animais.',
            ], 403);
        }

        return $next($request);
    }
}
