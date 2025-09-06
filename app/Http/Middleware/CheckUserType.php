<?php
// app/Http/Middleware/CheckUserType.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckUserType
{
    public function handle(Request $request, Closure $next, $userType)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié'
            ], 401);
        }

        if ($request->user()->user_type !== $userType) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Type d\'utilisateur requis : ' . $userType
            ], 403);
        }

        return $next($request);
    }
}
