<?php

namespace Tests\Unit\Enums;

use App\Enums\ProjectStatus;
use PHPUnit\Framework\TestCase;

class ProjectStatusTest extends TestCase
{
    public function test_statuses_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame(
            ['active', 'on_hold', 'archived'],
            array_column(ProjectStatus::cases(), 'value'),
        );

        $this->assertSame('활성', ProjectStatus::Active->label());
        $this->assertSame('보류', ProjectStatus::OnHold->label());
        $this->assertSame('보관', ProjectStatus::Archived->label());
    }
}
