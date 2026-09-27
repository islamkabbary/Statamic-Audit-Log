<?php

namespace IslamKabbary\AuditLog\Audit;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * File storage for the audit log: one JSON object per line, one file per day —
 * storage/audit/audit-YYYY-MM-DD.jsonl (git-ignored, outside the public /logs viewer's folder).
 *
 * Ids are "YmdHisu-xxxx": they sort chronologically and name the file they live in, so a single
 * record is found by reading one day's file. Appends take an exclusive lock, so concurrent saves
 * never interleave lines.
 */
class AuditLogStore
{
    public function directory(): string
    {
        return rtrim(config('audit-log.path', storage_path('audit')), '/\\');
    }

    /** Appends a record (assigning its id) and returns it. Throws if the file cannot be written. */
    public function append(array $data): AuditRecord
    {
        $record = AuditRecord::fromArray($data);
        $record->created_at ??= now();
        $record->id ??= $record->created_at->format('YmdHisu').'-'.Str::lower(Str::random(4));

        $this->write($record);

        return $record;
    }

    public function write(AuditRecord $record): void
    {
        $this->ensureDirectory();

        $line = json_encode($record->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->fileFor($record->created_at), $line."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not write audit log file '.$this->fileFor($record->created_at));
        }
    }

    /**
     * Every record in the date range (whole days, inclusive), newest first.
     * Only the files for those days are read.
     */
    public function records(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->files($from, $to)
            ->flatMap(fn ($file) => $this->readFile($file))
            ->sortByDesc(fn (AuditRecord $r) => $r->id)
            ->values();
    }

    public function find(string $id): ?AuditRecord
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})\d{6}/', $id, $m)) {
            return null;
        }

        $file = $this->directory()."/audit-{$m[1]}-{$m[2]}-{$m[3]}.jsonl";

        return File::exists($file)
            ? $this->readFile($file)->first(fn (AuditRecord $r) => $r->id === $id)
            : null;
    }

    /** Deletes the day files older than $days days. Returns how many were deleted. */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days)->startOfDay();

        return $this->files()
            ->filter(fn ($file) => $this->dateOf($file)?->lt($cutoff))
            ->each(fn ($file) => File::delete($file))
            ->count();
    }

    private function files(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return collect(File::glob($this->directory().'/audit-*.jsonl') ?: [])
            ->filter(function ($file) use ($from, $to) {
                $date = $this->dateOf($file);

                return $date
                    && (! $from || $date->gte($from->copy()->startOfDay()))
                    && (! $to || $date->lte($to->copy()->startOfDay()));
            })
            ->sort()
            ->values();
    }

    private function readFile(string $file): Collection
    {
        $records = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $data = json_decode($line, true);

            if (is_array($data)) {
                $records[] = AuditRecord::fromArray($data);
            }
        }

        return collect($records);
    }

    /**
     * Creates the directory with its own .gitignore: the default path is under storage/, which
     * host apps usually do not ignore as a whole, and audit records must never be committed.
     */
    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        File::ensureDirectoryExists($directory);

        if (! File::exists($directory.'/.gitignore')) {
            File::put($directory.'/.gitignore', "*\n!.gitignore\n");
        }
    }

    private function fileFor(Carbon $date): string
    {
        return $this->directory().'/audit-'.$date->format('Y-m-d').'.jsonl';
    }

    private function dateOf(string $file): ?Carbon
    {
        return preg_match('/audit-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $m)
            ? Carbon::createFromFormat('!Y-m-d', $m[1])
            : null;
    }
}
