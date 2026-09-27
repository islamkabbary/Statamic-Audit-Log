<?php

namespace IslamKabbary\AuditLog\Listeners;

use IslamKabbary\AuditLog\Audit\AuditDiffer;
use IslamKabbary\AuditLog\Audit\AuditLogger;
use IslamKabbary\AuditLog\Audit\AuditSnapshots;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\File;
use Statamic\Events;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

/**
 * Detailed control-panel audit trail. Covers the same events as the "created/edited" one-liners
 * of webographen/statamic-admin-log, and records WHAT changed:
 *
 *   Entry, Term, User, Asset  before = getOriginal() (Statamic HasDirtyState), or — for entries,
 *                             terms and users whose original was reset (see
 *                             rememberPersistedState) — the persisted file, read at *Saving
 *   Global values             before = the YAML on disk, read before it is written (GlobalSetSaving
 *                             when values live in the set file — single-site on Statamic 4/5;
 *                             else GlobalVariablesSaving)
 *   Role, User group          before = snapshot taken at RouteMatched for the CP roles/user-groups
 *                             routes (no *Saving event exists and the repository hands the
 *                             controller the very instance it mutates)
 *   Config items (collection, blueprint, fieldset, form, taxonomy, nav, container, folder)
 *                             created / updated / deleted only — no field diff.
 *
 * Source of truth per save: *Created logs the creation and marks the object so the *Saved that
 * Statamic fires right after is skipped; *Saved logs an update only if the diff is non-empty.
 */
class AuditLogSubscriber
{
    public function __construct(
        private AuditLogger $logger,
        private AuditDiffer $differ,
        private AuditSnapshots $snapshots,
    ) {
    }

    public function subscribe($events): array
    {
        return array_merge($this->events(), array_filter([
            // Statamic 5+ only; on 4 a nav's creation is inferred from the CP route (configSaved).
            'Statamic\Events\NavCreated' => class_exists('Statamic\Events\NavCreated') ? 'configCreated' : null,
        ]));
    }

    private function events(): array
    {
        return [
            RouteMatched::class => 'captureBeforeState',

            Events\EntrySaving::class => 'rememberPersistedState',
            Events\TermSaving::class => 'rememberPersistedState',
            Events\UserSaving::class => 'rememberPersistedState',

            Events\EntryCreated::class => 'entryCreated',
            Events\EntrySaved::class => 'entrySaved',
            Events\EntryDeleted::class => 'entryDeleted',

            Events\TermCreated::class => 'termCreated',
            Events\TermSaved::class => 'termSaved',
            Events\TermDeleted::class => 'termDeleted',

            Events\UserCreated::class => 'userCreated',
            Events\UserSaved::class => 'userSaved',
            Events\UserDeleted::class => 'userDeleted',

            Events\RoleSaved::class => 'roleSaved',
            Events\RoleDeleted::class => 'roleDeleted',
            Events\UserGroupSaved::class => 'groupSaved',
            Events\UserGroupDeleted::class => 'groupDeleted',

            Events\GlobalSetSaving::class => 'globalSetSaving',
            Events\GlobalVariablesSaving::class => 'globalVariablesSaving',
            Events\GlobalVariablesSaved::class => 'globalVariablesSaved',

            Events\AssetCreated::class => 'assetCreated',
            Events\AssetSaved::class => 'assetSaved',
            Events\AssetDeleting::class => 'assetDeleting',
            Events\AssetDeleted::class => 'assetDeleted',
            Events\AssetReuploaded::class => 'assetReuploaded',
            Events\AssetReplaced::class => 'assetReplaced',

            Events\SubmissionDeleted::class => 'submissionDeleted',

            // Configuration items: created / updated / deleted.
            Events\CollectionCreated::class => 'configCreated',
            Events\CollectionSaved::class => 'configSaved',
            Events\CollectionDeleted::class => 'configDeleted',
            Events\BlueprintCreated::class => 'configCreated',
            Events\BlueprintSaved::class => 'configSaved',
            Events\BlueprintDeleted::class => 'configDeleted',
            Events\FieldsetCreated::class => 'configCreated',
            Events\FieldsetSaved::class => 'configSaved',
            Events\FieldsetDeleted::class => 'configDeleted',
            Events\FormCreated::class => 'configCreated',
            Events\FormSaved::class => 'configSaved',
            Events\FormDeleted::class => 'configDeleted',
            Events\TaxonomyCreated::class => 'configCreated',
            Events\TaxonomySaved::class => 'configSaved',
            Events\TaxonomyDeleted::class => 'configDeleted',
            Events\GlobalSetCreated::class => 'configCreated',
            Events\GlobalSetSaved::class => 'configSaved',
            Events\GlobalSetDeleted::class => 'configDeleted',
            Events\AssetContainerCreated::class => 'configCreated',
            Events\AssetContainerSaved::class => 'configSaved',
            Events\AssetContainerDeleted::class => 'configDeleted',
            Events\NavSaved::class => 'configSaved',
            Events\NavDeleted::class => 'configDeleted',
            Events\AssetFolderSaved::class => 'configSaved',
            Events\AssetFolderDeleted::class => 'configDeleted',
        ];
    }

    /* ------------------------------ before state ------------------------------ */

    public function captureBeforeState(RouteMatched $event): void
    {
        $this->guard(function () use ($event) {
            $name = (string) $event->route->getName();

            if (str_starts_with($name, 'statamic.cp.roles.')) {
                $this->snapshots->captureRoles();
            } elseif (str_starts_with($name, 'statamic.cp.user-groups.')) {
                $this->snapshots->captureGroups();
            }
        });
    }

    /**
     * Entry/Term/User::save() start with Facades\X::find($this->id()), and the Stache calls
     * syncOriginal() on what it returns. With a serializing cache (file — production) that is a
     * fresh copy and our object's original survives; with a non-serializing one (array) it is the
     * very object being saved, and its original is overwritten with the new values before any
     * event fires. When the original looks overwritten (equals the current state), read the
     * persisted file instead, which is still untouched at *Saving.
     */
    public function rememberPersistedState($event): void
    {
        $this->guard(function () use ($event) {
            $item = $event->entry ?? $event->term ?? $event->user ?? null;

            if (! $item || ! method_exists($item, 'getOriginal')) {
                return;
            }

            $original = $item->getOriginal();

            if ($original && json_encode($original) !== json_encode($item->getCurrentDirtyStateAttributes())) {
                return; // Statamic's own original is intact.
            }

            if ($state = $this->persistedState($item)) {
                $this->snapshots->putBefore($item, $state);
            }
        });
    }

    private function persistedState($item): ?array
    {
        $path = method_exists($item, 'initialPath') && $item->initialPath() ? $item->initialPath() : (method_exists($item, 'path') ? $item->path() : null);

        if (! $path || ! File::exists($path)) {
            return null; // new item, or a non-file (eloquent) repository
        }

        $store = match (true) {
            $item instanceof \Statamic\Contracts\Entries\Entry => Stache::store('entries')->store($item->collectionHandle()),
            $item instanceof \Statamic\Contracts\Taxonomies\Term => Stache::store('terms')->store($item->taxonomyHandle()),
            $item instanceof \Statamic\Contracts\Auth\User => Stache::store('users'),
            default => null,
        };

        return $store?->makeItemFromFile($path, File::get($path))->getCurrentDirtyStateAttributes();
    }

    /** The state before this save: the persisted snapshot if one was taken, else Statamic's original. */
    private function before($item): array
    {
        return $this->snapshots->pullBefore($item) ?? $item->getOriginal();
    }

    /* --------------------------------- entries --------------------------------- */

    public function entryCreated(Events\EntryCreated $event): void
    {
        $this->guard(function () use ($event) {
            $entry = $event->entry;
            $this->snapshots->markCreated($entry);

            $this->logger->log($this->entryRecord($entry, 'created', [
                'changes' => $this->differ->snapshot($this->entryValues($entry), $this->differ->fieldsFromBlueprint($entry->blueprint()), 'new'),
                'meta' => ['url' => $this->urlOf($entry), 'blueprint' => optional($entry->blueprint())->handle()],
            ]));
        });
    }

    public function entrySaved(Events\EntrySaved $event): void
    {
        $this->guard(function () use ($event) {
            $entry = $event->entry;

            if ($this->snapshots->pullCreated($entry)) {
                return;
            }

            $original = $this->before($entry);

            if (! $original) {
                // Loaded without Statamic syncing its original state: we know it was saved, not what changed.
                $this->logger->log($this->entryRecord($entry, 'updated', ['meta' => ['before_unavailable' => true]]));

                return;
            }

            $changes = $this->differ->diff(
                $this->entryValues($entry, $original),
                $this->entryValues($entry),
                $this->differ->fieldsFromBlueprint($entry->blueprint()) + $this->entryMetaFields()
            );

            if (! $changes) {
                return; // Save pressed without a meaningful change.
            }

            $action = isset($changes['status'])
                ? ($entry->published() ? 'published' : 'unpublished')
                : 'updated';

            $this->logger->log($this->entryRecord($entry, $action, ['changes' => $changes]));
        });
    }

    public function entryDeleted(Events\EntryDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $entry = $event->entry;

            $this->logger->log($this->entryRecord($entry, 'deleted', [
                'changes' => $this->differ->snapshot($this->entryValues($entry), $this->differ->fieldsFromBlueprint($entry->blueprint()), 'old'),
                'meta' => ['url' => $this->urlOf($entry), 'slug' => $entry->slug(), 'blueprint' => optional($entry->blueprint())->handle()],
            ]));
        });
    }

    /**
     * Comparable values of an entry: its data plus slug/date/status (from the dirty-state
     * attributes, or from $dirty = getOriginal() for the "before" side).
     */
    private function entryValues($entry, ?array $dirty = null): array
    {
        $dirty ??= $entry->getCurrentDirtyStateAttributes();

        $values = collect($dirty)->except(['collection', 'locale', 'origin', 'path', 'published'])->all();
        $values['status'] = array_key_exists('published', $dirty) ? ($dirty['published'] ? 'published' : 'draft') : null;
        // `blueprint` is in the data of an entry loaded from its file but not of one built in
        // memory; absent means "the entry's blueprint", so absence alone is never a change.
        $values['blueprint'] = $dirty['blueprint'] ?? optional($entry->blueprint())->handle();

        return $values;
    }

    /**
     * The entry URL, or null when it cannot be built. On Statamic 4 a deleted entry of a
     * structured collection is already gone from its tree, and url() throws ("routeData() on
     * null") — which, caught by guard(), would drop the whole "deleted" record.
     */
    private function urlOf($entry): ?string
    {
        try {
            return $entry->url();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function entryMetaFields(): array
    {
        return [
            'status' => ['label' => 'Status', 'type' => 'status'],
            'slug' => ['label' => 'Slug', 'type' => 'slug'],
            'date' => ['label' => 'Date', 'type' => 'date'],
        ];
    }

    private function entryRecord($entry, string $action, array $extra = []): array
    {
        $collection = $entry->collection();

        return array_replace([
            'action' => $action,
            'subject_type' => 'entry',
            'subject_id' => $entry->id(),
            'subject_title' => $entry->value('title') ?? $entry->slug(),
            'collection' => optional($collection)->handle(),
            'collection_title' => optional($collection)->title(),
            'site' => Site::hasMultiple() ? $entry->locale() : null,
        ], $extra);
    }

    /* ---------------------------------- terms ---------------------------------- */

    public function termCreated(Events\TermCreated $event): void
    {
        $this->guard(function () use ($event) {
            $term = $event->term;
            $this->snapshots->markCreated($term);

            $this->logger->log($this->termRecord($term, 'created', [
                'changes' => $this->differ->snapshot($term->getCurrentDirtyStateAttributes(), $this->differ->fieldsFromBlueprint($term->blueprint()), 'new'),
            ]));
        });
    }

    public function termSaved(Events\TermSaved $event): void
    {
        $this->guard(function () use ($event) {
            $term = $event->term;

            if ($this->snapshots->pullCreated($term)) {
                return;
            }

            if (! $original = $this->before($term)) {
                $this->logger->log($this->termRecord($term, 'updated', ['meta' => ['before_unavailable' => true]]));

                return;
            }

            $changes = $this->differ->diff(
                ['blueprint' => $original['blueprint'] ?? $term->blueprint()?->handle()] + collect($original)->except(['taxonomy'])->all(),
                ['blueprint' => $term->get('blueprint') ?? $term->blueprint()?->handle()] + collect($term->getCurrentDirtyStateAttributes())->except(['taxonomy'])->all(),
                $this->differ->fieldsFromBlueprint($term->blueprint())
            );

            if ($changes) {
                $this->logger->log($this->termRecord($term, 'updated', ['changes' => $changes]));
            }
        });
    }

    public function termDeleted(Events\TermDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $term = $event->term;

            $this->logger->log($this->termRecord($term, 'deleted', [
                'changes' => $this->differ->snapshot($term->getCurrentDirtyStateAttributes(), $this->differ->fieldsFromBlueprint($term->blueprint()), 'old'),
            ]));
        });
    }

    private function termRecord($term, string $action, array $extra = []): array
    {
        return array_replace([
            'action' => $action,
            'subject_type' => 'term',
            'subject_id' => $term->id(),
            'subject_title' => $term->title(),
            'collection' => $term->taxonomyHandle(),
            'collection_title' => optional($term->taxonomy())->title(),
        ], $extra);
    }

    /* ---------------------------------- users ---------------------------------- */

    public function userCreated(Events\UserCreated $event): void
    {
        $this->guard(function () use ($event) {
            $user = $event->user;
            $this->snapshots->markCreated($user);

            $this->logger->log($this->userRecord($user, 'created', [
                'changes' => $this->differ->snapshot($user->getCurrentDirtyStateAttributes(), $this->userFields($user), 'new'),
            ]));
        });
    }

    public function userSaved(Events\UserSaved $event): void
    {
        $this->guard(function () use ($event) {
            $user = $event->user;

            if ($this->snapshots->pullCreated($user)) {
                return;
            }

            if (! $original = $this->before($user)) {
                $this->logger->log($this->userRecord($user, 'updated', ['meta' => ['before_unavailable' => true]]));

                return;
            }

            $changes = $this->differ->diff($original, $user->getCurrentDirtyStateAttributes(), $this->userFields($user));

            if ($changes) {
                $this->logger->log($this->userRecord($user, 'updated', ['changes' => $changes]));
            }
        });
    }

    public function userDeleted(Events\UserDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $user = $event->user;

            $this->logger->log($this->userRecord($user, 'deleted', [
                'changes' => $this->differ->snapshot($user->getCurrentDirtyStateAttributes(), $this->userFields($user), 'old'),
            ]));
        });
    }

    private function userFields($user): array
    {
        return $this->differ->fieldsFromBlueprint($user->blueprint()) + [
            'roles' => ['label' => 'Roles', 'type' => 'user_roles'],
            'groups' => ['label' => 'Groups', 'type' => 'user_groups'],
            'super' => ['label' => 'Super admin', 'type' => 'toggle'],
            'permissions' => ['label' => 'Direct permissions', 'type' => 'permissions'],
            'password_hash' => ['label' => 'Password'],
        ];
    }

    private function userRecord($user, string $action, array $extra = []): array
    {
        return array_replace([
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $user->id(),
            'subject_title' => $user->name() ?: $user->email(),
        ], $extra, ['meta' => ['email' => $user->email()] + ($extra['meta'] ?? [])]);
    }

    /* ------------------------------ roles & groups ------------------------------ */

    public function roleSaved(Events\RoleSaved $event): void
    {
        $this->guard(function () use ($event) {
            $role = $event->role;
            $new = ['title' => $role->title(), 'handle' => $role->handle(), 'permissions' => $role->permissions()->values()->all()];

            if (! $this->snapshots->hasRoles()) {
                $this->logger->log($this->roleRecord($role, 'updated', ['meta' => ['before_unavailable' => true, 'permissions' => $new['permissions']]]));

                return;
            }

            $before = $this->snapshots->role($role->originalHandle()) ?? $this->snapshots->role($role->handle());
            $this->snapshots->rememberRole($role->handle(), $new);

            $fields = ['permissions' => ['label' => 'Permissions', 'type' => 'permissions'], 'title' => ['label' => 'Title'], 'handle' => ['label' => 'Handle']];

            if ($before === null) {
                $this->logger->log($this->roleRecord($role, 'created', [
                    'changes' => $this->differ->diff([], $new, $fields),
                ]));

                return;
            }

            $before['handle'] = $role->originalHandle() ?? $role->handle();
            $changes = $this->differ->diff($before, $new, $fields);

            if ($changes) {
                $this->logger->log($this->roleRecord($role, 'updated', ['changes' => $changes]));
            }
        });
    }

    public function roleDeleted(Events\RoleDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $role = $event->role;

            $this->logger->log($this->roleRecord($role, 'deleted', [
                'changes' => $this->differ->diff(
                    ['permissions' => $role->permissions()->values()->all()],
                    [],
                    ['permissions' => ['label' => 'Permissions', 'type' => 'permissions']]
                ),
            ]));
        });
    }

    private function roleRecord($role, string $action, array $extra = []): array
    {
        return array_replace([
            'action' => $action,
            'subject_type' => 'role',
            'subject_id' => $role->handle(),
            'subject_title' => $role->title(),
        ], $extra);
    }

    public function groupSaved(Events\UserGroupSaved $event): void
    {
        $this->guard(function () use ($event) {
            $group = $event->group;
            $new = ['title' => $group->title(), 'handle' => $group->handle(), 'roles' => $group->roles()->map->handle()->values()->all()];
            $fields = ['roles' => ['label' => 'Roles', 'type' => 'user_roles'], 'title' => ['label' => 'Title'], 'handle' => ['label' => 'Handle']];

            if (! $this->snapshots->hasGroups()) {
                $this->logger->log($this->groupRecord($group, 'updated', ['meta' => ['before_unavailable' => true, 'roles' => $new['roles']]]));

                return;
            }

            $originalHandle = method_exists($group, 'originalHandle') ? $group->originalHandle() : null;
            $before = $this->snapshots->group($originalHandle) ?? $this->snapshots->group($group->handle());
            $this->snapshots->rememberGroup($group->handle(), $new);

            if ($before === null) {
                $this->logger->log($this->groupRecord($group, 'created', ['changes' => $this->differ->diff([], $new, $fields)]));

                return;
            }

            $before['handle'] = $originalHandle ?? $group->handle();

            if ($changes = $this->differ->diff($before, $new, $fields)) {
                $this->logger->log($this->groupRecord($group, 'updated', ['changes' => $changes]));
            }
        });
    }

    public function groupDeleted(Events\UserGroupDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $group = $event->group;

            $this->logger->log($this->groupRecord($group, 'deleted', [
                'changes' => $this->differ->diff(
                    ['roles' => $group->roles()->map->handle()->values()->all()],
                    [],
                    ['roles' => ['label' => 'Roles', 'type' => 'user_roles']]
                ),
            ]));
        });
    }

    private function groupRecord($group, string $action, array $extra = []): array
    {
        return array_replace([
            'action' => $action,
            'subject_type' => 'user_group',
            'subject_id' => $group->handle(),
            'subject_title' => $group->title(),
        ], $extra);
    }

    /* --------------------------------- globals --------------------------------- */

    /**
     * Single-site on Statamic 4/5: a set's values live inside the set file
     * (content/globals/<handle>.yaml, under `data:`), and GlobalSet::save() writes that file BEFORE
     * its variables fire *Saving — so the "before" values must be read here, at GlobalSetSaving.
     *
     * Statamic 6 always keeps values in their own per-site file, so the set file has no `data:`
     * and the values are left to globalVariablesSaving (reading `[]` here would log every value
     * as newly added).
     */
    public function globalSetSaving(Events\GlobalSetSaving $event): void
    {
        $this->guard(function () use ($event) {
            $set = $event->globals;

            if (Site::hasMultiple() || ! File::exists($path = $set->path())) {
                return;
            }

            $yaml = (array) YAML::parse(File::get($path));

            if (! array_key_exists('data', $yaml) && $this->valuesLiveElsewhere($set, $path)) {
                return;
            }

            $this->snapshots->putBefore($this->globalKey($set->handle(), Site::default()->handle()), (array) ($yaml['data'] ?? []));
        });
    }

    private function valuesLiveElsewhere($set, string $setPath): bool
    {
        $variables = $set->in(Site::default()->handle());

        return $variables && str_replace('\\', '/', $variables->path()) !== str_replace('\\', '/', $setPath);
    }

    /** Multi-site: each localization has its own file, still unwritten at GlobalVariablesSaving. */
    public function globalVariablesSaving(Events\GlobalVariablesSaving $event): void
    {
        $this->guard(function () use ($event) {
            $variables = $event->variables;
            $key = $this->globalKey($variables->globalSet()->handle(), $variables->locale());

            if ($this->snapshots->hasBefore($key)) {
                return; // captured at GlobalSetSaving
            }

            $path = $variables->path();
            $before = File::exists($path)
                ? Stache::store('global-variables')->makeItemFromFile($path, File::get($path))->data()->all()
                : [];

            $this->snapshots->putBefore($key, $before);
        });
    }

    public function globalVariablesSaved(Events\GlobalVariablesSaved $event): void
    {
        $this->guard(function () use ($event) {
            $variables = $event->variables;
            $set = $variables->globalSet();
            $before = $this->snapshots->pullBefore($this->globalKey($set->handle(), $variables->locale()));

            if ($before === null) {
                return;
            }

            $changes = $this->differ->diff($before, $variables->data()->all(), $this->differ->fieldsFromBlueprint($variables->blueprint()));

            if (! $changes) {
                return;
            }

            // The GlobalSetSaved that follows is this values edit, not a change of the set's settings.
            $this->snapshots->flag('global_set:'.$set->handle());

            $this->logger->log([
                'action' => 'updated',
                'subject_type' => 'global',
                'subject_id' => $set->handle(),
                'subject_title' => $set->title(),
                'site' => Site::hasMultiple() ? $variables->locale() : null,
                'changes' => $changes,
            ]);
        });
    }

    private function globalKey(string $handle, ?string $locale): string
    {
        return 'global:'.$handle.':'.$locale;
    }

    /* ---------------------------------- assets ---------------------------------- */

    public function assetCreated(Events\AssetCreated $event): void
    {
        $this->guard(function () use ($event) {
            $asset = $event->asset;
            $this->snapshots->markCreated($asset);

            $this->logger->log($this->assetRecord($asset, 'uploaded', [
                'changes' => $this->differ->snapshot($asset->data()->all(), $this->differ->fieldsFromBlueprint($asset->blueprint()), 'new'),
            ]));
        });
    }

    public function assetSaved(Events\AssetSaved $event): void
    {
        $this->guard(function () use ($event) {
            $asset = $event->asset;

            if ($this->snapshots->pullCreated($asset) || ! ($original = $asset->getOriginal())) {
                return;
            }

            $current = $asset->getCurrentDirtyStateAttributes();
            $changes = $this->differ->diff(
                ['path' => $original['path'] ?? null] + (array) ($original['data'] ?? []),
                ['path' => $current['path'] ?? null] + (array) ($current['data'] ?? []),
                $this->differ->fieldsFromBlueprint($asset->blueprint()) + ['path' => ['label' => 'File path', 'type' => 'path']]
            );

            if ($changes) {
                $this->logger->log($this->assetRecord($asset, isset($changes['path']) ? 'moved' : 'updated', ['changes' => $changes]));
            }
        });
    }

    /**
     * Read the asset's values while its file still exists. After deletion nothing may touch
     * data()/meta()/size(): the container's file listing is still cached, so Statamic would think
     * the file exists and write a fresh, orphaned .meta/<file>.yaml for it.
     */
    public function assetDeleting(Events\AssetDeleting $event): void
    {
        $this->guard(fn () => $this->snapshots->putBefore($event->asset, [
            'data' => $event->asset->data()->all(),
            'size' => $event->asset->size(),
        ]));
    }

    public function assetDeleted(Events\AssetDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $asset = $event->asset;
            $before = $this->snapshots->pullBefore($asset) ?? [];

            $this->logger->log($this->assetRecord($asset, 'deleted', [
                'changes' => $this->differ->snapshot($before['data'] ?? [], $this->differ->fieldsFromBlueprint($asset->blueprint()), 'old'),
                'meta' => ['url' => $asset->url(), 'path' => $asset->path(), 'size' => $before['size'] ?? null],
            ]));
        });
    }

    public function assetReuploaded(Events\AssetReuploaded $event): void
    {
        $this->guard(fn () => $this->logger->log($this->assetRecord($event->asset, 'replaced', [
            'meta' => ['url' => $event->asset->url(), 'size' => $event->asset->size()],
        ])));
    }

    public function assetReplaced(Events\AssetReplaced $event): void
    {
        $this->guard(fn () => $this->logger->log($this->assetRecord($event->originalAsset, 'replaced', [
            'changes' => ['file' => ['label' => 'File', 'type' => 'assets', 'old' => $event->originalAsset->path(), 'new' => $event->newAsset->path()]],
        ])));
    }

    private function assetRecord($asset, string $action, array $extra = []): array
    {
        return array_replace([
            'action' => $action,
            'subject_type' => 'asset',
            'subject_id' => $asset->id(),
            'subject_title' => $asset->basename(),
            'collection' => $asset->containerHandle(),
            'collection_title' => optional($asset->container())->title(),
        ], $extra, ['meta' => ($extra['meta'] ?? []) + ['url' => $asset->url()]]);
    }

    /* --------------------------------- misc --------------------------------- */

    public function submissionDeleted(Events\SubmissionDeleted $event): void
    {
        $this->guard(function () use ($event) {
            $submission = $event->submission;
            $form = $submission->form();

            $this->logger->log([
                'action' => 'deleted',
                'subject_type' => 'submission',
                'subject_id' => $submission->id(),
                'subject_title' => 'Submission '.$submission->id(),
                'collection' => optional($form)->handle(),
                'collection_title' => optional($form)->title(),
                'changes' => $this->differ->snapshot($submission->data()->all(), $this->differ->fieldsFromBlueprint(optional($form)->blueprint()), 'old'),
            ]);
        });
    }

    public function configCreated($event): void
    {
        $this->guard(function () use ($event) {
            [$type, $item] = $this->configSubject($event);
            $this->snapshots->markCreated($item);
            $this->logger->log($this->configRecord($type, $item, 'created'));
        });
    }

    public function configSaved($event): void
    {
        $this->guard(function () use ($event) {
            [$type, $item] = $this->configSubject($event);

            if ($this->snapshots->pullCreated($item)) {
                return;
            }

            // A values edit saves the whole set; only a settings edit is a "global set updated".
            if ($type === 'global_set' && ($this->snapshots->pullFlag('global_set:'.$item->handle())
                || optional(request()->route())->getName() === 'statamic.cp.globals.variables.update')) {
                return;
            }

            // Asset folders (and navs on Statamic 4) have no *Created event: fall back to the CP route that saved it.
            $action = in_array($type, ['navigation', 'asset_folder'], true) && str_ends_with((string) optional(request()->route())->getName(), '.store')
                ? 'created'
                : 'updated';

            $this->logger->log($this->configRecord($type, $item, $action));
        });
    }

    public function configDeleted($event): void
    {
        $this->guard(function () use ($event) {
            [$type, $item] = $this->configSubject($event);
            $this->logger->log($this->configRecord($type, $item, 'deleted'));
        });
    }

    private function configSubject($event): array
    {
        return match (true) {
            isset($event->collection) => ['collection', $event->collection],
            isset($event->blueprint) => ['blueprint', $event->blueprint],
            isset($event->fieldset) => ['fieldset', $event->fieldset],
            isset($event->form) => ['form', $event->form],
            isset($event->taxonomy) => ['taxonomy', $event->taxonomy],
            isset($event->globals) => ['global_set', $event->globals],
            isset($event->container) => ['asset_container', $event->container],
            isset($event->nav) => ['navigation', $event->nav],
            isset($event->folder) => ['asset_folder', $event->folder],
        };
    }

    private function configRecord(string $type, $item, string $action): array
    {
        $record = ['action' => $action, 'subject_type' => $type, 'meta' => ['details' => 'not_tracked']];

        switch ($type) {
            case 'asset_folder':
                $record['subject_id'] = $item->path();
                $record['subject_title'] = $item->path();
                $record['collection'] = optional($item->container())->handle();
                $record['collection_title'] = optional($item->container())->title();
                break;
            case 'asset_container':
                $record['subject_id'] = $item->id();
                $record['subject_title'] = $item->title();
                break;
            case 'blueprint':
                $record['subject_id'] = $item->namespace().'.'.$item->handle();
                $record['subject_title'] = $item->title();
                $record['collection'] = $item->namespace();
                break;
            default:
                $record['subject_id'] = $item->handle();
                $record['subject_title'] = $item->title();
        }

        return $record;
    }

    /** Auditing must never break a save. */
    private function guard(callable $callback): void
    {
        if (! config('audit-log.enabled', true)) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                logger()->warning('Audit log listener failed: '.$e->getMessage(), ['at' => $e->getFile().':'.$e->getLine()]);
            } catch (\Throwable $ignored) {
            }
        }
    }
}
