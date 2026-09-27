<?php

namespace IslamKabbary\AuditLog;

use Illuminate\Console\Scheduling\Schedule;
use IslamKabbary\AuditLog\Audit\AuditDiffer;
use IslamKabbary\AuditLog\Audit\AuditSnapshots;
use IslamKabbary\AuditLog\Console\ImportLegacyAdminLog;
use IslamKabbary\AuditLog\Console\PruneAuditLog;
use IslamKabbary\AuditLog\Listeners\AuditLogSubscriber;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

/**
 * Everything the audit log needs, so a host app only has to `composer require` it — the same
 * on the Kernel/EventServiceProvider layout (Laravel 10) and bootstrap/app.php (11+).
 */
class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $subscribe = [
        AuditLogSubscriber::class,
    ];

    protected $commands = [
        PruneAuditLog::class,
        ImportLegacyAdminLog::class,
    ];

    protected $viewNamespace = 'audit-log';

    // Merged under the "audit-log" key in register() instead: Statamic would name it after the
    // package slug, and the config must exist before boot for the log channel below.
    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/audit-log.php', 'audit-log');

        // Request-scoped audit state (before-snapshots, created flags, duplicate guard).
        $this->app->singleton(AuditSnapshots::class);
        $this->app->singleton(AuditDiffer::class);

        $this->registerSummaryChannel();
    }

    public function bootAddon()
    {
        // Super admins always pass; other roles need this permission.
        Permission::extend(function () {
            Permission::register('view audit log')->label('View Audit Log');
        });

        Nav::extend(function ($nav) {
            $nav->tools('Audit Log')
                ->route('audit-log.index')
                ->icon('history')
                ->can('view audit log');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit-log.php' => config_path('audit-log.php'),
            ], 'audit-log-config');
        }
    }

    protected function schedule(Schedule $schedule)
    {
        if (config('audit-log.schedule_prune', true)) {
            $schedule->command('audit-log:prune')
                ->dailyAt(config('audit-log.prune_at', '03:30'))
                ->withoutOverlapping();
        }
    }

    private function registerSummaryChannel(): void
    {
        $channel = config('audit-log.summary_channel', 'audit-log');

        if (config("logging.channels.{$channel}") !== null) {
            return;
        }

        config(["logging.channels.{$channel}" => [
            'driver' => 'daily',
            'path' => storage_path("logs/{$channel}.log"),
            'level' => 'info',
            'days' => (int) config('audit-log.retention_days', 365),
        ]]);
    }
}
