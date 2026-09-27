<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Statamic CP audit log (who changed what, from what, to what, when)
    |--------------------------------------------------------------------------
    |
    | Stored as files — one JSON line per action in {path}/audit-YYYY-MM-DD.jsonl (no database) —
    | and summarised as one line per action in the `summary_channel` log. Viewable in the CP under
    | Tools › Audit Log (permission: "view audit log").
    |
    */

    'enabled' => env('AUDIT_LOG_ENABLED', env('ADMINLOG_ENABLED', true)),

    // A .gitignore is written into this directory when it is created.
    'path' => storage_path('audit'),

    // Days of audit files to keep. Pruned daily by `audit-log:prune` at `prune_at` when
    // `schedule_prune` is on (needs the Laravel scheduler cron). 0 keeps them forever.
    'retention_days' => 365,
    'schedule_prune' => true,
    'prune_at' => '03:30',

    // Log channel for the one-line summaries. Registered by the package as a daily file
    // (storage/logs/{channel}.log) unless the app already defines a channel with this name.
    'summary_channel' => 'audit-log',

    // `audit-log:import-legacy` reads storage/logs/{legacy_log_name}-*.log — the files written by
    // webographen/statamic-admin-log (its `log-name` config).
    'legacy_log_name' => 'adminlog',

    // Field handles that change on their own and are not an audit-worthy change.
    // (Statamic already excludes `updated_at` from the dirty state.)
    'ignored_fields' => [
        'updated_at',
        'updated_by',   // set to whoever pressed Save; the audit row already records the user
        'path',         // file path, derived from slug/locale
        'preferences',  // user CP preferences (listing columns, locale) — UI state, not data
        'last_login',
        'blueprint_hash',
    ],

    // Any key (at any depth) whose name matches one of these patterns is never stored raw:
    // a change is recorded as "********" → "********" so the fact that it changed is kept.
    'sensitive_patterns' => [
        '/passw(or)?d/i',           // password, password_confirmation, password_hash, passwd
        '/token/i',
        '/secret/i',
        '/api[_-]?key/i',
        '/private[_-]?key/i',
        '/credential/i',
        '/^auth_|authentication/i', // not ^auth — that would mask the entry `author` field
        '/(^|_)otp(_|$)/i',
        '/two[_-]?factor/i',
        '/recovery[_-]?code/i',
    ],

    // Size guards so one save of a huge Bard field cannot produce a huge record.
    'max_string_length' => 1000,
    'max_nested_changes' => 30,
    'max_initial_fields' => 25,
    'max_changes_bytes' => 60000,
];
