<?php

namespace IslamKabbary\AuditLog\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\GlobalSet;

class GlobalsAuditTest extends TestCase
{
    private function makeSet()
    {
        $set = GlobalSet::make('contact')->title('Contact');
        $values = ['phone' => '0100', 'email' => 'info@example.com'];

        if (method_exists($set, 'addLocalization')) {
            // Statamic 4/5: a set holds its localizations and saves them itself.
            $set->addLocalization($set->makeLocalization('default')->data($values))->save();
        } else {
            // Statamic 6: values are saved on their own, per site.
            $set->save();
            ($set->in('default') ?? $set->makeLocalization('default'))->data($values)->save();
        }

        return GlobalSet::find('contact');
    }

    private function globalRecords()
    {
        return $this->records()->where('subject_type', 'global')->values();
    }

    #[Test]
    public function changing_a_value_records_only_that_value(): void
    {
        $set = $this->makeSet();

        $set->in('default')->set('phone', '0111')->save();

        $record = $this->globalRecords()->first();

        $this->assertSame('updated', $record->action);
        $this->assertSame('contact', $record->subject_id);
        $this->assertSame(['phone'], array_keys($record->changes));
        $this->assertSame('0100', $record->changes['phone']['old']);
        $this->assertSame('0111', $record->changes['phone']['new']);
    }

    /**
     * Statamic 6 keeps single-site values in their own file, not under `data:` in the set file.
     * Saving the set first must not leave an empty "before" that makes every value look new.
     */
    #[Test]
    public function saving_the_set_before_its_values_does_not_blank_the_before_state(): void
    {
        $set = $this->makeSet();

        $set->save();
        $set->in('default')->set('phone', '0111')->save();

        $record = $this->globalRecords()->first();

        $this->assertSame(['phone'], array_keys($record->changes));
        $this->assertSame('0100', $record->changes['phone']['old']);
    }
}
