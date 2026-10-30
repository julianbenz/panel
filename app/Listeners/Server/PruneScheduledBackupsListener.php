<?php

namespace App\Listeners\Server;

use App\Events\Server\BackupCompleted;
use App\Models\Backup;
use App\Services\Backups\DeleteBackupService;
use Throwable;

class PruneScheduledBackupsListener
{
    public function __construct(private readonly DeleteBackupService $deleteBackupService) {}

    /**
     * Applies the grandfather-father-son rotation to the scheduled backups of the server.
     */
    public function handle(BackupCompleted $event): void
    {
        $backup = $event->backup;
        if (!config('backups.gfs.enabled') || !$backup->is_scheduled || !$backup->is_successful) {
            return;
        }

        $backups = $backup->server->backups()
            ->where('is_scheduled', true)
            ->where('is_successful', true)
            ->where('is_locked', false)
            ->latest()
            ->get();

        // Backups are sorted newest first, so the first backup of each group is the newest of that day/week.
        $keep = $backups->take(config('backups.gfs.sons'))
            ->concat($backups->groupBy(fn (Backup $backup) => $backup->created_at->format('Y-m-d'))->take(config('backups.gfs.fathers'))->map->first())
            ->concat($backups->groupBy(fn (Backup $backup) => $backup->created_at->format('o-W'))->take(config('backups.gfs.grandfathers'))->map->first())
            ->pluck('id');

        foreach ($backups->whereNotIn('id', $keep) as $prunable) {
            try {
                $this->deleteBackupService->handle($prunable);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
