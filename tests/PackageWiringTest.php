<?php

namespace IslamKabbary\AuditLog\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use IslamKabbary\AuditLog\Audit\AuditLogStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;

class PackageWiringTest extends TestCase
{
    #[Test]
    public function config_is_merged_under_audit_log(): void
    {
        $this->assertSame(365, config('audit-log.retention_days'));
        $this->assertSame('audit-log', config('audit-log.summary_channel'));
    }

    /** A config cache built before the package was installed has no audit-log keys at all. */
    #[Test]
    public function defaults_are_restored_when_the_cached_config_lacks_them(): void
    {
        config(['audit-log' => ['summary_channel' => 'from-app']]);

        $provider = $this->app->getProvider(\IslamKabbary\AuditLog\ServiceProvider::class);
        (new \ReflectionMethod($provider, 'mergeDefaultConfig'))->invoke($provider);

        $this->assertNotEmpty(config('audit-log.sensitive_patterns'), 'masking must never silently switch off');
        $this->assertSame(365, config('audit-log.retention_days'));
        $this->assertSame('from-app', config('audit-log.summary_channel'), 'app values still win');
    }

    #[Test]
    public function the_summary_channel_is_registered_when_the_app_has_none(): void
    {
        $this->assertSame('daily', config('logging.channels.audit-log.driver'));
    }

    #[Test]
    public function the_cp_routes_are_registered(): void
    {
        $this->assertStringEndsWith('/audit-log', cp_route('audit-log.index'));
        $this->assertStringEndsWith('/audit-log/20260924120000000000-abcd', cp_route('audit-log.show', '20260924120000000000-abcd'));
    }

    #[Test]
    public function the_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('audit-log:prune', $commands);
        $this->assertContains('audit-log:import-legacy', $commands);
    }

    #[Test]
    public function pruning_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->map->command->filter()->implode("\n");

        $this->assertStringContainsString('audit-log:prune', $events);
    }

    #[Test]
    public function the_audit_directory_ignores_itself_in_git(): void
    {
        Collection::make('pages')->save();

        $this->assertSame("*\n!.gitignore\n", File::get($this->fixtures('audit/.gitignore')));
    }

    #[Test]
    public function prune_deletes_only_files_older_than_the_retention(): void
    {
        File::ensureDirectoryExists($this->fixtures('audit'));
        File::put($this->fixtures('audit/audit-2000-01-01.jsonl'), '');
        File::put($this->fixtures('audit/audit-'.now()->format('Y-m-d').'.jsonl'), '');

        $this->assertSame(1, app(AuditLogStore::class)->prune(30));
        $this->assertFileDoesNotExist($this->fixtures('audit/audit-2000-01-01.jsonl'));
        $this->assertFileExists($this->fixtures('audit/audit-'.now()->format('Y-m-d').'.jsonl'));
    }

    #[Test]
    public function the_legacy_import_reads_webographen_lines(): void
    {
        $dir = storage_path('logs');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/adminlog-2026-01-02.log';
        File::put($file, implode("\n", [
            "[2026-01-02 10:00:00] local.INFO: Islam ('u-1') created/edited entry 'Home' (id: 'e-1') in collection 'Pages'",
            "[2026-01-02 11:00:00] local.INFO: [audit] Islam ('u-1') updated entry 'Home'",
        ]));

        try {
            $this->artisan('audit-log:import-legacy')->assertExitCode(0);
            $this->artisan('audit-log:import-legacy')->assertExitCode(0); // idempotent

            $records = $this->records();
            $this->assertCount(1, $records);
            $this->assertSame('saved', $records->first()->action);
            $this->assertSame('Home', $records->first()->subject_title);
            $this->assertTrue($records->first()->isLegacy());
        } finally {
            File::delete($file);
        }
    }
}
