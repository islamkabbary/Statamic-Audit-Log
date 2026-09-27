<?php

namespace IslamKabbary\AuditLog\Audit;

use Carbon\Carbon;

/**
 * One audited control-panel action, as stored (one JSON line) by AuditLogStore.
 *
 * `changes` shape, keyed by field handle — only the fields that actually changed:
 *   scalar:        {label, type, old, new}
 *   list/relation: {label, type, added: [..], removed: [..]}   items are strings or {id, title}
 *   rich/nested:   {label, type, lines: [{op: '-'|'+', text}]}  or  {label, type, items: [{path, old, new}]}
 *   sensitive:     {label, old: '********', new: '********', masked: true}
 * On `created` only `new` is present (initial values); on `deleted` only `old` (last values).
 * Legacy records imported from the old file log have `changes` = null and meta.legacy = true.
 */
class AuditRecord
{
    public const FIELDS = [
        'id', 'user_id', 'user_name', 'user_email', 'action', 'subject_type', 'subject_id', 'subject_title',
        'collection', 'collection_title', 'site', 'changes', 'meta', 'source', 'ip', 'created_at',
    ];

    public ?string $id = null;
    public ?string $user_id = null;
    public ?string $user_name = null;
    public ?string $user_email = null;
    public ?string $action = null;
    public ?string $subject_type = null;
    public ?string $subject_id = null;
    public ?string $subject_title = null;
    public ?string $collection = null;
    public ?string $collection_title = null;
    public ?string $site = null;
    public ?array $changes = null;
    public ?array $meta = null;
    public ?string $source = null;
    public ?string $ip = null;
    public ?Carbon $created_at = null;

    public static function fromArray(array $data): self
    {
        $record = new self;

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            $record->{$field} = match (true) {
                $field === 'created_at' => $value ? Carbon::parse($value) : null,
                in_array($field, ['changes', 'meta'], true) => $value ?: null,
                default => $value === null ? null : (string) $value,
            };
        }

        return $record;
    }

    public function toArray(): array
    {
        $data = [];

        foreach (self::FIELDS as $field) {
            $data[$field] = $field === 'created_at'
                ? $this->created_at?->format('Y-m-d H:i:s')
                : $this->{$field};
        }

        return $data;
    }

    public function isLegacy(): bool
    {
        return (bool) ($this->meta['legacy'] ?? false);
    }

    /** "Updated Entry", "Deleted Role", ... */
    public function actionLabel(): string
    {
        return ucfirst((string) $this->action).' '.self::subjectTypeLabel($this->subject_type);
    }

    public static function subjectTypeLabel(?string $type): string
    {
        return ucwords(str_replace('_', ' ', (string) $type));
    }
}
