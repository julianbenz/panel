<?php

namespace App\Tests\Integration\Listeners;

use App\Events\Server\BackupCompleted;
use App\Listeners\Server\PruneScheduledBackupsListener;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Services\Backups\DeleteBackupService;
use App\Tests\Integration\IntegrationTestCase;
use Carbon\CarbonImmutable;

class PruneScheduledBackupsListenerTest extends IntegrationTestCase
{
    private Server $server;

    private BackupHost $backupHost;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('backups.gfs.enabled', true);

        $this->server = $this->createServerModel();
        $this->backupHost = BackupHost::factory()->create();

        $this->mock(DeleteBackupService::class)
            ->shouldReceive('handle')
            ->andReturnUsing(fn (Backup $backup) => $backup->delete());
    }

    public function test_grandfather_father_son_rotation_is_applied(): void
    {
        // Wednesday, the current ISO week started on Monday 2026-10-26.
        $now = CarbonImmutable::create(2026, 10, 28, 12);

        // Sons: a backup every 15 minutes during the last 3 hours.
        for ($i = 0; $i <= 12; $i++) {
            $this->createBackup($now->subMinutes($i * 15));
        }

        // One backup per day during the last 30 days.
        for ($i = 1; $i <= 30; $i++) {
            $this->createBackup($now->subDays($i)->setTime(6, 0));
        }

        $manual = $this->createBackup($now->subDays(40), ['is_scheduled' => false]);
        $locked = $this->createBackup($now->subDays(40), ['is_locked' => true]);

        $this->app->make(PruneScheduledBackupsListener::class)->handle(new BackupCompleted($this->server->backups()->latest()->first()));

        $expected = collect(range(0, 9))->map(fn ($i) => $now->subMinutes($i * 15)) // sons
            ->concat(collect(range(1, 6))->map(fn ($i) => $now->subDays($i)->setTime(6, 0))) // fathers (today is covered by the sons)
            ->push($now->setDate(2026, 10, 18)->setTime(6, 0)) // grandfather (newer weeks are covered by the fathers)
            ->push($manual->created_at, $locked->created_at)
            ->map(fn ($date) => $date->toDateTimeString())
            ->sort()
            ->values();

        $this->assertSame($expected->all(), $this->server->backups()->orderBy('created_at')->pluck('created_at')->map->toDateTimeString()->all());
    }

    public function test_nothing_is_pruned_when_disabled_or_not_applicable(): void
    {
        $now = CarbonImmutable::now();
        for ($i = 0; $i < 20; $i++) {
            $this->createBackup($now->subMinutes($i * 15));
        }

        $failed = $this->createBackup($now, ['is_successful' => false]);
        $manual = $this->createBackup($now, ['is_scheduled' => false]);
        $listener = $this->app->make(PruneScheduledBackupsListener::class);

        $listener->handle(new BackupCompleted($failed));
        $listener->handle(new BackupCompleted($manual));

        config()->set('backups.gfs.enabled', false);
        $listener->handle(new BackupCompleted($this->server->backups()->where('is_scheduled', true)->where('is_successful', true)->first()));

        $this->assertSame(22, $this->server->backups()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBackup(CarbonImmutable $createdAt, array $attributes = []): Backup
    {
        return Backup::factory()->create(array_merge([
            'server_id' => $this->server->id,
            'backup_host_id' => $this->backupHost->id,
            'is_scheduled' => true,
            'created_at' => $createdAt,
            'completed_at' => $createdAt,
        ], $attributes));
    }
}
