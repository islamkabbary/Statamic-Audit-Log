<?php

namespace IslamKabbary\AuditLog\Audit;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;
use Statamic\Statamic;

/**
 * Writes one audit record: a line in storage/audit/audit-YYYY-MM-DD.jsonl (AuditLogStore) plus a
 * one-line summary in the `summary_channel` log channel (a daily file under storage/logs, which
 * the package registers itself unless the app already defines a channel with that name).
 *
 * Never throws: an audit failure must not stop an editor from saving. If the audit file cannot be
 * written, the full record goes into the summary line instead.
 */
class AuditLogger
{
    private static bool $reportedFailure = false;

    public function __construct(private AuditSnapshots $snapshots, private AuditDiffer $differ, private AuditLogStore $store)
    {
    }

    /**
     * @param  array  $record  action, subject_type, subject_id, subject_title, collection,
     *                         collection_title, site, changes, meta
     */
    public function log(array $record): void
    {
        if (! config('audit-log.enabled', true)) {
            return;
        }

        try {
            $record = $this->complete($record);

            $hash = md5(json_encode([
                $record['action'], $record['subject_type'], $record['subject_id'], $record['site'], $record['changes'],
            ]));

            if (! $this->snapshots->firstTime($hash)) {
                return;
            }

            $stored = $this->persist($record);
            $this->writeFileLine($record, $stored);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    private function complete(array $record): array
    {
        $user = null;

        try {
            $user = User::current();
        } catch (\Throwable $e) {
            // No session/guard (CLI, early boot).
        }

        $meta = $record['meta'] ?? [];

        if (! app()->runningInConsole() && ($impersonator = session()->get('statamic_impersonated_by'))) {
            $meta['impersonated_by'] = [
                'id' => (string) $impersonator,
                'name' => optional(User::find($impersonator))->name(),
            ];
        }

        return [
            'user_id' => $user ? (string) $user->id() : null,
            'user_name' => $user ? $this->limit($user->name() ?: $user->email()) : null,
            'user_email' => $user ? $this->limit($user->email()) : null,
            'action' => $record['action'],
            'subject_type' => $record['subject_type'],
            'subject_id' => isset($record['subject_id']) ? $this->limit((string) $record['subject_id']) : null,
            'subject_title' => isset($record['subject_title']) ? $this->limit((string) $record['subject_title']) : null,
            'collection' => isset($record['collection']) ? $this->limit((string) $record['collection']) : null,
            'collection_title' => isset($record['collection_title']) ? $this->limit((string) $record['collection_title']) : null,
            'site' => $record['site'] ?? null,
            'changes' => ($record['changes'] ?? null) ?: null,
            'meta' => $meta ? $this->differ->scrub($meta) : null,
            'source' => $this->source(),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ];
    }

    private function persist(array $record): bool
    {
        try {
            $this->store->append($record);

            return true;
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            return false;
        }
    }

    /**
     * "islamkabbary ('6a98…') updated entry 'Computer Skills Course' (id: '7c18…') in 'Courses' [ar]: price, title"
     */
    private function writeFileLine(array $r, bool $stored): void
    {
        $who = $r['user_id'] ? "{$r['user_name']} ('{$r['user_id']}')" : 'system ('.$r['source'].')';
        // "[audit]" marks the new format so audit-log:import-legacy never mistakes it for an old line.
        $line = "[audit] {$who} {$r['action']} ".str_replace('_', ' ', $r['subject_type'])." '{$r['subject_title']}'";

        if ($r['subject_id'] && $r['subject_id'] !== $r['subject_title']) {
            $line .= " (id: '{$r['subject_id']}')";
        }
        if ($r['collection_title']) {
            $line .= " in '{$r['collection_title']}'";
        }
        if ($r['site']) {
            $line .= " [{$r['site']}]";
        }
        if ($r['changes'] && $r['action'] !== 'created' && $r['action'] !== 'deleted') {
            $line .= ': '.implode(', ', array_keys($r['changes']));
        }

        // When the audit file could not be written, keep the full record here so nothing is lost.
        $context = $stored ? [] : ['changes' => $r['changes'], 'meta' => $r['meta'], 'ip' => $r['ip']];

        Log::channel(config('audit-log.summary_channel', 'audit-log'))->info($line, $context);
    }

    private function source(): string
    {
        if (app()->runningInConsole()) {
            return 'cli';
        }

        return Statamic::isCpRoute() ? 'cp' : 'web';
    }

    private function limit(?string $value, int $length = 255): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function reportOnce(\Throwable $e): void
    {
        if (self::$reportedFailure) {
            return;
        }

        self::$reportedFailure = true;

        try {
            Log::warning('Audit log write failed (full record kept in the summary log instead): '.$e->getMessage());
        } catch (\Throwable $ignored) {
        }
    }
}
