<?php

namespace App\Services;

use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Throwable;

class NotificationDeliveryFailureRecorder
{
    public function record(string $deliveryId, Throwable $exception): void
    {
        NotificationDelivery::query()
            ->whereKey($deliveryId)
            ->whereNull('sent_at')
            ->update([
                'status' => NotificationDeliveryStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => NotificationDeliveryFailure::fromException($exception)->value,
                'updated_at' => now(),
            ]);
    }
}
