<x-layouts::auth :title="__('초대 수락')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('초대 수락')"
            :description="__(':company의 계정을 생성합니다.', ['company' => $invitation->company->name])"
        />

        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="text-zinc-500 dark:text-zinc-400">{{ __('초대 이메일') }}</div>
            <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $invitation->email }}</div>
        </div>

        <form method="POST" action="{{ route('invitations.accept.store', $token) }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="name"
                :label="__('이름')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('이름을 입력하세요')"
            />

            <flux:input
                name="password"
                :label="__('비밀번호')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('비밀번호')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('비밀번호 확인')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('비밀번호를 다시 입력하세요')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('계정 만들기') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
