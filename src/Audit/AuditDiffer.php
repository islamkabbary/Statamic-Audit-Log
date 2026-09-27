<?php

namespace IslamKabbary\AuditLog\Audit;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Support\Str;

/**
 * Turns a before/after pair of raw Statamic data arrays into a compact, human-readable list of
 * the fields that actually changed. See IslamKabbary\AuditLog\Audit\AuditRecord for the stored shapes.
 *
 * Values are normalized before comparing so the CP's own round-trip is not reported as a change:
 * null / '' / [] / missing are all "empty", 1200 equals "1200", associative keys are sorted and
 * null keys dropped, and Replicator/Bard set ids are ignored. List order IS significant (e.g. the
 * order of related courses), so a pure reorder is reported.
 */
class AuditDiffer
{
    public const MASK = '********';

    /** Blueprint field types whose value is a list of references. */
    private const REFERENCE_TYPES = ['entries', 'terms', 'taxonomy', 'users', 'assets', 'collections', 'user_roles', 'user_groups', 'sites', 'structures', 'navs', 'taxonomies', 'form'];

    /** Field types holding rich text: diffed line by line. */
    private const TEXT_TYPES = ['bard', 'markdown', 'textarea', 'html', 'tinymce_cloud', 'code'];

    private array $fieldCache = [];

    /**
     * @param  array  $fields  handle => ['label' => ?, 'type' => ?, 'config' => []] (optional hints)
     */
    public function diff(array $old, array $new, array $fields = []): array
    {
        $changes = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $key = (string) $key;

            if ($this->isIgnored($key)) {
                continue;
            }

            $field = $fields[$key] ?? [];
            $before = $this->normalize($this->fieldValue($old[$key] ?? null, $field));
            $after = $this->normalize($this->fieldValue($new[$key] ?? null, $field));

            if ($this->same($before, $after)) {
                continue;
            }

            $label = $field['label'] ?? $this->humanize($key);

            // The CP writes a field's default the first time an older entry is saved after that
            // field was added to the blueprint. Unset and "its default" read the same to editors.
            if ($field && ($before === null || $after === null) && $this->same($before ?? $after, $this->fieldDefault($field))) {
                continue;
            }

            if ($this->isSensitive($key)) {
                $changes[$key] = ['label' => $label, 'old' => self::MASK, 'new' => self::MASK, 'masked' => true];

                continue;
            }

            $changes[$key] = ['label' => $label, 'type' => $field['type'] ?? null]
                + $this->describe($before, $after, $field);
        }

        // Blueprint order (the order editors see the fields in); unknown keys keep their place after.
        $position = array_flip(array_keys($fields));
        $found = array_flip(array_keys($changes));
        uksort($changes, fn ($a, $b) => [$position[$a] ?? PHP_INT_MAX, $found[$a]] <=> [$position[$b] ?? PHP_INT_MAX, $found[$b]]);

        return $this->capSize($changes);
    }

    /**
     * Compact identification snapshot for creates ($side = 'new') and deletes ($side = 'old'):
     * short scalar values and short lists only; rich/nested content is noted, not copied.
     */
    public function snapshot(array $values, array $fields = [], string $side = 'new'): array
    {
        $max = (int) config('audit-log.max_initial_fields', 25);
        $out = [];

        // Title first, it is what identifies the item.
        uksort($values, fn ($a, $b) => ($b === 'title') <=> ($a === 'title'));

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (count($out) >= $max) {
                break;
            }

            if ($this->isIgnored($key)) {
                continue;
            }

            $field = $fields[$key] ?? [];
            $value = $this->normalize($this->fieldValue($value, $field));

            // Empty, or just the field's default (e.g. every toggle left off): not worth listing.
            if ($value === null || ($field && $this->same($value, $this->fieldDefault($field)))) {
                continue;
            }

            $label = $field['label'] ?? $this->humanize($key);

            if ($this->isSensitive($key)) {
                $out[$key] = ['label' => $label, $side => self::MASK, 'masked' => true];
            } elseif (is_scalar($value) && mb_strlen((string) $value) <= 200) {
                $out[$key] = ['label' => $label, $side => $value];
            } elseif ($this->isScalarList($value) && count($value) <= 15) {
                $out[$key] = ['label' => $label, $side => $this->truncate(implode(', ', $value))];
            }
        }

        return $out;
    }

    /** Masks sensitive keys at any depth of an arbitrary array (used for meta payloads). */
    public function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    /** Field hints (label/type/config) for every field of a Statamic blueprint. */
    public function fieldsFromBlueprint($blueprint): array
    {
        if (! $blueprint) {
            return [];
        }

        $cacheKey = $blueprint->namespace().'::'.$blueprint->handle();

        return $this->fieldCache[$cacheKey] ??= $blueprint->fields()->all()
            ->map(fn ($field) => [
                'label' => $field->display(),
                'type' => $field->type(),
                'config' => $field->config(),
            ])->all();
    }

    public function isSensitive(string $key): bool
    {
        foreach ((array) config('audit-log.sensitive_patterns', []) as $pattern) {
            if (@preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    public function isIgnored(string $key): bool
    {
        return in_array($key, (array) config('audit-log.ignored_fields', []), true);
    }

    /* ------------------------------------------------------------------ */

    private function describe($before, $after, array $field): array
    {
        $type = $field['type'] ?? null;

        // Reference fields (entries/terms/users/assets...), multi-selects, tags, checkbox lists.
        if (in_array($type, self::REFERENCE_TYPES, true)
            || ($this->isScalarListOrScalar($before) && $this->isScalarListOrScalar($after) && (is_array($before) || is_array($after)))) {
            return $this->describeList((array) ($before ?? []), (array) ($after ?? []), $type, $field['config'] ?? []);
        }

        // Rich text: Bard (ProseMirror arrays or saved HTML), markdown, textarea, long HTML strings.
        if (in_array($type, self::TEXT_TYPES, true) || $this->looksLikeRichText($before) || $this->looksLikeRichText($after)) {
            $lines = $this->lineDiff($this->toLines($before), $this->toLines($after));

            if ($lines) {
                return ['lines' => $lines];
            }
        }

        // Nested structures: Replicator, Grid, Group, Table, arrays.
        if (is_array($before) || is_array($after)) {
            return $this->describeNested($before, $after);
        }

        return ['old' => $this->display($before), 'new' => $this->display($after)];
    }

    private function describeList(array $before, array $after, ?string $type, array $config): array
    {
        $before = array_values(array_map('strval', $before));
        $after = array_values(array_map('strval', $after));

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $out = [
            'added' => $this->labels($added, $type, $config),
            'removed' => $this->labels($removed, $type, $config),
        ];

        // Same members, different order.
        if (! $added && ! $removed) {
            $out['reordered'] = true;
            $out['old'] = $this->truncate(implode(', ', $before));
            $out['new'] = $this->truncate(implode(', ', $after));
        }

        return $out;
    }

    /** Resolves reference ids to titles, only for the items that changed. */
    private function labels(array $values, ?string $type, array $config): array
    {
        $max = (int) config('audit-log.max_nested_changes', 30);

        return collect($values)->take($max)->map(function ($value) use ($type, $config) {
            $title = null;

            try {
                $title = match ($type) {
                    'entries' => Entry::find($value)?->value('title'),
                    'users' => User::find($value)?->name(),
                    'user_roles' => Role::find($value)?->title(),
                    'user_groups' => UserGroup::find($value)?->title(),
                    'terms', 'taxonomy' => $this->termTitle($value, $config),
                    'assets' => basename($value),
                    default => Str::isUuid($value) ? Entry::find($value)?->value('title') : null,
                };
            } catch (\Throwable $e) {
                // A label is a nicety; never let it break the audit.
            }

            return $title !== null && $title !== $value
                ? ['id' => $value, 'title' => $this->truncate((string) $title)]
                : $this->truncate($value);
        })->all();
    }

    private function termTitle(string $value, array $config): ?string
    {
        if (str_contains($value, '::')) {
            return Term::find($value)?->title();
        }

        foreach ((array) ($config['taxonomies'] ?? []) as $taxonomy) {
            if ($term = Term::find($taxonomy.'::'.$value)) {
                return $term->title();
            }
        }

        return null;
    }

    private function describeNested($before, $after): array
    {
        $old = $this->flatten($before);
        $new = $this->flatten($after);
        $max = (int) config('audit-log.max_nested_changes', 30);

        $items = [];
        $total = 0;

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
            $o = $old[$path] ?? null;
            $n = $new[$path] ?? null;

            if ($o === $n) {
                continue;
            }

            $total++;

            if (count($items) < $max) {
                $items[] = [
                    'path' => (string) $path,
                    'old' => $this->isSensitive((string) $path) ? self::MASK : $this->display($o),
                    'new' => $this->isSensitive((string) $path) ? self::MASK : $this->display($n),
                ];
            }
        }

        return ['items' => $items] + ($total > $max ? ['more' => $total - $max] : []);
    }

    /**
     * Flattens nested data to "path => scalar". List items are numbered from 1 and labelled
     * with their set type when they are Replicator/Bard sets: "#2 (text_block) › title".
     */
    private function flatten($value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return $prefix === '' && $value === null ? [] : [$prefix === '' ? 'value' : $prefix => $value];
        }

        if ($this->isScalarList($value)) {
            return [$prefix === '' ? 'value' : $prefix => implode(', ', $value)];
        }

        $out = [];
        $isList = array_is_list($value);

        foreach ($value as $key => $child) {
            if ($isList) {
                $segment = '#'.($key + 1).(is_array($child) && isset($child['type']) && is_string($child['type']) ? ' ('.$child['type'].')' : '');
            } else {
                if ($key === 'type' && $prefix !== '' && str_contains($prefix, '(')) {
                    continue; // already part of the segment label
                }
                $segment = (string) $key;
            }

            $path = $prefix === '' ? $segment : $prefix.' › '.$segment;
            $out += $this->flatten($child, $path);
        }

        return $out;
    }

    /* ---------------------------- rich text ---------------------------- */

    private function looksLikeRichText($value): bool
    {
        if (is_string($value)) {
            return mb_strlen($value) > 300 || $value !== strip_tags($value);
        }

        // ProseMirror document: list of nodes with a `type`.
        return is_array($value) && array_is_list($value) && isset($value[0]['type']) && is_string($value[0]['type'])
            && in_array($value[0]['type'], ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'set', 'image', 'table', 'horizontalRule', 'codeBlock'], true);
    }

    /** One readable line per block (paragraph, heading, list item, Bard set). */
    private function toLines($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $text = preg_replace('~<(br|/p|/h[1-6]|/li|/div|/tr|/blockquote)\b[^>]*>~i', "\n", $value);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $this->cleanLines(explode("\n", str_replace("\r", '', $text)));
        }

        if (! is_array($value)) {
            return [(string) $value];
        }

        $lines = [];

        foreach (array_is_list($value) ? $value : [$value] as $node) {
            if (! is_array($node)) {
                $lines[] = (string) $node;

                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'set') {
                $values = $node['attrs']['values'] ?? [];
                $setType = is_array($values) ? ($values['type'] ?? 'set') : 'set';
                $flat = collect($this->flatten(is_array($values) ? array_diff_key($values, ['type' => 1]) : []))
                    ->map(fn ($v, $k) => $k.': '.$this->display($v))->implode(' | ');
                $lines[] = '['.$setType.'] '.$flat;
            } elseif (in_array($type, ['bulletList', 'orderedList'], true)) {
                foreach ($node['content'] ?? [] as $item) {
                    $lines[] = '• '.$this->nodeText($item);
                }
            } elseif ($type === 'heading') {
                $lines[] = 'H'.($node['attrs']['level'] ?? '').': '.$this->nodeText($node);
            } elseif ($type === 'image') {
                $lines[] = '[image] '.($node['attrs']['src'] ?? '');
            } elseif ($type !== null) {
                $lines[] = $this->nodeText($node);
            } else {
                // Not ProseMirror (e.g. a Replicator row): one line per row.
                $lines[] = collect($this->flatten($node))->map(fn ($v, $k) => $k.': '.$this->display($v))->implode(' | ');
            }
        }

        return $this->cleanLines($lines);
    }

    private function nodeText(array $node): string
    {
        if (isset($node['text'])) {
            $text = (string) $node['text'];

            foreach ($node['marks'] ?? [] as $mark) {
                if (($mark['type'] ?? null) === 'link' && ! empty($mark['attrs']['href'])) {
                    $text .= ' ('.$mark['attrs']['href'].')';
                }
            }

            return $text;
        }

        return collect($node['content'] ?? [])
            ->map(fn ($child) => is_array($child) ? $this->nodeText($child) : '')
            ->implode(($node['type'] ?? null) === 'listItem' ? ' ' : '');
    }

    private function cleanLines(array $lines): array
    {
        return array_values(array_filter(array_map(
            fn ($line) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $line)),
            $lines
        ), fn ($line) => $line !== ''));
    }

    /** Changed lines only, in document order: [{op: '-'|'+', text}]. */
    private function lineDiff(array $old, array $new): array
    {
        $max = (int) config('audit-log.max_nested_changes', 30);
        $n = count($old);
        $m = count($new);

        if ($n * $m > 250000) {
            // Too large for an LCS table: fall back to set difference.
            $ops = array_merge(
                array_map(fn ($t) => ['op' => '-', 'text' => $t], array_values(array_diff($old, $new))),
                array_map(fn ($t) => ['op' => '+', 'text' => $t], array_values(array_diff($new, $old)))
            );
        } else {
            $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; $i--) {
                for ($j = $m - 1; $j >= 0; $j--) {
                    $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
                }
            }

            $ops = [];
            $i = $j = 0;
            while ($i < $n || $j < $m) {
                if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                    $i++;
                    $j++;
                } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                    $ops[] = ['op' => '+', 'text' => $new[$j++]];
                } else {
                    $ops[] = ['op' => '-', 'text' => $old[$i++]];
                }
            }
        }

        $total = count($ops);
        $ops = array_map(fn ($op) => ['op' => $op['op'], 'text' => $this->truncate($op['text'])], array_slice($ops, 0, $max));

        if ($total > $max) {
            $ops[] = ['op' => '…', 'text' => ($total - $max).' more changed line(s)'];
        }

        return $ops;
    }

    /* ---------------------------- normalizing ---------------------------- */

    /**
     * Canonical form used both for comparing and for storing.
     */
    public function normalize($value)
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i');
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = str_replace("\r\n", "\n", $value);

            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return $value; // bool / null
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $child) {
            // Replicator/Bard/Grid row ids are generated identifiers, not content.
            if (! $isList && $key === 'id' && (isset($value['type']) || isset($value['enabled'])) && is_string($child) && ! Str::isUuid($child)) {
                continue;
            }
            // `enabled: true` is the default for sets; only a disabled set is meaningful.
            if (! $isList && $key === 'enabled' && $child === true && isset($value['type'])) {
                continue;
            }

            $child = $this->normalize($child);

            if ($isList) {
                $out[] = $child;
            } elseif ($child !== null) {
                $out[$key] = $child;
            }
        }

        if (! $isList) {
            ksort($out);
        }

        return $out === [] ? null : $out;
    }

    /**
     * Field types whose stored shape changes on a CP round-trip without the content changing.
     * Code: a plain string in older files, {code, mode} once the CP saves it — compare the code.
     */
    private function fieldValue($value, array $field)
    {
        if (($field['type'] ?? null) === 'code' && is_array($value)) {
            return $value['code'] ?? null;
        }

        return $value;
    }

    private function fieldDefault(array $field)
    {
        $default = $field['config']['default'] ?? (($field['type'] ?? null) === 'toggle' ? false : null);

        return $this->normalize($default);
    }

    private function same($a, $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE) === json_encode($b, JSON_UNESCAPED_UNICODE);
    }

    private function isScalarList($value): bool
    {
        return is_array($value) && array_is_list($value) && collect($value)->every(fn ($v) => is_scalar($v));
    }

    private function isScalarListOrScalar($value): bool
    {
        return $value === null || is_scalar($value) || $this->isScalarList($value);
    }

    private function display($value)
    {
        if (is_array($value)) {
            return $this->truncate(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // HTML (TinyMCE/Bard HTML inside a Replicator row): show its text, one block per line.
        if (is_string($value) && $value !== strip_tags($value)) {
            return $this->truncate(implode("\n", $this->toLines($value)));
        }

        return is_string($value) ? $this->truncate($value) : $value;
    }

    private function truncate(string $value): string
    {
        $max = (int) config('audit-log.max_string_length', 1000);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }

    private function humanize(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    /** Last-resort guard: an oversized record keeps which fields changed, not the values. */
    private function capSize(array $changes): array
    {
        $max = (int) config('audit-log.max_changes_bytes', 60000);

        if (strlen(json_encode($changes, JSON_UNESCAPED_UNICODE)) <= $max) {
            return $changes;
        }

        return collect($changes)->map(fn ($change) => [
            'label' => $change['label'] ?? null,
            'type' => $change['type'] ?? null,
            'too_large' => true,
        ])->all();
    }
}
