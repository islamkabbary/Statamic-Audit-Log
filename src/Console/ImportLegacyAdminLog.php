<?php

namespace IslamKabbary\AuditLog\Console;

use IslamKabbary\AuditLog\Audit\AuditLogStore;
use IslamKabbary\AuditLog\Audit\AuditRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copies the old webographen/statamic-admin-log "created/edited ..." lines from
 * storage/logs/{legacy_log_name}-*.log into
 * the audit files (storage/audit) so the CP Audit Log shows history from before the detailed audit existed.
 *
 * Imported rows have changes = null and meta.legacy = true (the old log never knew what changed,
 * nor create vs edit, so their action is "saved"). Idempotent: each line is hashed and skipped
 * if already imported. Lines written by the new audit (prefixed "[audit]") are never imported.
 */
class ImportLegacyAdminLog extends Command
{
    protected $signature = 'audit-log:import-legacy {--dry-run : Show what would be imported}';

    protected $description = 'Import the old adminlog-*.log lines into the audit log (changes = null)';

    private const TYPES = [
        'asset container' => 'asset_container', 'asset folder' => 'asset_folder', 'user group' => 'user_group',
        'global set' => 'global_set', 'navigation' => 'navigation', 'blueprint' => 'blueprint',
        'collection' => 'collection', 'fieldset' => 'fieldset', 'submission' => 'submission',
        'taxonomy' => 'taxonomy', 'entry' => 'entry', 'asset' => 'asset', 'form' => 'form',
        'role' => 'role', 'term' => 'term', 'user' => 'user',
    ];

    public function handle(AuditLogStore $store): int
    {
        $known = $store->records()->map(fn (AuditRecord $r) => $r->meta['legacy_hash'] ?? null)->filter()->flip();

        $name = config('audit-log.legacy_log_name', 'adminlog');
        $files = collect(File::glob(storage_path("logs/{$name}-*.log")))->sort()->values();
        $imported = 0;

        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = $this->parse($line);

                if (! $row || $known->has($row['meta']['legacy_hash'])) {
                    continue;
                }

                $known->put($row['meta']['legacy_hash'], true);
                $imported++;

                if ($this->option('dry-run')) {
                    $this->line($row['created_at'].'  '.$row['action'].' '.$row['subject_type'].'  '.$row['subject_title']);
                } else {
                    // Deterministic id (time + line hash) so each line lands in its own day file, in order.
                    $record = AuditRecord::fromArray($row);
                    $record->id = $record->created_at->format('YmdHis').'000000-'.substr($row['meta']['legacy_hash'], 0, 4);
                    $store->write($record);
                }
            }
        }

        $this->info(($this->option('dry-run') ? 'Would import ' : 'Imported ').$imported.' legacy line(s).');

        return self::SUCCESS;
    }

    /** "[2026-08-27 14:33:05] local.INFO: name ('id') created/edited entry 'T' (id: 'x') in collection 'C'" */
    private function parse(string $line): ?array
    {
        if (! preg_match("/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.\w+: (?!\[audit\] )(?:unknown user \(cli\)|(.+?) \('([^']*)'\)) (created\/edited|deleted) (.+)$/", trim($line), $m)) {
            return null;
        }

        [, $date, $userName, $userId, $verb, $rest] = $m;

        $type = null;
        foreach (self::TYPES as $words => $handle) {
            if (str_starts_with($rest, $words.' ')) {
                $type = $handle;
                break;
            }
        }

        if (! $type) {
            return null;
        }

        preg_match("/^[a-z ]+? '(.*?)'(?: \(|$| in| for| with)/", $rest, $title);
        preg_match("/(?:\(|, )(?:id|handle): '([^']*)'/", $rest, $id);
        preg_match("/ in (?:collection|taxonomy) '([^']*)'\s*$/", $rest, $parent);

        return [
            'user_id' => $userId ?: null,
            'user_name' => $userName ?: null,
            'action' => $verb === 'deleted' ? 'deleted' : 'saved',
            'subject_type' => $type,
            'subject_id' => $id[1] ?? null,
            'subject_title' => $title[1] ?? null,
            'collection_title' => $parent[1] ?? null,
            'changes' => null,
            'meta' => ['legacy' => true, 'legacy_hash' => md5($line), 'message' => preg_replace('/^\[[^\]]+\] \w+\.\w+: /', '', trim($line))],
            'source' => $userId ? 'cp' : 'cli',
            'created_at' => Carbon::parse($date),
        ];
    }
}
