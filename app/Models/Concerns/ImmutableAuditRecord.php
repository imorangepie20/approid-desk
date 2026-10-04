<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableAuditRecord
{
    protected static function bootImmutableAuditRecord(): void
    {
        static::updating(fn () => throw new LogicException('감사 기록은 수정할 수 없습니다.'));
        static::deleting(fn () => throw new LogicException('감사 기록은 삭제할 수 없습니다.'));
    }
}
