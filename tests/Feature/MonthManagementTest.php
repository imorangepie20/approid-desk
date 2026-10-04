<?php

namespace Tests\Feature;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Models\MonthAdjustment;
use App\Models\MonthClosure;
use App\Models\ServiceContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthManagementTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    public function test_operator_closes_once_and_adjusts_closed_month_without_changing_snapshot(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $entry = $month->entries()->sole();
        $this->travelTo($month->month->copy()->addMonth());
        $this->actingAs($operator)->get(route('usage.manage', $month))->assertOk()->assertSee('월 마감 확정');
        $this->post(route('usage.close', $month))->assertSessionHasErrors('confirmed');
        foreach ([1, 2] as $attempt) {
            $this->post(route('usage.close', $month), ['confirmed' => '1'])->assertRedirect(route('usage.manage', $month));
        }
        $this->assertDatabaseCount('month_closures', 1);
        $this->assertSame('closed', $month->fresh()->status);
        $this->get(route('usage.adjust.create', [$month, $entry]))->assertOk()->assertSee('마감 월');
        $payload = ['type' => 'adjust_decrease', 'minutes' => 30, 'reason' => '<script>audit</script>',
            'idempotency_key' => (string) Str::uuid(), 'confirmed' => '1'];
        foreach ([1, 2] as $attempt) {
            $this->post(route('usage.adjust.store', [$month, $entry]), $payload)->assertRedirect(route('usage.manage', $month));
        }
        $this->assertDatabaseCount('month_adjustments', 1);
        $this->assertSame(2, $month->entries()->count());
        $this->assertSame(100, MonthClosure::query()->sole()->totals['available']);
        $this->assertSame($operator->id, MonthAdjustment::query()->sole()->approved_by);
        $this->get(route('usage.manage', $month))->assertOk()->assertSee('70분')->assertSee('100분')
            ->assertSee('&lt;script&gt;audit&lt;/script&gt;', false)->assertDontSee('<script>audit</script>', false)
            ->assertDontSee('월 마감 확정')->assertSee($operator->name);
        $this->post(route('usage.adjust.store', [$month, $entry]), array_replace($payload, ['minutes' => 20]))
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_customer_guest_and_stale_operator_cannot_access_or_mutate_management(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $entry = $month->entries()->sole();
        $show = route('usage.manage', $month);
        $create = route('usage.adjust.create', [$month, $entry]);
        $this->get($show)->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('usage.show', $month))->assertDontSee('월 마감·조정');
        foreach ([$admin, User::factory()->customerUser()->for($admin->company)->create(), $operator] as $actor) {
            if ($actor->id === $operator->id) {
                User::query()->whereKey($actor->id)->update(['is_active' => false]);
            }
            $this->actingAs($actor)->get($show)->assertForbidden();
            $this->get($create)->assertForbidden();
            $this->post(route('usage.close', $month), ['confirmed' => 1])->assertForbidden();
            $this->post(route('usage.adjust.store', [$month, $entry]), [])->assertForbidden();
        }
        $this->assertDatabaseCount('month_closures', 0);
        $this->assertDatabaseCount('month_adjustments', 0);
    }

    public function test_current_month_and_unconfirmed_work_block_close(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $this->actingAs($operator)->get(route('usage.manage', $month))->assertOk()->assertDontSee('월 마감 확정');
        $this->post(route('usage.close', $month), ['confirmed' => 1])->assertSessionHasErrors('month');
        (new SaveWorkLogDraft)->handle($operator, $request, ['worked_on' => today()->toDateString(),
            'minutes' => 10, 'description' => '초안', 'is_billable' => true]);
        $this->travelTo($month->month->copy()->addMonth());
        $this->get(route('usage.manage', $month))->assertSee('미확정 작업기록 1건')->assertDontSee('월 마감 확정');
        $this->post(route('usage.close', $month), ['confirmed' => 1])->assertSessionHasErrors('work_logs');
        $this->assertDatabaseCount('month_closures', 0);
    }

    public function test_outstanding_reservations_block_http_close(): void
    {
        [, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'management test');
        $this->travelTo($month->month->copy()->addMonth());
        $this->actingAs($operator)->get(route('usage.manage', $month))->assertOk()
            ->assertSee('남은 예약 60분')->assertDontSee('월 마감 확정');
        $this->post(route('usage.close', $month), ['confirmed' => 1])->assertSessionHasErrors('ledger');
        $this->assertDatabaseCount('month_closures', 0);
        $this->assertSame('open', $month->fresh()->status);
    }

    public function test_foreign_entry_and_invalid_adjustment_are_rejected_and_failed_input_is_retained(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $otherMonth = (new ProvideContractMonth)->handle($operator,
            ServiceContract::factory()->signed()->create(), today()->startOfMonth()->toDateString(), 100);
        $foreign = $otherMonth->entries()->sole();
        $this->actingAs($operator)->get(route('usage.adjust.create', [$month, $foreign]))->assertNotFound();
        $this->post(route('usage.adjust.store', [$month, $foreign]), [])->assertNotFound();
        $entry = $month->entries()->sole();
        $create = route('usage.adjust.create', [$month, $entry]);
        $store = route('usage.adjust.store', [$month, $entry]);
        $payload = ['type' => 'adjust_decrease', 'minutes' => 101, 'reason' => '잔액 확인',
            'idempotency_key' => (string) Str::uuid(), 'confirmed' => '1'];
        $this->from($create)->post($store, $payload)->assertRedirect($create)->assertSessionHasErrors('minutes')
            ->assertSessionHasInput('idempotency_key', $payload['idempotency_key']);
        $this->get($create)->assertSee($payload['idempotency_key'])->assertSee('잔액 확인');
        foreach (['type' => 'usage', 'minutes' => 1.5, 'reason' => ' ', 'idempotency_key' => 'invalid', 'confirmed' => '0'] as $field => $value) {
            $this->post($store, array_replace($payload, [$field => $value]))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('month_adjustments', 0);
    }

    public function test_superadmin_can_adjust_open_month_and_history_is_paginated(): void
    {
        [, , , $month] = $this->timeFixture();
        $actor = User::factory()->superAdmin()->create();
        $entry = $month->entries()->sole();
        for ($i = 0; $i < 21; $i++) {
            (new AdjustContractMonth)->handle($actor, $month, $entry, TimeLedgerType::AdjustIncrease, 1, '조정 '.$i, (string) Str::uuid());
        }
        $this->actingAs($actor)->get(route('usage.manage', $month))->assertOk()->assertSee('121분')
            ->assertSee('조정 20')->assertDontSee('data-test="adjustment-1"', false);
        $this->get(route('usage.manage', [$month, 'page' => 2]))->assertOk()->assertSee('조정 0')->assertSee('121분');
        $this->get(route('usage.manage', [$month, 'page' => 0]))->assertSessionHasErrors('page');
        $this->post(route('usage.adjust.store', [$month, $entry]), ['type' => 'adjust_increase', 'minutes' => 9,
            'reason' => '추가', 'idempotency_key' => (string) Str::uuid(), 'confirmed' => 1])->assertRedirect();
        $this->get(route('usage.manage', $month))->assertSee('130분');
    }
}
