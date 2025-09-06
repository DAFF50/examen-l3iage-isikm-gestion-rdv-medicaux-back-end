<?php
// app/Http/Middleware/CheckDoctorVerified.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckDoctorVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'doctor') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $doctor = $user->doctor;

        if (!$doctor || !$doctor->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Médecin non vérifié. Veuillez attendre la validation de votre profil.'
            ], 403);
        }

        return $next($request);
    }
}
