<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Auth;

use RuntimeException;

/**
 * Contexte utilisateur de la requête — peuplé par le middleware EnsureInternalUser.
 * Permet aux handlers Application d'appliquer permissions et scope agences
 * sans dépendre du framework d'auth.
 */
final class CurrentUser
{
    private ?string $id = null;

    /** @var list<string> */
    private array $permissions = [];

    /** @var list<string> */
    private array $branchIds = [];

    private bool $allBranches = false;

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $branchIds
     */
    public function set(string $id, array $permissions, array $branchIds, bool $allBranches): void
    {
        $this->id = $id;
        $this->permissions = $permissions;
        $this->branchIds = $branchIds;
        $this->allBranches = $allBranches;
    }

    public function id(): string
    {
        if ($this->id === null) {
            throw new RuntimeException('Aucun utilisateur dans le contexte.');
        }

        return $this->id;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return list<string>|null null = toutes les agences (direction/admin). */
    public function branchScope(): ?array
    {
        return $this->allBranches ? null : $this->branchIds;
    }

    public function isSet(): bool
    {
        return $this->id !== null;
    }
}
