# Statamic Audit Log

A detailed audit log for the Statamic control panel: **who** changed **what**, **from what**, **to what**, and **when**.

- Field-level diffs for entries, terms, users, globals, and assets (old → new, lists as added/removed, nested Bard/Replicator paths).
- Roles and user groups (permissions added/removed).
- Created / updated / deleted records for configuration items: collections, blueprints, fieldsets, forms, taxonomies, global sets, navigations, asset containers and folders.
- Sensitive values (passwords, tokens, API keys…) are never stored: a change shows as `********`.
- **File-based, no database:** one JSON line per action in `storage/audit/audit-YYYY-MM-DD.jsonl`, plus a one-line summary in a daily log file.
- A CP screen under **Tools › Audit Log**, with filters (user, action, type, collection, dates, search) and a detail view per record.
- Never breaks a save: any failure inside the audit is caught and logged as a warning.

Supports **Statamic 4, 5 and 6** on **Laravel 10–13**, PHP 8.1+.

## Installation

The package is installed from GitHub. Add the repository to your app's `composer.json`:

```json
"repositories": {
    "audit-log": {
        "type": "vcs",
        "url": "https://github.com/islamkabbary/Statamic-Audit-Log.git"
    }
}
```

Then:

```bash
composer require islamkabbary/statamic-audit-log
```

That's all: the event subscriber, CP routes, the Tools nav item, the permission, the commands and the prune schedule are registered by the package.

### Deploying

Run `php artisan optimize:clear` after `composer install`, then re-cache if you cache (`php artisan optimize`).

The package is built to survive a stale cache anyway:
- **Stale route cache:** the Tools › Audit Log item stays hidden until the routes are cached again, instead of breaking the CP nav.
- **Stale config cache:** the package fills in its own defaults, so sensitive values stay masked.

### Permission

Super admins always see the screen. For other roles, grant **View Audit Log** (`view audit log`) in the CP under Users › Roles, or in `resources/users/roles.yaml`.

### Scheduler

Old files are deleted daily by `audit-log:prune` (03:30 by default). This needs the usual Laravel scheduler cron on the server:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Configuration

Publish the config only if you need to change something:

```bash
php artisan vendor:publish --tag=audit-log-config
```

| Key | Default | |
|---|---|---|
| `enabled` | `env('AUDIT_LOG_ENABLED', true)` | Master switch. |
| `path` | `storage_path('audit')` | Where the JSONL files go. A `.gitignore` is written into it on first use. |
| `retention_days` | `365` | Days to keep. `0` keeps everything. |
| `schedule_prune` / `prune_at` | `true` / `'03:30'` | The daily prune. |
| `summary_channel` | `'audit-log'` | Log channel for the one-line summaries. Registered as a daily file unless your app already defines a channel with that name. |
| `ignored_fields` | `updated_at`, `updated_by`, … | Field handles that are never a change. |
| `sensitive_patterns` | password, token, secret, api key… | Regexes; matching keys at any depth are masked. |
| `max_*` | | Size guards for huge fields. |

## Coming from webographen/statamic-admin-log

1. Install this package, and set `'enabled' => false` in `config/admin-log.php` so nothing is logged twice.
2. Import the old lines (safe to run more than once, since already-imported lines are skipped):

   ```bash
   php artisan audit-log:import-legacy --dry-run
   php artisan audit-log:import-legacy
   ```

   Imported records are marked "legacy". The old log never stored what changed, so they have no field diff.
3. Remove the old addon: `composer remove webographen/statamic-admin-log`.

`legacy_log_name` (default `adminlog`) must match the old addon's `log-name`.

## Commands

| Command | |
|---|---|
| `audit-log:prune` | Delete files older than `retention_days`. |
| `audit-log:import-legacy {--dry-run}` | Import webographen/statamic-admin-log lines. |

## Development

```bash
composer install
vendor/bin/phpunit
```

The test suite uses Statamic's `AddonTestCase` (Statamic 5+).

Release: commit, then tag (`git tag v1.x.y && git push --tags`). Apps pick it up with `composer update islamkabbary/statamic-audit-log`.

## License

MIT
