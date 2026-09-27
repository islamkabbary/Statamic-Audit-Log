{{-- One stored value (old or new). Params: $value, $kind ('old'|'new'|'plain'), $caption --}}
<div class="audit-val audit-val--{{ $kind }}">
    @if (! empty($caption))<span class="audit-val__cap">{{ $caption }}</span>@endif
    @if ($value === null || $value === '')
        <span class="audit-empty">empty</span>
    @elseif (is_bool($value))
        {{ $value ? 'Yes' : 'No' }}
    @elseif (is_array($value))
        {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}
    @else
        {{ $value }}
    @endif
</div>
