<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Membership;
use App\Models\Reminder;
use App\Support\ShanghaiTime;
use Illuminate\Support\Facades\Log;

class ReminderPushDispatcher
{
    public function __construct(protected UniPushService $push) {}

    /**
     * @return array{scanned:int, sent:int, skipped:int, failed:int}
     */
    public function dispatchDue(): array
    {
        $stats = ['scanned' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->push->enabled()) {
            Log::info('reminders.dispatch_skipped_push_disabled');

            return $stats;
        }

        $now = now(ShanghaiTime::TZ);

        Reminder::query()
            ->where('status', 'pending')
            ->whereNull('pushed_at')
            ->where('due_at', '<=', $now)
            ->where('push_attempts', '<', 5)
            ->orderBy('due_at')
            ->limit(100)
            ->get()
            ->each(function (Reminder $reminder) use (&$stats) {
                $stats['scanned']++;
                $result = $this->dispatchOne($reminder);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            });

        return $stats;
    }

    /** @return 'sent'|'skipped'|'failed' */
    public function dispatchOne(Reminder $reminder): string
    {
        $userIds = Membership::where('workspace_id', $reminder->workspace_id)
            ->pluck('user_id')
            ->all();

        if ($reminder->created_by) {
            $userIds[] = $reminder->created_by;
        }
        $userIds = array_values(array_unique(array_filter($userIds)));

        $cids = DeviceToken::whereIn('user_id', $userIds)
            ->pluck('push_client_id')
            ->all();

        $reminder->push_attempts = (int) $reminder->push_attempts + 1;

        if (! $cids) {
            $reminder->pushed_at = now();
            $reminder->save();

            return 'skipped';
        }

        $ok = $this->push->sendToClients(
            $cids,
            '提醒',
            $reminder->title ?: '你有一条到期提醒',
            [
                'type' => 'reminder',
                'reminder_id' => $reminder->id,
                'workspace_id' => $reminder->workspace_id,
                'path' => '/pages/reminders/index',
            ]
        );

        if (! $ok) {
            $reminder->save();

            return 'failed';
        }

        $this->markPushed($reminder);

        return 'sent';
    }

    protected function markPushed(Reminder $reminder): void
    {
        $freq = data_get($reminder->recurrence, 'freq');

        if ($freq === 'yearly' && $reminder->due_at) {
            $reminder->due_at = $reminder->due_at->copy()->addYear();
            $reminder->pushed_at = null;
            $reminder->push_attempts = 0;
            $reminder->status = 'pending';
        } else {
            $reminder->pushed_at = now();
        }

        $reminder->save();
    }
}
