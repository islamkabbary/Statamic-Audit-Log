<?php

namespace IslamKabbary\AuditLog\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class EntryAuditTest extends TestCase
{
    private function entryRecords()
    {
        return $this->records()->where('subject_type', 'entry')->values();
    }

    private function makeEntry(array $data = ['title' => 'First title', 'price' => '100'])
    {
        Collection::make('pages')->title('Pages')->save();

        $entry = Entry::make()->collection('pages')->slug('about')->data($data);
        $entry->save();

        return Entry::find($entry->id());
    }

    #[Test]
    public function creating_an_entry_records_its_initial_values(): void
    {
        $entry = $this->makeEntry();

        $record = $this->entryRecords()->first();

        $this->assertSame('created', $record->action);
        $this->assertSame('entry', $record->subject_type);
        $this->assertSame($entry->id(), $record->subject_id);
        $this->assertSame('First title', $record->subject_title);
        $this->assertSame('pages', $record->collection);
        $this->assertSame('Pages', $record->collection_title);
        $this->assertSame('First title', $record->changes['title']['new']);
        $this->assertCount(1, $this->entryRecords(), 'The *Saved after *Created must not log a second row.');
    }

    #[Test]
    public function editing_an_entry_records_old_and_new_values_of_changed_fields_only(): void
    {
        $entry = $this->makeEntry();

        $entry->set('title', 'Second title')->save();

        $record = $this->latest();

        $this->assertSame('updated', $record->action);
        $this->assertSame(['title'], array_keys($record->changes));
        $this->assertSame('First title', $record->changes['title']['old']);
        $this->assertSame('Second title', $record->changes['title']['new']);
    }

    #[Test]
    public function saving_without_a_change_writes_nothing(): void
    {
        $entry = $this->makeEntry();

        $entry->save();

        $this->assertCount(1, $this->entryRecords());
    }

    #[Test]
    public function publishing_state_changes_are_their_own_action(): void
    {
        $entry = $this->makeEntry();

        $entry->published(false)->save();

        $this->assertSame('unpublished', $this->latest()->action);
    }

    #[Test]
    public function deleting_an_entry_records_its_last_values(): void
    {
        $entry = $this->makeEntry();

        $entry->delete();

        $record = $this->latest();

        $this->assertSame('deleted', $record->action);
        $this->assertSame('First title', $record->changes['title']['old']);
    }

    #[Test]
    public function sensitive_fields_are_masked(): void
    {
        $entry = $this->makeEntry(['title' => 'Integration', 'api_key' => 'first-secret']);

        $entry->set('api_key', 'second-secret')->save();

        $json = json_encode($this->records()->map->toArray()->all());

        $this->assertStringNotContainsString('first-secret', $json);
        $this->assertStringNotContainsString('second-secret', $json);
        $this->assertTrue($this->latest()->changes['api_key']['masked'] ?? false);
    }

    #[Test]
    public function nothing_is_recorded_when_disabled(): void
    {
        config(['audit-log.enabled' => false]);

        $this->makeEntry();

        $this->assertCount(0, $this->records());
    }
}
