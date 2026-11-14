<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\User;
use App\Services\AccountEmails;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Everyone with an account: customers (who sign up in the app) and staff. Admins manage them.
 */
class UserController extends Controller
{
    use StoresPhotos;

    /** Set while an admin creates an account, so the "new sign-up" bell is not rung for it. */
    public static bool $creatingFromAdmin = false;

    /**
     * GET /api/admin/customers: customers to pick from when staff enter an order or a package (Editors too, so
     * only what the picker shows: name, phone, email). The full Customers list stays with Admins.
     */
    public function customers(Request $request)
    {
        $query = QueryBuilder::for(User::class)
            ->role('Customer')
            ->select(['id', 'name', 'phone', 'email'])
            ->allowedFilters([AdminList::search(['name', 'email', 'phone'])])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function index(Request $request)
    {
        $query = QueryBuilder::for(User::class)
            ->with('roles:id,name')
            ->withCount(['orders', 'packages'])
            ->allowedFilters([
                AdminList::search(['name', 'email', 'phone', 'nin']),
                AllowedFilter::callback('role', fn (Builder $q, $value) => $q->role($value)),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('gender'),
                AllowedFilter::callback('has_device', fn (Builder $q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('device_token')->where('device_token', '!=', '')
                    : $q->where(fn ($w) => $w->whereNull('device_token')->orWhere('device_token', ''))),
                AllowedFilter::callback('ids', fn (Builder $q, $v) => $q->whereIn('id', is_array($v) ? $v : explode(',', (string) $v))),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'name', 'email', 'phone', 'status', 'lastlogin', 'created_at', 'orders_count'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    /**
     * One account. Editors may open a customer's (an order's customer, opened over the order), never a staff
     * account: Customers & staff stays the Admins' page.
     */
    public function show(Request $request, int $id)
    {
        $user = User::with(['roles:id,name', 'orders' => fn ($q) => $q->latest()->limit(5), 'packages' => fn ($q) => $q->latest()->limit(5)])
            ->withCount(['orders', 'packages'])
            ->findOrFail($id);
        abort_unless($request->user()->hasRole('Admin') || $user->hasRole('Customer'), 403, 'Only Admins can open staff accounts.');

        $data = (new AdminResource($user))->resolve();
        $data['totals'] = [
            'orders_value' => (float) $user->orders()->where('status', '!=', 'cancelled')->sum('charged_amount'),
            'paid' => (float) $user->orders()->where('status', '!=', 'cancelled')->sum('amount_paid'),
            'balance_due' => (float) $user->orders()->where('status', '!=', 'cancelled')->where('balance_due', '>', 0)->sum('balance_due'),
        ];

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $user = DB::transaction(function () use ($data, $request) {
            self::$creatingFromAdmin = true;
            $user = new User;
            $user->forceFill($this->fields($data) + ['password' => Hash::make($data['password']), 'agree' => true]);
            $this->applyPhoto($request, $user, 'user_photos');
            $user->save();
            $user->syncRoles([$data['role']]);
            self::$creatingFromAdmin = false;

            return $user;
        });

        app(AccountEmails::class)->created($user, $request->user());

        return response()->json(['message' => $user->name.' added. We emailed them a link to choose their password.', 'data' => new AdminResource($user->load('roles'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate($this->rules($user));

        if ($user->is($request->user()) && ($data['role'] ?? null) && ! in_array($data['role'], User::STAFF_ROLES, true)) {
            return response()->json(['message' => 'You cannot take away your own staff role.'], 422);
        }

        $before = ['role' => $user->getRoleNames()->first(), 'status' => $user->status];

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill($this->fields($data));
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $this->applyPhoto($request, $user, 'user_photos');
            $user->save();
            $user->syncRoles([$data['role']]);
        });

        // Tell them: a new password, a new role, an account switched off or on.
        $emails = app(AccountEmails::class);
        if (! empty($data['password'])) {
            $emails->passwordChanged($user, 'by an administrator', $request->user());
        }
        $changes = array_filter([
            'role' => $before['role'] !== $data['role'] ? [$before['role'], $data['role']] : null,
            'status' => $before['status'] !== $data['status'] ? [$before['status'], $data['status']] : null,
        ]);
        $emails->accessChanged($user, $changes, $request->user());
        if (isset($changes['role'])) {
            \App\Support\Audit\Audit::change($user, 'role', 'Role', ...$changes['role']);
        }
        if (($changes['status'][1] ?? null) === 'inactive') {
            $user->tokens()->delete(); // an inactive account is signed out everywhere
        }

        return response()->json(['message' => $user->name.' saved.', 'data' => new AdminResource($user->load('roles'))]);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];

        if (in_array($request->user()->id, $ids)) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $withHistory = User::whereIn('id', $ids)->where(fn ($q) => $q->has('orders')->orHas('packages'))->pluck('name');
        if ($withHistory->isNotEmpty()) {
            return response()->json(['message' => 'These have orders or packages and are kept: '.$withHistory->implode(', ').'. Make them inactive instead.'], 422);
        }

        $users = User::whereIn('id', $ids)->get();
        DB::transaction(fn () => $users->each(function (User $u) {
            $u->tokens()->delete();
            $u->syncRoles([]);
            $u->delete();
        }));
        $users->each(fn (User $u) => $this->deletePhoto($u->photo_url));

        return response()->json(['message' => $users->count().' account'.($users->count() === 1 ? '' : 's').' deleted.']);
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user?->id)],
            'status' => 'required|in:active,inactive',
            'gender' => 'nullable|in:male,female',
            'nin' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date|before:today',
            'role' => ['required', Rule::in(Role::pluck('name'))],
            'password' => ($user ? 'nullable' : 'required').'|string|min:8|confirmed',
            'photo' => self::PHOTO_RULE,
            'remove_photo' => 'nullable|boolean',
        ];
    }

    private function fields(array $data): array
    {
        return collect($data)->only(['name', 'email', 'phone', 'status', 'gender', 'nin', 'date_of_birth'])->all();
    }
}
