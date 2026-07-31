<?php

namespace App\Console\Commands;

use App\Services\ReminderPushDispatcher;
use Illuminate\Console\Command;

class DispatchDueReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = '扫描到期提醒并通过 UniPush 推送';

    public function handle(ReminderPushDispatcher $dispatcher): int
    {
        $stats = $dispatcher->dispatchDue();
        $this->info(sprintf(
            'scanned=%d sent=%d skipped=%d failed=%d',
            $stats['scanned'],
            $stats['sent'],
            $stats['skipped'],
            $stats['failed']
        ));

        return self::SUCCESS;
    }
}
