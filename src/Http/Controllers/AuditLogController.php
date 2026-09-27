<?php

namespace IslamKabbary\AuditLog\Http\Controllers;

use IslamKabbary\AuditLog\Audit\AuditLogStore;
use IslamKabbary\AuditLog\Audit\AuditRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Statamic\Http\Controllers\CP\CpController;

/**
 * CP › Tools › Audit Log: filterable list + detail view of the audit files (AuditLogStore).
 * Filtering happens in memory; a from/to date limits which day files are read at all.
 */
class AuditLogController extends CpController
{
    private const PER_PAGE = 50;

    public function index(Request $request, AuditLogStore $store)
    {
        $this->authorize('view audit log');

        $filters = $request->only(['q', 'user', 'action', 'type', 'collection', 'from', 'to', 'subject']);
        $all = $store->records($this->date($filters['from'] ?? null), $this->date($filters['to'] ?? null));
        $term = Str::lower(trim($filters['q'] ?? ''));

        $matching = $all->filter(fn (AuditRecord $r) => (empty($filters['user']) || $r->user_id === $filters['user'])
            && (empty($filters['action']) || $r->action === $filters['action'])
            && (empty($filters['type']) || $r->subject_type === $filters['type'])
            && (empty($filters['collection']) || $r->collection === $filters['collection'])
            && (empty($filters['subject']) || $r->subject_id === $filters['subject'])
            && ($term === '' || $r->subject_id === trim($filters['q'])
                || Str::contains(Str::lower($r->subject_title.' '.$r->user_name.' '.$r->user_email), $term)))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $logs = (new LengthAwarePaginator($matching->forPage($page, self::PER_PAGE)->values(), $matching->count(), self::PER_PAGE, $page, [
            'path' => cp_route('audit-log.index'),
        ]))->withQueryString();

        return view('audit-log::index', [
            'logs' => $logs,
            'filters' => $filters,
            'options' => $this->filterOptions($all),
        ]);
    }

    public function show(string $id, AuditLogStore $store)
    {
        $this->authorize('view audit log');

        abort_unless($log = $store->find($id), 404);

        $history = $log->subject_id
            ? $store->records()->filter(fn (AuditRecord $r) => $r->subject_type === $log->subject_type && $r->subject_id === $log->subject_id)->count()
            : 0;

        return view('audit-log::show', ['log' => $log, 'history' => $history]);
    }

    private function filterOptions($records): array
    {
        return [
            'users' => $records->whereNotNull('user_id')->mapWithKeys(fn ($r) => [$r->user_id => $r->user_name])->sort()->all(),
            'actions' => $records->pluck('action')->unique()->sort()->values()->all(),
            'types' => $records->pluck('subject_type')->unique()->sort()->values()->all(),
            'collections' => $records->whereNotNull('collection')->mapWithKeys(fn ($r) => [$r->collection => $r->collection_title])->sort()->all(),
        ];
    }

    private function date(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::createFromFormat('!Y-m-d', $value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
