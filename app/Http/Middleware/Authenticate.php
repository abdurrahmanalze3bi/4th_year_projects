<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Always return null for API routes
        if ($request->is('api/*')) {
            return null;
        }

        // Only return route for web routes (if you have any)
        return route('login');
    }

    /**
     * Handle unauthenticated users
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->is('api/*')) {
            abort(response()->json([
                'message' => 'Unauthenticated',
                'status' => 401
            ], 401));
        }

        parent::unauthenticated($request, $guards);
    }
}
