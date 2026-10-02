<?php

namespace Tests\Unit\Enums;

use App\Enums\WorkRequestActivityType;
use PHPUnit\Framework\TestCase;

class WorkRequestActivityTypeTest extends TestCase
{
    public function test_activity_types_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame(
            ['request_created', 'request_updated', 'assignee_changed', 'comment_created', 'status_changed'],
            array_column(WorkRequestActivityType::cases(), 'value'),
        );

        $this->assertSame('요청 등록', WorkRequestActivityType::RequestCreated->label());
        $this->assertSame('요청 수정', WorkRequestActivityType::RequestUpdated->label());
        $this->assertSame('담당자 변경', WorkRequestActivityType::AssigneeChanged->label());
        $this->assertSame('댓글 등록', WorkRequestActivityType::CommentCreated->label());
        $this->assertSame('상태 변경', WorkRequestActivityType::StatusChanged->label());
    }
}
