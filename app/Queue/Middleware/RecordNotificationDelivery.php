<?php

namespace App\Queue\Middleware;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Closure;
use Illuminate\Support\Facades\DB;

class RecordNotificationDelivery
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $channel,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->sent_at !== null) {
            return;
        }

        NotificationDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => NotificationDeliveryStatus::Pending->value,
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_attempted_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->channel === NotificationChannel::Database->value
            && DB::table('notifications')->where('id', $this->deliveryId)->exists()) {
            $this->markSent();

            return;
        }

        $next($job);
        $this->markSent();
    }

    private function markSent(): void
    {
        NotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('sent_at')
            ->update([
                'status' => NotificationDeliveryStatus::Sent->value,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
