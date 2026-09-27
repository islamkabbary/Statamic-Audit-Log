@extends('statamic::layout')
@section('title', 'Audit Log')

{{-- In <head>: a <style> inside the CP content is stripped by Vue when it mounts the page. --}}
@push('head')
    @include('audit-log::_styles')
@endpush

@section('content')
<div class="audit-screen">

    <header class="mb-6">
        <div class="flex items-center justify-between">
            <h1>Audit Log</h1>
        </div>
    </header>

    <form method="GET" action="{{ cp_route('audit-log.index') }}" class="card mb-4">
        <div class="audit-filters">
            <div>
                <label for="audit-q">Search</label>
                <input id="audit-q" type="text" name="q" class="input-text" value="{{ $filters['q'] ?? '' }}" placeholder="Title, ID or user">
            </div>
            <div>
                <label for="audit-user">User</label>
                <select id="audit-user" name="user" class="input-text">
                    <option value="">All users</option>
                    @foreach ($options['users'] as $id => $name)
                        <option value="{{ $id }}" @selected(($filters['user'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-action">Action</label>
                <select id="audit-action" name="action" class="input-text">
                    <option value="">All actions</option>
                    @foreach ($options['actions'] as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ ucfirst($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-type">Type</label>
                <select id="audit-type" name="type" class="input-text">
                    <option value="">All types</option>
                    @foreach ($options['types'] as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ \IslamKabbary\AuditLog\Audit\AuditRecord::subjectTypeLabel($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-collection">Collection / group</label>
                <select id="audit-collection" name="collection" class="input-text">
                    <option value="">All</option>
                    @foreach ($options['collections'] as $handle => $title)
                        <option value="{{ $handle }}" @selected(($filters['collection'] ?? '') === (string) $handle)>{{ $title ?: $handle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-from">From</label>
                <input id="audit-from" type="date" name="from" class="input-text" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div>
                <label for="audit-to">To</label>
                <input id="audit-to" type="date" name="to" class="input-text" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="flex">
                <button type="submit" class="btn-primary rtl:ml-2 ltr:mr-2">Filter</button>
                @if (array_filter($filters))
                    <a href="{{ cp_route('audit-log.index') }}" class="btn">Reset</a>
                @endif
            </div>
        </div>
        @if (! empty($filters['subject']))
            <input type="hidden" name="subject" value="{{ $filters['subject'] }}">
            <p class="text-sm audit-muted mt-3">Showing the history of <code>{{ $filters['subject'] }}</code>.</p>
        @endif
    </form>

    <div class="card p-0">
        @if ($logs->isEmpty())
            <div class="p-4 audit-muted">
                @if (array_filter($filters))
                    No audit records match these filters.
                @else
                    No changes recorded yet. Saving an entry, global, user, role or asset in the CP will appear here
                    (a save that changes no value is not recorded).
                @endif
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Item</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        @php $url = cp_route('audit-log.show', $log->id); @endphp
                        <tr class="audit-row" onclick="if (!event.target.closest('a')) window.location='{{ $url }}'">
                            <td class="whitespace-nowrap text-sm" title="{{ $log->created_at?->format('Y-m-d H:i:s') }}">
                                {{ $log->created_at?->format('M j, Y H:i') }}
                            </td>
                            <td class="text-sm">
                                {{ $log->user_name ?: 'System ('.($log->source ?: 'unknown').')' }}
                            </td>
                            <td>
                                <span class="audit-badge audit-badge--{{ $log->action }}">{{ ucfirst($log->action) }}</span>
                                @if ($log->isLegacy())
                                    <span class="audit-badge audit-badge--legacy">legacy</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ $url }}" class="text-blue">{{ $log->subject_title ?: $log->subject_id ?: '—' }}</a>
                                <span class="audit-muted text-sm">
                                    · {{ \IslamKabbary\AuditLog\Audit\AuditRecord::subjectTypeLabel($log->subject_type) }}@if ($log->collection_title) · {{ $log->collection_title }}@endif
                                </span>
                                @if ($log->changes && ! in_array($log->action, ['created', 'deleted', 'uploaded'], true))
                                    <div class="audit-fields">
                                        Changed: {{ collect($log->changes)->map(fn ($c, $k) => $c['label'] ?? $k)->take(6)->implode(', ') }}@if (count($log->changes) > 6), +{{ count($log->changes) - 6 }} more @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="audit-pager">
                <span class="audit-muted">
                    {{ number_format($logs->firstItem()) }}–{{ number_format($logs->lastItem()) }} of {{ number_format($logs->total()) }}
                </span>
                @if ($logs->hasPages())
                    @php
                        $current = $logs->currentPage();
                        $last = $logs->lastPage();
                        $window = range(max(1, $current - 2), min($last, $current + 2));
                        $pages = array_values(array_unique(array_merge([1], $window, [$last])));
                    @endphp
                    <nav class="audit-pages" aria-label="Pagination">
                        @if ($logs->onFirstPage())
                            <span class="audit-page is-disabled">&lsaquo;</span>
                        @else
                            <a class="audit-page" href="{{ $logs->previousPageUrl() }}" rel="prev">&lsaquo;</a>
                        @endif

                        @foreach ($pages as $i => $page)
                            @if ($i > 0 && $page - $pages[$i - 1] > 1)
                                <span class="audit-page is-gap">…</span>
                            @endif
                            @if ($page === $current)
                                <span class="audit-page is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="audit-page" href="{{ $logs->url($page) }}">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($logs->hasMorePages())
                            <a class="audit-page" href="{{ $logs->nextPageUrl() }}" rel="next">&rsaquo;</a>
                        @else
                            <span class="audit-page is-disabled">&rsaquo;</span>
                        @endif
                    </nav>
                @endif
            </div>
        @endif
    </div>
</div>
@stop
