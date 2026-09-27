<?php

namespace IslamKabbary\AuditLog\Audit;

use Statamic\Facades\Role;
use Statamic\Facades\UserGroup;

/**
 * Request-scoped "before" state for things Statamic does not keep a dirty state for, plus the
 * bookkeeping that keeps one user action from producing several audit rows.
 *
 * Registered as a singleton; everything here is small and consumed (forgotten) once used, so it
 * does not grow in long-running CLI imports.
 */
class AuditSnapshots
{
    /** handle => ['title' => , 'permissions' => []] taken before a CP role request runs. */
    private ?array $roles = null;

    /** handle => ['title' => , 'roles' => []] taken before a CP user-group request runs. */
    private ?array $groups = null;

    /** spl_object_id => data array, read from disk at GlobalVariablesSaving. */
    private array $before = [];

    /** spl_object_id => true for objects whose *Created event was already logged. */
    private array $created = [];

    /** Hashes of rows already written in this process (duplicate guard). */
    private array $written = [];

    /**
     * Roles and groups are only cached in memory by their repositories and the CP controller
     * mutates that same instance, and Statamic 4 has no RoleSaving event — so the previous state
     * must be copied (as plain arrays) before the controller runs.
     */
    public function captureRoles(): void
    {
        $this->roles = Role::all()->map(fn ($role) => [
            'title' => $role->title(),
            'permissions' => $role->permissions()->values()->all(),
        ])->all();
    }

    public function captureGroups(): void
    {
        $this->groups = UserGroup::all()->map(fn ($group) => [
            'title' => $group->title(),
            'roles' => $group->roles()->map->handle()->values()->all(),
        ])->all();
    }

    public function hasRoles(): bool
    {
        return $this->roles !== null;
    }

    public function role(?string $handle): ?array
    {
        return $handle !== null ? ($this->roles[$handle] ?? null) : null;
    }

    public function hasGroups(): bool
    {
        return $this->groups !== null;
    }

    public function group(?string $handle): ?array
    {
        return $handle !== null ? ($this->groups[$handle] ?? null) : null;
    }

    /** After a role/group is saved its new state becomes the baseline for a second save. */
    public function rememberRole(string $handle, array $state): void
    {
        if ($this->roles !== null) {
            $this->roles[$handle] = $state;
        }
    }

    public function rememberGroup(string $handle, array $state): void
    {
        if ($this->groups !== null) {
            $this->groups[$handle] = $state;
        }
    }

    /** @param  object|string  $item  the item itself, or a stable string key */
    public function putBefore(object|string $item, array $data): void
    {
        $this->before[is_object($item) ? spl_object_id($item) : $item] = $data;
    }

    public function hasBefore(object|string $item): bool
    {
        return isset($this->before[is_object($item) ? spl_object_id($item) : $item]);
    }

    public function pullBefore(object|string $item): ?array
    {
        $id = is_object($item) ? spl_object_id($item) : $item;
        $data = $this->before[$id] ?? null;
        unset($this->before[$id]);

        return $data;
    }

    public function markCreated(object $item): void
    {
        $this->created[spl_object_id($item)] = true;
    }

    /** True once for an object whose creation was already logged (its *Saved is then skipped). */
    public function pullCreated(object $item): bool
    {
        $id = spl_object_id($item);
        $was = isset($this->created[$id]);
        unset($this->created[$id]);

        return $was;
    }

    /** Named one-shot flags, e.g. "these global values were just saved through their set". */
    private array $flags = [];

    public function flag(string $key): void
    {
        $this->flags[$key] = true;
    }

    public function pullFlag(string $key): bool
    {
        $was = isset($this->flags[$key]);
        unset($this->flags[$key]);

        return $was;
    }

    /** Returns false if an identical row was already written in this process. */
    public function firstTime(string $hash): bool
    {
        if (isset($this->written[$hash])) {
            return false;
        }

        if (count($this->written) > 500) {
            $this->written = [];
        }

        return $this->written[$hash] = true;
    }
}
