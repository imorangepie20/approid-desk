<?php

namespace Tests\Feature\Models;

use App\Actions\TransitionWorkRequest;
use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkRequestActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_request_records_a_creation_activity(): void
    {
        $workRequest = WorkRequest::factory()->create();
        $activity = $workRequest->activities()->sole();

        $this->assertSame(WorkRequestActivityType::RequestCreated, $activity->type);
        $this->assertSame('요청이 등록되었습니다.', $activity->summary);
        $this->assertNull($activity->before_values);
        $this->assertSame($workRequest->title, $activity->after_values['title']);
        $this->assertTrue($activity->actor->is($workRequest->submitter));
    }

    public function test_updating_request_fields_records_before_and_after_values(): void
    {
        $operator = User::factory()->operator()->create();
        $workRequest = WorkRequest::factory()->create(['title' => '변경 전 제목']);
        $this->actingAs($operator);

        $workRequest->update([
            'title' => '변경 후 제목',
            'priority' => 'high',
        ]);

        $activity = $workRequest->activities()
            ->where('type', WorkRequestActivityType::RequestUpdated->value)
            ->sole();

        $this->assertSame(['title' => '변경 전 제목', 'priority' => 'normal'], $activity->before_values);
        $this->assertSame(['title' => '변경 후 제목', 'priority' => 'high'], $activity->after_values);
        $this->assertTrue($activity->actor->is($operator));
    }

    public function test_status_and_assignee_changes_have_separate_activity_rows(): void
    {
        $operator = User::factory()->operator()->create();
        $assignee = User::factory()->operator()->create();
        $workRequest = WorkRequest::factory()->create();
        $this->actingAs($operator);

        (new TransitionWorkRequest)->handle($operator, $workRequest, WorkRequestStatus::Estimating);
        $workRequest->update(['assigned_to' => $assignee->id]);

        $statusActivity = $workRequest->activities()
            ->where('type', WorkRequestActivityType::StatusChanged->value)
            ->sole();
        $assigneeActivity = $workRequest->activities()
            ->where('type', WorkRequestActivityType::AssigneeChanged->value)
            ->sole();

        $this->assertSame(['status' => WorkRequestStatus::Received->value], $statusActivity->before_values);
        $this->assertSame(WorkRequestStatus::Estimating->value, $statusActivity->after_values['status']);
        $this->assertSame(['assigned_to' => null], $assigneeActivity->before_values);
        $this->assertSame(['assigned_to' => $assignee->id], $assigneeActivity->after_values);
        $this->assertTrue($workRequest->fresh()->assignee->is($assignee));
    }

    public function test_creating_a_comment_records_a_comment_activity(): void
    {
        $workRequest = WorkRequest::factory()->create();
        $author = User::factory()->for($workRequest->company)->create();

        $comment = WorkRequestComment::factory()
            ->for($workRequest->company)
            ->for($workRequest)
            ->for($author, 'author')
            ->create(['body' => '요구사항을 확인했습니다.']);

        $activity = $workRequest->activities()
            ->where('type', WorkRequestActivityType::CommentCreated->value)
            ->sole();

        $this->assertTrue($comment->author->is($author));
        $this->assertTrue($activity->comment->is($comment));
        $this->assertTrue($activity->actor->is($author));
        $this->assertSame('댓글이 등록되었습니다.', $activity->summary);
        $this->assertSame(['body' => '요구사항을 확인했습니다.'], $activity->after_values);
    }

    public function test_comment_cannot_reference_a_request_from_another_company(): void
    {
        $company = Company::factory()->create();
        $anotherCompanyRequest = WorkRequest::factory()->create();
        $author = User::factory()->for($company)->create();

        $this->expectException(QueryException::class);

        WorkRequestComment::factory()
            ->for($company)
            ->for($anotherCompanyRequest)
            ->for($author, 'author')
            ->create();
    }

    public function test_request_comments_and_activities_are_scoped_to_their_request(): void
    {
        $workRequest = WorkRequest::factory()->create();
        $anotherRequest = WorkRequest::factory()->create();
        WorkRequestComment::factory()->for($workRequest)->for($workRequest->company)->create();
        WorkRequestComment::factory()->for($anotherRequest)->for($anotherRequest->company)->create();

        $this->assertCount(1, $workRequest->comments);
        $this->assertCount(2, $workRequest->activities);
        $this->assertTrue($workRequest->comments->first()->workRequest->is($workRequest));
        $this->assertTrue($workRequest->activities->every(
            fn ($activity): bool => $activity->workRequest->is($workRequest),
        ));
    }
}
