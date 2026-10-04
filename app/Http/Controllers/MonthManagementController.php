<?php

namespace App\Http\Controllers;

use App\Actions\AdjustContractMonth;
use App\Actions\CloseContractMonth;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\MonthAdjustment;
use App\Models\MonthClosure;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\ContractMonthTotals;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MonthManagementController extends Controller
{
    public function show(Request $request, ContractMonth $contractMonth): View
    {
        $this->actor($request, $contractMonth);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $contractMonth->load('serviceContract.company');
        $drafts = WorkLog::query()->where('company_id', $contractMonth->company_id)
            ->whereHas('workRequest', fn ($query) => $query->where('service_contract_id', $contractMonth->service_contract_id))
            ->whereBetween('worked_on', [$contractMonth->month->toDateString(), $contractMonth->month->copy()->endOfMonth()->toDateString()])
            ->where('status', WorkLogStatus::Draft)->count();
        $closure = MonthClosure::query()->where('contract_month_id', $contractMonth->id)->first();
        $adjustments = MonthAdjustment::query()->where('contract_month_id', $contractMonth->id)
            ->orderByDesc('id')->paginate(20);
        $actors = User::query()->whereIn('id', $adjustments->getCollection()->pluck('approved_by')->push($closure?->closed_by)->filter())
            ->pluck('name', 'id');

        return view('usage.manage', [
            'contractMonth' => $contractMonth, 'draftCount' => $drafts,
            'totals' => (new ContractMonthTotals)->calculate($contractMonth->entries()->get()),
            'ended' => $contractMonth->month->copy()->endOfMonth()->lt(today()),
            'closure' => $closure, 'adjustments' => $adjustments, 'actors' => $actors,
        ]);
    }

    public function close(Request $request, ContractMonth $contractMonth): RedirectResponse
    {
        $actor = $this->actor($request, $contractMonth);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        (new CloseContractMonth)->handle($actor, $contractMonth);

        return redirect()->route('usage.manage', $contractMonth)->with('success', '월 마감을 확인했습니다.');
    }

    public function createAdjustment(Request $request, ContractMonth $contractMonth, TimeLedgerEntry $entry): View
    {
        $this->actor($request, $contractMonth);
        $this->related($contractMonth, $entry);
        $contractMonth->load('serviceContract.company');

        return view('usage.adjust', ['contractMonth' => $contractMonth, 'entry' => $entry,
            'requestKey' => (string) Str::uuid(),
            'totals' => (new ContractMonthTotals)->calculate($contractMonth->entries()->get())]);
    }

    public function storeAdjustment(Request $request, ContractMonth $contractMonth, TimeLedgerEntry $entry): RedirectResponse
    {
        $actor = $this->actor($request, $contractMonth);
        $this->related($contractMonth, $entry);
        $data = $request->validate([
            'type' => ['required', Rule::in([TimeLedgerType::AdjustIncrease->value, TimeLedgerType::AdjustDecrease->value])],
            'minutes' => ['required', 'integer', 'between:1,10000000'],
            'reason' => ['required', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
        ]);
        (new AdjustContractMonth)->handle($actor, $contractMonth, $entry, TimeLedgerType::from($data['type']),
            (int) $data['minutes'], $data['reason'], $data['idempotency_key']);

        return redirect()->route('usage.manage', $contractMonth)->with('success', '시간 조정을 확인했습니다.');
    }

    private function actor(Request $request, ContractMonth $month): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $actor = User::query()->findOrFail($user->id);
        Gate::forUser($actor)->authorize('close', $month);

        return $actor;
    }

    private function related(ContractMonth $month, TimeLedgerEntry $entry): void
    {
        abort_unless($entry->contract_month_id === $month->id && $entry->company_id === $month->company_id, 404);
    }
}
