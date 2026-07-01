<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = QueryBuilder::for(User::class)
            ->with(['supervisor:id,name'])
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::callback('role', function ($query, $value) {
                    $query->role($value);
                }),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['id', 'name', 'created_at'])
            ->defaultSort('-id')
            ->paginate($request->input('per_page', 15));

        $users->getCollection()->transform(function ($user) {
            $user->roles_list = $user->getRoleNames();
            return $user;
        });

        return $this->paginated($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|max:50|unique:users,username',
            'password'      => 'required|string|min:6',
            'name'          => 'required|string|max:50',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'status'        => 'nullable|integer|in:0,1',
            'role'          => 'required|string|exists:roles,name',
            'supervisor_id' => 'nullable|integer|exists:users,id',
            'auto_approve'  => 'nullable|boolean',
            'auto_approve_forward' => 'nullable|boolean',
        ]);

        $role = $data['role'];
        unset($data['role']);

        $user = User::create($data);
        $user->assignRole($role);

        $user->roles_list = $user->getRoleNames();

        return $this->success($user, '用户创建成功');
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['supervisor:id,name']);
        $user->roles_list = $user->getRoleNames();
        $user->permissions_list = $user->getAllPermissions()->pluck('name');

        return $this->success($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:50',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'status'        => 'nullable|integer|in:0,1',
            'role'          => 'nullable|string|exists:roles,name',
            'supervisor_id' => 'nullable|integer|exists:users,id',
            'auto_approve'  => 'nullable|boolean',
            'auto_approve_forward' => 'nullable|boolean',
        ]);

        $role = $data['role'] ?? null;
        unset($data['role']);

        $user->update($data);

        if ($role) {
            $user->syncRoles([$role]);
        }

        $user->roles_list = $user->getRoleNames();

        return $this->success($user, '用户更新成功');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->error('不能删除自己的账户', 422);
        }

        $user->delete();

        return $this->success(null, '用户已删除');
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => 'nullable|string|min:6',
        ]);

        $newPassword = $data['password'] ?? Str::random(12);

        $user->update(['password' => Hash::make($newPassword)]);

        return $this->success([
            'password' => $newPassword,
        ], '密码重置成功');
    }

    /**
     * PUT /users/{user}/auto-approve
     */
    public function setAutoApprove(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'auto_approve' => 'nullable|boolean',
            'auto_approve_forward' => 'nullable|boolean',
        ]);

        $user->update($data);

        return $this->success($user, '已更新');
    }

    /**
     * POST /users/{user}/generate-invite-code
     */
    public function generateInviteCode(User $user): JsonResponse
    {
        if (!$user->invite_code) {
            $user->invite_code = strtoupper(Str::random(6));
            $user->save();
        }

        $portalUrl = rtrim(config('proxy.platform.customer_portal_url', 'https://user.sunipip.com'), '/');

        return $this->success([
            'invite_code' => $user->invite_code,
            'invite_link' => "{$portalUrl}/register?invite={$user->invite_code}",
        ]);
    }

    /**
     * POST /users/{user}/regenerate-invite-code
     */
    public function regenerateInviteCode(User $user): JsonResponse
    {
        $user->invite_code = strtoupper(Str::random(6));
        $user->save();

        $portalUrl = rtrim(config('proxy.platform.customer_portal_url', 'https://user.sunipip.com'), '/');

        return $this->success([
            'invite_code' => $user->invite_code,
            'invite_link' => "{$portalUrl}/register?invite={$user->invite_code}",
        ]);
    }
}
