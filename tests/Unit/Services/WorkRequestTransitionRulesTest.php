<?php

namespace Tests\Unit\Services;

use App\Enums\WorkRequestStatus as Status;
use App\Services\WorkRequestTransitionRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkRequestTransitionRulesTest extends TestCase
{
    public function test_transition_matrix_is_complete_for_every_status(): void
    {
        $expected = [
            'received' => ['estimating', 'on_hold', 'cancelled'],
            'estimating' => ['awaiting_approval', 'on_hold', 'cancelled'],
            'awaiting_approval' => ['queued', 'estimating', 'cancelled'],
            'queued' => ['in_progress', 'awaiting_approval', 'on_hold', 'cancelled'],
            'in_progress' => ['awaiting_review', 'awaiting_approval', 'queued', 'on_hold', 'cancelled'],
            'awaiting_review' => ['completed', 'in_progress', 'on_hold'],
            'completed' => ['in_progress'],
            'on_hold' => ['cancelled'],
            'cancelled' => [],
        ];
        $rules = new WorkRequestTransitionRules;
        $actual = [];

        foreach (Status::cases() as $status) {
            $actual[$status->value] = array_column($rules->destinations($status), 'value');
        }

        $this->assertSame($expected, $actual);
    }

    /** @return array<string, array{Status|null, list<Status>}> */
    public static function heldStates(): array
    {
        return [
            'missing history' => [null, [Status::Cancelled]],
            'received' => [Status::Received, [Status::Received, Status::Cancelled]],
            'estimating' => [Status::Estimating, [Status::Estimating, Status::Cancelled]],
            'queued' => [Status::Queued, [Status::Queued, Status::Cancelled]],
            'in progress' => [Status::InProgress, [Status::InProgress, Status::Cancelled]],
            'awaiting review' => [Status::AwaitingReview, [Status::AwaitingReview, Status::Cancelled]],
            'approval cannot be held' => [Status::AwaitingApproval, [Status::Cancelled]],
            'completed cannot be held' => [Status::Completed, [Status::Cancelled]],
            'nested hold is invalid' => [Status::OnHold, [Status::Cancelled]],
            'cancelled cannot be held' => [Status::Cancelled, [Status::Cancelled]],
        ];
    }

    /** @param list<Status> $expected */
    #[DataProvider('heldStates')]
    public function test_hold_only_resumes_a_status_that_can_enter_hold(?Status $heldFrom, array $expected): void
    {
        $this->assertSame($expected, (new WorkRequestTransitionRules)->destinations(Status::OnHold, $heldFrom));
    }

    public function test_reason_requirement_is_complete_for_every_allowed_transition(): void
    {
        $reasonRequired = [
            'received:on_hold', 'received:cancelled',
            'estimating:on_hold', 'estimating:cancelled',
            'awaiting_approval:estimating', 'awaiting_approval:cancelled',
            'queued:on_hold', 'queued:cancelled',
            'in_progress:queued', 'in_progress:on_hold', 'in_progress:cancelled',
            'awaiting_review:in_progress', 'awaiting_review:on_hold',
            'completed:in_progress',
            'on_hold:received', 'on_hold:cancelled',
        ];
        $rules = new WorkRequestTransitionRules;

        foreach (Status::cases() as $from) {
            $heldFrom = $from === Status::OnHold ? Status::Received : null;

            foreach ($rules->destinations($from, $heldFrom) as $to) {
                $key = $from->value.':'.$to->value;
                $this->assertSame(in_array($key, $reasonRequired, true), $rules->requiresReason($from, $to), $key);
            }
        }
    }
}
