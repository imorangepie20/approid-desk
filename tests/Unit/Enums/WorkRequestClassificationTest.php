<?php

namespace Tests\Unit\Enums;

use App\Enums\IntakeChannel;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestType;
use PHPUnit\Framework\TestCase;

class WorkRequestClassificationTest extends TestCase
{
    public function test_types_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame(
            ['feature', 'bug_fix', 'maintenance', 'consultation', 'other'],
            array_column(WorkRequestType::cases(), 'value'),
        );

        $this->assertSame('기능 개발', WorkRequestType::Feature->label());
        $this->assertSame('버그 수정', WorkRequestType::BugFix->label());
        $this->assertSame('유지보수', WorkRequestType::Maintenance->label());
        $this->assertSame('상담', WorkRequestType::Consultation->label());
        $this->assertSame('기타', WorkRequestType::Other->label());
    }

    public function test_priorities_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame(
            ['low', 'normal', 'high'],
            array_column(WorkRequestPriority::cases(), 'value'),
        );

        $this->assertSame('낮음', WorkRequestPriority::Low->label());
        $this->assertSame('보통', WorkRequestPriority::Normal->label());
        $this->assertSame('높음', WorkRequestPriority::High->label());
    }

    public function test_intake_channels_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame(
            ['web', 'phone', 'email', 'messenger', 'other'],
            array_column(IntakeChannel::cases(), 'value'),
        );

        $this->assertSame('웹사이트', IntakeChannel::Web->label());
        $this->assertSame('전화', IntakeChannel::Phone->label());
        $this->assertSame('이메일', IntakeChannel::Email->label());
        $this->assertSame('메신저', IntakeChannel::Messenger->label());
        $this->assertSame('기타', IntakeChannel::Other->label());
    }
}
