<?php

namespace IslamKabbary\AuditLog\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class CpScreenTest extends TestCase
{
    private function recordSomething(): void
    {
        Collection::make('pages')->title('Pages')->save();
        Entry::make()->collection('pages')->slug('home')->data(['title' => 'Home page'])->save();
    }

    private function user(array $permissions)
    {
        Role::make('editor')->title('Editor')->permissions($permissions)->save();

        $user = User::make()->email('editor@example.com')->assignRole('editor');
        $user->save();

        return $user;
    }

    #[Test]
    public function guests_are_sent_to_the_login(): void
    {
        $this->get(cp_route('audit-log.index'))->assertRedirect();
    }

    #[Test]
    public function users_without_the_permission_are_refused(): void
    {
        $this->recordSomething();

        // The CP turns an AuthorizationException into a redirect back with an "unauthorized" flash.
        $this->actingAs($this->user(['access cp']))
            ->get(cp_route('audit-log.index'))
            ->assertRedirect()
            ->assertDontSee('Home page');
    }

    #[Test]
    public function the_list_and_the_detail_screen_render_for_permitted_users(): void
    {
        $this->recordSomething();
        $record = $this->records()->firstWhere('subject_type', 'entry');
        $user = $this->user(['access cp', 'view audit log']);

        $this->actingAs($user)
            ->get(cp_route('audit-log.index'))
            ->assertOk()
            ->assertSee('Home page');

        $this->actingAs($user)
            ->get(cp_route('audit-log.show', $record->id))
            ->assertOk()
            ->assertSee('Home page')
            ->assertSee('Initial values');
    }
}
