<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateAttachmentLink;
use App\Actions\CreateEstimateVersion;
use App\Actions\SubmitEstimateVersion;
use App\Enums\WorkDifficulty;
use App\Models\Attachment;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function draft(User $operator, WorkRequest $request): EstimateVersion
    {
        if (! PricingRule::query()->exists()) {
            PricingRule::factory()->create();
        }
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 30, today(), '첨부 연결 검증');

        return (new CreateEstimateVersion)->handle($operator, $request, $assessment, '포함', '제외', today(), today()->startOfMonth());
    }

    private function comment(WorkRequest $request): WorkRequestComment
    {
        return WorkRequestComment::factory()->create(['company_id' => $request->company_id,
            'work_request_id' => $request->id, 'author_id' => $request->submitted_by]);
    }

    public function test_request_comment_and_estimate_links_preserve_parent_identity_and_relations(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $comment = $this->comment($request);
        $draft = $this->draft($operator, $request);
        $action = new CreateAttachmentLink;
        $direct = $action->handle($request->submitter, $request);
        $reply = $action->handle($request->submitter, $comment);
        $quote = $action->handle($operator, $draft);
        $this->assertSame($request->company_id, $direct->company_id);
        $this->assertNull($direct->work_request_comment_id);
        $this->assertNull($direct->estimate_version_id);
        $this->assertSame($comment->id, $reply->work_request_comment_id);
        $this->assertSame($draft->id, $quote->estimate_version_id);
        $this->assertTrue($direct->company->is($request->company));
        $this->assertTrue($reply->workRequest->is($request));
        $this->assertTrue($reply->workRequestComment->is($comment));
        $this->assertTrue($quote->estimateVersion->is($draft));
        $this->assertSame([$direct->id, $reply->id, $quote->id], $request->attachments()->orderBy('id')->pluck('id')->all());
        $this->assertSame($reply->id, $comment->attachments()->sole()->id);
        $this->assertSame($quote->id, $draft->attachments()->sole()->id);
    }

    public function test_customer_visibility_excludes_foreign_and_unsubmitted_estimate_links(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $direct = (new CreateAttachmentLink)->handle($operator, $request);
        $draft = $this->draft($operator, $request);
        $hidden = (new CreateAttachmentLink)->handle($operator, $draft);
        $foreign = (new CreateAttachmentLink)->handle($operator, WorkRequest::factory()->create());
        foreach ([$request->submitter, User::factory()->customerAdmin()->for($request->company)->create()] as $customer) {
            $this->assertSame([$direct->id], Attachment::visibleTo($customer)->pluck('id')->all());
            $this->assertFalse(Gate::forUser($customer)->allows('view', $hidden));
            $this->assertFalse(Gate::forUser($customer)->allows('view', $foreign));
            $this->assertTrue(Gate::forUser($customer)->allows('view', $direct));
        }
        $this->assertSame(3, Attachment::visibleTo($operator)->count());
        (new SubmitEstimateVersion)->handle($operator, $draft);
        $this->assertSame([$direct->id, $hidden->id], $request->attachments()->visibleTo($request->submitter)->orderBy('id')->pluck('id')->all());
        $this->assertTrue(Gate::forUser($request->submitter)->allows('view', $hidden->fresh()));
        $this->assertSame(0, Attachment::visibleTo(User::factory()->operator()->inactive()->create())->count());
        $request->company->update(['status' => 'inactive']);
        $this->assertSame(0, Attachment::visibleTo($request->submitter->fresh())->count());
    }

    public function test_create_reloads_actor_and_parent_and_denies_unauthorized_targets(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $comment = $this->comment($request);
        $draft = $this->draft($operator, $request);
        $otherCustomer = User::factory()->customerAdmin()->for($request->company)->create();
        foreach ([[$otherCustomer, $comment], [$request->submitter, $draft],
            [User::factory()->customerUser()->create(), $request]] as [$actor, $target]) {
            try {
                (new CreateAttachmentLink)->handle($actor, $target);
                $this->fail('Unauthorized link accepted');
            } catch (AuthorizationException) {
                $this->assertSame(0, Attachment::count());
            }
        }
        (new SubmitEstimateVersion)->handle($operator, $draft);
        try {
            (new CreateAttachmentLink)->handle($operator, $draft);
            $this->fail('Stale submitted draft accepted');
        } catch (AuthorizationException) {
            $this->assertSame(0, Attachment::count());
        }
        User::whereKey($operator->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        (new CreateAttachmentLink)->handle($operator, $request);
    }

    /** @return array<string, array{string}> */
    public static function invalidLinks(): array
    {
        return ['company' => ['company'], 'comment' => ['comment'], 'estimate' => ['estimate'],
            'both' => ['both'], 'missing request' => ['missing request'],
            'missing comment' => ['missing comment'], 'missing estimate' => ['missing estimate']];
    }

    #[DataProvider('invalidLinks')]
    public function test_database_rejects_invalid_parent_links(string $case): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $other = WorkRequest::factory()->for($request->company)->create();
        $comment = $this->comment($request);
        $draft = $this->draft($operator, $request);
        $row = ['company_id' => $request->company_id, 'work_request_id' => $request->id];
        $row = array_replace($row, match ($case) {
            'company' => ['company_id' => WorkRequest::factory()->create()->company_id],
            'comment' => ['work_request_comment_id' => $this->comment($other)->id],
            'estimate' => ['estimate_version_id' => $this->draft($operator, $other)->id],
            'both' => ['work_request_comment_id' => $comment->id, 'estimate_version_id' => $draft->id],
            'missing request' => ['work_request_id' => 999999],
            'missing comment' => ['work_request_comment_id' => 999999],
            default => ['estimate_version_id' => 999999],
        });
        $this->expectException(QueryException::class);
        DB::table('attachments')->insert($row);
    }

    public function test_link_identity_cannot_be_reassigned_through_model_or_sql(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $comment = $this->comment($request);
        try {
            $link->forceFill(['work_request_comment_id' => $comment->id])->save();
            $this->fail('Model reassignment accepted');
        } catch (LogicException) {
            $this->assertNull($link->fresh()->work_request_comment_id);
        }
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $link->id)->update(['work_request_comment_id' => $comment->id]);
    }

    public function test_submitted_estimate_blocks_direct_insert_and_delete_but_metadata_remains_updatable(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $draft = $this->draft($operator, $request);
        $link = (new CreateAttachmentLink)->handle($operator, $draft);
        (new SubmitEstimateVersion)->handle($operator, $draft);
        $link->touch();
        foreach (['insert', 'delete'] as $operation) {
            try {
                $operation === 'insert'
                    ? DB::table('attachments')->insert(['company_id' => $request->company_id, 'work_request_id' => $request->id, 'estimate_version_id' => $draft->id])
                    : DB::table('attachments')->where('id', $link->id)->delete();
                $this->fail('Submitted estimate attachment mutated');
            } catch (QueryException) {
                $this->assertSame(1, Attachment::count());
            }
        }
    }

    public function test_linked_parent_cannot_be_deleted_or_moved_to_another_request(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $other = WorkRequest::factory()->for($request->company)->create();
        $comment = $this->comment($request);
        $draft = $this->draft($operator, $request);
        (new CreateAttachmentLink)->handle($operator, $request);
        (new CreateAttachmentLink)->handle($request->submitter, $comment);
        (new CreateAttachmentLink)->handle($operator, $draft);
        foreach ([$request, $comment, $draft] as $parent) {
            try {
                $parent->newQuery()->whereKey($parent->id)->delete();
                $this->fail('Linked parent deleted');
            } catch (QueryException) {
                $this->assertTrue($parent->newQuery()->whereKey($parent->id)->exists());
            }
        }
        $this->expectException(QueryException::class);
        DB::table('work_request_comments')->where('id', $comment->id)->update(['work_request_id' => $other->id]);
    }
}
