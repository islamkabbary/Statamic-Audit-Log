@extends('statamic::layout')
@section('title', 'Audit Log')

{{-- In <head>: a <style> inside the CP content is stripped by Vue when it mounts the page. --}}
@push('head')
    @include('audit-log::_styles')
@endpush

@section('content')
<div class="audit-screen">

    @php
        $changes = $log->changes ?? [];
        $side = match ($log->action) {
            'created', 'uploaded' => 'new',
            'deleted' => 'old',
            default => null,
        };
        $meta = collect($log->meta ?? [])->except(['legacy', 'legacy_hash', 'message', 'details', 'before_unavailable', 'impersonated_by']);
        $fmt = fn ($v) => $v === 'published' || $v === 'draft' ? ucfirst($v) : $v;
    @endphp

    @php
        $backUrl = url()->previous() !== url()->current() && str_contains(url()->previous(), 'audit-log') ? url()->previous() : cp_route('audit-log.index');
    @endphp

    <header class="mb-6">
        {{-- statamic::partials.breadcrumb exists on Statamic 4/5 only. --}}
        @if (view()->exists('statamic::partials.breadcrumb'))
            @include('statamic::partials.breadcrumb', ['url' => $backUrl, 'title' => 'Audit Log'])
        @else
            <a href="{{ $backUrl }}" class="audit-back">&larr; Audit Log</a>
        @endif
        <div class="flex items-center justify-between">
            <h1>
                {{ $log->user_name ?: 'System' }}
                <span class="audit-muted">{{ $log->action }}</span>
                {{ $log->subject_title ?: $log->subject_id }}
            </h1>
        </div>
    </header>

    <div class="card mb-4">
        <dl class="audit-meta">
            <dt>User</dt>
            <dd>
                {{ $log->user_name ?: 'System' }}
                @if ($log->user_email) <span class="audit-muted">&lt;{{ $log->user_email }}&gt;</span> @endif
                @if ($imp = data_get($log->meta, 'impersonated_by'))
                    <div class="audit-muted text-sm">while impersonated by {{ $imp['name'] ?? $imp['id'] }}</div>
                @endif
            </dd>

            <dt>Action</dt>
            <dd>
                <span class="audit-badge audit-badge--{{ $log->action }}">{{ $log->actionLabel() }}</span>
                @if ($log->isLegacy()) <span class="audit-badge audit-badge--legacy">legacy record</span> @endif
            </dd>

            @if ($log->collection_title || $log->collection)
                <dt>{{ $log->subject_type === 'entry' ? 'Collection' : ($log->subject_type === 'term' ? 'Taxonomy' : ($log->subject_type === 'asset' || $log->subject_type === 'asset_folder' ? 'Container' : 'Group')) }}</dt>
                <dd>{{ $log->collection_title ?: $log->collection }}</dd>
            @endif

            <dt>{{ \IslamKabbary\AuditLog\Audit\AuditRecord::subjectTypeLabel($log->subject_type) }}</dt>
            <dd>{{ $log->subject_title ?: '—' }}</dd>

            @if ($log->subject_id)
                <dt>{{ \IslamKabbary\AuditLog\Audit\AuditRecord::subjectTypeLabel($log->subject_type) }} ID</dt>
                <dd>
                    <code>{{ $log->subject_id }}</code>
                    @if ($history > 1)
                        · <a class="text-blue text-sm" href="{{ cp_route('audit-log.index', ['subject' => $log->subject_id, 'type' => $log->subject_type]) }}">view all {{ $history }} records</a>
                    @endif
                </dd>
            @endif

            @if ($log->site)
                <dt>Site</dt>
                <dd>{{ $log->site }}</dd>
            @endif

            <dt>Date</dt>
            <dd>{{ $log->created_at?->format('M j, Y H:i:s') }}</dd>

            <dt>Source</dt>
            <dd>{{ ['cp' => 'Control panel', 'web' => 'Website', 'cli' => 'Command line / job'][$log->source] ?? ($log->source ?: '—') }}@if ($log->ip) <span class="audit-muted">· {{ $log->ip }}</span>@endif</dd>

            @foreach ($meta as $key => $value)
                <dt>{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                <dd class="text-sm">{{ is_array($value) ? implode(', ', \Illuminate\Support\Arr::flatten($value)) : (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) }}</dd>
            @endforeach
        </dl>
    </div>

    <h2 class="mb-2 font-bold">
        @if ($side === 'new') Initial values
        @elseif ($side === 'old') Values at deletion
        @else Changes
        @endif
    </h2>

    <div class="card p-0">
        @if ($log->isLegacy())
            <div class="p-4">
                <p class="audit-muted text-sm mb-2">Recorded by the previous activity log, which did not capture field changes.</p>
                <code class="text-sm">{{ data_get($log->meta, 'message') }}</code>
            </div>
        @elseif (data_get($log->meta, 'before_unavailable'))
            <div class="p-4 audit-muted">This item was saved outside the normal editing flow, so its previous values were not available and field changes could not be determined.</div>
        @elseif (data_get($log->meta, 'details') === 'not_tracked')
            <div class="p-4 audit-muted">Field-level changes are not tracked for {{ \Illuminate\Support\Str::plural(strtolower(\IslamKabbary\AuditLog\Audit\AuditRecord::subjectTypeLabel($log->subject_type))) }} (configuration items); this record shows who {{ $log->action }} it and when.</div>
        @elseif (! $changes)
            <div class="p-4 audit-muted">No field values recorded.</div>
        @else
            @foreach ($changes as $handle => $change)
                <div class="audit-change">
                    <div class="audit-change__label">
                        {{ $change['label'] ?? $handle }}
                        @if (($change['label'] ?? $handle) !== $handle)<small><code>{{ $handle }}</code></small>@endif
                    </div>

                    @if (! empty($change['masked']))
                        <div class="audit-val audit-val--plain">******** <span class="audit-muted">(sensitive value — {{ $side ? 'set' : 'changed' }}, not stored)</span></div>

                    @elseif (! empty($change['too_large']))
                        <div class="audit-val audit-val--plain audit-muted">Changed — the values were too large to store.</div>

                    @elseif (array_key_exists('added', $change) || array_key_exists('removed', $change))
                        @if (! empty($change['reordered']))
                            <div class="audit-muted text-sm mb-2">Order changed</div>
                            <div class="audit-cmp">
                                @include('audit-log::_value', ['value' => $change['old'] ?? null, 'kind' => 'old', 'caption' => 'Old order'])
                                @include('audit-log::_value', ['value' => $change['new'] ?? null, 'kind' => 'new', 'caption' => 'New order'])
                            </div>
                        @else
                            <ul class="audit-list">
                                @foreach ($change['added'] ?? [] as $item)
                                    <li class="add">+ {{ is_array($item) ? $item['title'].' ('.$item['id'].')' : $item }}</li>
                                @endforeach
                                @foreach ($change['removed'] ?? [] as $item)
                                    <li class="rem">− {{ is_array($item) ? $item['title'].' ('.$item['id'].')' : $item }}</li>
                                @endforeach
                            </ul>
                        @endif

                    @elseif (array_key_exists('lines', $change))
                        <ul class="audit-list">
                            @foreach ($change['lines'] as $line)
                                <li class="{{ $line['op'] === '+' ? 'add' : ($line['op'] === '-' ? 'rem' : 'more') }}">{{ $line['op'] === '-' ? '−' : $line['op'] }} {{ $line['text'] }}</li>
                            @endforeach
                        </ul>

                    @elseif (array_key_exists('items', $change))
                        <table class="audit-nested">
                            <thead><tr><th>Where</th><th>Old</th><th>New</th></tr></thead>
                            <tbody>
                                @foreach ($change['items'] as $item)
                                    <tr>
                                        <td class="path">{{ $item['path'] }}</td>
                                        <td class="o">@if ($item['old'] === null)<span class="audit-empty">empty</span>@elseif (is_bool($item['old'])){{ $item['old'] ? 'Yes' : 'No' }}@else{{ $item['old'] }}@endif</td>
                                        <td class="n">@if ($item['new'] === null)<span class="audit-empty">empty</span>@elseif (is_bool($item['new'])){{ $item['new'] ? 'Yes' : 'No' }}@else{{ $item['new'] }}@endif</td>
                                    </tr>
                                @endforeach
                                @if (! empty($change['more']))
                                    <tr><td colspan="3" class="audit-muted">… {{ $change['more'] }} more change(s) not shown</td></tr>
                                @endif
                            </tbody>
                        </table>

                    @elseif ($side)
                        @include('audit-log::_value', ['value' => $fmt($change[$side] ?? null), 'kind' => 'plain', 'caption' => null])

                    @else
                        <div class="audit-cmp">
                            @include('audit-log::_value', ['value' => $fmt($change['old'] ?? null), 'kind' => 'old', 'caption' => 'Old'])
                            @include('audit-log::_value', ['value' => $fmt($change['new'] ?? null), 'kind' => 'new', 'caption' => 'New'])
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
@stop
