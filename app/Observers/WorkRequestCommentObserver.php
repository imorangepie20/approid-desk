<?php

namespace App\Observers;

use App\Enums\WorkRequestActivityType;
use App\Models\WorkRequestComment;

class WorkRequestCommentObserver
{
    public function created(WorkRequestComment $comment): void
    {
        $comment->workRequest->activities()->create([
            'company_id' => $comment->company_id,
            'actor_id' => $comment->author_id,
            'comment_id' => $comment->id,
            'type' => WorkRequestActivityType::CommentCreated,
            'summary' => '댓글이 등록되었습니다.',
            'before_values' => null,
            'after_values' => ['body' => $comment->body],
            'occurred_at' => now(),
        ]);
    }
}
