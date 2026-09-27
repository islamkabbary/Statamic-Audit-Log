<?php

namespace IslamKabbary\AuditLog\Tests;

use Illuminate\Support\Facades\File;
use IslamKabbary\AuditLog\Audit\AuditLogStore;
use IslamKabbary\AuditLog\Audit\AuditRecord;
use IslamKabbary\AuditLog\ServiceProvider;
use Statamic\Testing\AddonTestCase;

/**
 * Boots Statamic with the addon against throwaway content under tests/__fixtures__ (git-ignored,
 * wiped per test). Statamic's AddonTestCase exists from 5.x, so the suite runs on Statamic 5/6;
 * Statamic 4 is covered by the host apps that run it.
 */
abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        $this->wipeFixtures();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->wipeFixtures();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('audit-log.path', $this->fixtures('audit'));
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('cache.default', 'array');
    }

    protected function fixtures(string $path = ''): string
    {
        return __DIR__.'/__fixtures__'.($path === '' ? '' : '/'.$path);
    }

    /** @return \Illuminate\Support\Collection<int, AuditRecord> newest first */
    protected function records()
    {
        return app(AuditLogStore::class)->records();
    }

    protected function latest(): ?AuditRecord
    {
        return $this->records()->first();
    }

    private function wipeFixtures(): void
    {
        if (is_dir($this->fixtures())) {
            File::deleteDirectory($this->fixtures());
        }
    }
}
