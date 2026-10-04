<?php

namespace App\Jobs;

use App\Actions\InspectAttachment;
use App\Models\Attachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $attachmentId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(InspectAttachment $inspect): void
    {
        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment !== null) {
            $inspect->handle($attachment);
        }
    }
}
