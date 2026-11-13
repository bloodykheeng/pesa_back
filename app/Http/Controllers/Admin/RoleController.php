<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and what each may do. Admin keeps every permission; Customer is the app's role.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        // Counted from the pivot: Role::users() resolves the model through the current guard, and
        // under Sanctum that guard has none.
        $members = DB::table('model_has_roles')->selectRaw('role_id, COUNT(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        $roles = Role::with('permissions:id,name')->orderBy('id')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users_count' => (int) ($members[$r->id] ?? 0),
                'permissions' => $r->permissions->pluck('name')->values(),
                'locked' => $r->name === 'Admin',
            ]);

        return response()->json([
            'data' => $roles,
            'permissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        abort_if($role->name === 'Admin', 422, 'Admin always has every permission.');

        $names = $request->validate(['permissions' => 'present|array', 'permissions.*' => 'string|exists:permissions,name'])['permissions'];
        $role->syncPermissions($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => $role->name . ' saved.']);
    }
}
