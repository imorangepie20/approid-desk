<x-layouts::app title="월 사용내역">
    @php($inputClass = 'w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white')
    <div class="mx-auto w-full max-w-7xl space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">월 사용내역</h1>
            <p class="font-mono text-sm text-zinc-500">{{ $selectedMonth }}</p>
        </header>
        @if ($errors->any())
            <div role="alert" class="text-sm text-red-600 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="GET" action="{{ route('usage.index') }}" class="flex flex-col gap-3 border-y border-zinc-200 py-4 dark:border-zinc-700 sm:flex-row sm:items-end">
            <label class="grid min-w-0 gap-2 text-sm sm:w-48">기준 월
                <input type="month" name="month" required value="{{ $selectedMonth }}" class="{{ $inputClass }}" />
            </label>
            @if ($isOperator)
                <label class="grid min-w-0 gap-2 text-sm sm:w-72">고객사
                    <select name="company_id" class="{{ $inputClass }}">
                        <option value="">전체 고객사</option>
                        @foreach ($companies as $company)<option value="{{ $company->id }}" @selected($selectedCompany === $company->id)>{{ $company->name }}</option>@endforeach
                    </select>
                </label>
            @endif
            <flux:button type="submit" icon="funnel">조회</flux:button>
        </form>
        @if ($months->isEmpty())
            <p class="py-12 text-center text-sm text-zinc-500" data-test="usage-empty">선택한 월에 등록된 계약시간이 없습니다.</p>
        @else
            <div class="hidden xl:block">
                <table class="w-full table-fixed text-left text-sm" data-test="usage-month-table">
                    <caption class="sr-only">{{ $selectedMonth }} 계약별 시간 현황</caption>
                    <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                        <tr><th scope="col" class="w-[28%] py-3 pr-4">고객사 · 계약</th><th scope="col" class="px-2 py-3">상태</th><th scope="col" class="px-2 py-3 text-right">제공</th><th scope="col" class="px-2 py-3 text-right">고객 차감</th><th scope="col" class="px-2 py-3 text-right">예약 중</th><th scope="col" class="py-3 pl-2 text-right">사용 가능</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($months as $month)
                            @php($totals = $totalsByMonth[$month->id])
                            <tr data-test="usage-month-row-{{ $month->id }}">
                                <th scope="row" class="break-words py-4 pr-4 font-normal">
                                    <a href="{{ route('usage.show', $month) }}" class="font-semibold text-cyan-700 dark:text-cyan-300">{{ $month->serviceContract->company->name }}</a>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $month->serviceContract->type->label() }} · 계약 #{{ $month->service_contract_id }}</p>
                                </th>
                                <td class="px-2 py-4">{{ $month->status === 'closed' ? '마감' : '진행 중' }}</td>
                                @foreach (['provided', 'net_usage', 'remaining_reserved', 'available'] as $key)
                                    <td class="break-words px-2 py-4 text-right font-mono {{ $key === 'available' ? 'font-semibold text-emerald-700 dark:text-emerald-300' : '' }}">{{ number_format($totals[$key]) }}분</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700 xl:hidden" data-test="usage-month-mobile">
                @foreach ($months as $month)
                    @php($totals = $totalsByMonth[$month->id])
                    <article class="space-y-3 py-4" data-test="usage-month-card-{{ $month->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><a href="{{ route('usage.show', $month) }}" class="break-words font-semibold text-cyan-700 dark:text-cyan-300">{{ $month->serviceContract->company->name }}</a><p class="mt-1 text-xs text-zinc-500">{{ $month->serviceContract->type->label() }} · 계약 #{{ $month->service_contract_id }}</p></div>
                            <span class="shrink-0 text-xs">{{ $month->status === 'closed' ? '마감' : '진행 중' }}</span>
                        </div>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            @foreach (['provided' => '제공', 'net_usage' => '고객 차감', 'remaining_reserved' => '예약 중', 'available' => '사용 가능'] as $key => $label)
                                <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words font-mono {{ $key === 'available' ? 'font-semibold text-emerald-700 dark:text-emerald-300' : '' }}">{{ number_format($totals[$key]) }}분</dd></div>
                            @endforeach
                        </dl>
                    </article>
                @endforeach
            </div>
            {{ $months->links() }}
        @endif
    </div>
</x-layouts::app>
