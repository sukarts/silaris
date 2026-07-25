<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\PermissionModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\RoleModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class RoleController
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** Rôles système + rôles personnalisés du tenant. */
    public function index(): JsonResponse
    {
        return response()->json(
            RoleModel::with('permissions:key,module')
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id()))
                ->orderBy('is_system', 'desc')->orderBy('name')
                ->get(),
        );
    }

    /** Catalogue des permissions (pour l'éditeur de rôles). */
    public function permissions(): JsonResponse
    {
        return response()->json(
            PermissionModel::orderBy('module')->orderBy('key')->get()->groupBy('module'),
        );
    }

    /** POST /v1/admin/roles — rôle personnalisé tenant. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_keys' => ['required', 'array', 'min:1'],
            'permission_keys.*' => ['string', 'exists:permissions,key'],
        ]);

        $role = DB::transaction(function () use ($data) {
            $role = RoleModel::create([
                'tenant_id' => $this->tenant->id(),
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync($data['permission_keys']);

            return $role;
        });

        return response()->json($role->fresh('permissions:key,module'), 201);
    }

    /** PATCH — rôles personnalisés uniquement (jamais les rôles système). */
    public function update(Request $request, string $roleId): JsonResponse
    {
        $role = RoleModel::where('tenant_id', $this->tenant->id())->where('is_system', false)->findOrFail($roleId);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_keys' => ['sometimes', 'array', 'min:1'],
            'permission_keys.*' => ['string', 'exists:permissions,key'],
        ]);

        DB::transaction(function () use ($role, $data): void {
            if (isset($data['permission_keys'])) {
                $role->permissions()->sync($data['permission_keys']);
            }
            $role->update(collect($data)->except('permission_keys')->all());
        });
        // Invalidation des caches de permissions des utilisateurs du rôle.
        DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id')
            ->each(fn ($id) => UserModel::forgetPermissionCache((string) $id));

        return response()->json($role->fresh('permissions:key,module'));
    }
}
