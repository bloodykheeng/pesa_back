<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a customer act only on their own user record; staff (Admin, Editor) on anyone.
 *
 * Usage: ->middleware('self.or.staff:id') where "id" is the route parameter holding the user id.
 * Used on the app's users/{user}, profile-update/{id} and change-password/{id}, which used to
 * accept any id from any signed-in user.
 */
class EnsureSelfOrStaff
{
    public function handle(Request $request, Closure $next, string $parameter = 'id'): Response
    {
        $user = $request->user();
        $target = $request->route($parameter);
        $targetId = $target instanceof User ? $target->getKey() : $target;

        if ($user && ((string) $user->getKey() === (string) $targetId || $user->hasAnyRole(User::STAFF_ROLES))) {
            return $next($request);
        }

        return response()->json(['message' => 'You can only view or change your own account.'], 403);
    }
}
