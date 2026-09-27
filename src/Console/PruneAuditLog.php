<?php

namespace IslamKabbary\AuditLog\Console;

use IslamKabbary\AuditLog\Audit\AuditLogStore;
use Illuminate\Console\Command;

/**
 * Deletes audit day files (storage/audit/audit-YYYY-MM-DD.jsonl) older than
 * config audit-log.retention_days. 0 keeps everything.
 */
class PruneAuditLog extends Command
{
    protected $signature = 'audit-log:prune';

    protected $description = 'Delete audit log files older than the configured retention';

    public function handle(AuditLogStore $store): int
    {
        $days = (int) config('audit-log.retention_days', 0);
        $deleted = $store->prune($days);

        $this->info($days > 0 ? "Deleted {$deleted} audit file(s) older than {$days} days." : 'Retention is 0: nothing deleted.');

        return self::SUCCESS;
    }
}
