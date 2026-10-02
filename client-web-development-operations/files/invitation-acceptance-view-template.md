# 초대 수락 화면 템플릿

Laravel Blade와 Flux를 사용하는 초대 전용 가입 화면을 만들 때 복사한 뒤 대괄호 값을 프로젝트에 맞게 바꾼다.

## 자리표시자

- `[AUTH_LAYOUT_COMPONENT]`
  - 예시: `x-layouts::auth`
- `[PAGE_TITLE]`
  - 예시: `초대 수락`
- `[COMPANY_NAME_EXPRESSION]`
  - 예시: `$invitation->company->name`
- `[INVITED_EMAIL_EXPRESSION]`
  - 예시: `$invitation->email`
- `[SUBMIT_ROUTE_NAME]`
  - 예시: `invitations.accept.store`
- `[TOKEN_EXPRESSION]`
  - 예시: `$token`
- `[SUBMIT_BUTTON_TEXT]`
  - 예시: `계정 만들기`

## 템플릿

```blade
<[AUTH_LAYOUT_COMPONENT] :title="__('[PAGE_TITLE]')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('[PAGE_TITLE]')"
            :description="__(':company의 계정을 생성합니다.', ['company' => [COMPANY_NAME_EXPRESSION]])"
        />

        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="text-zinc-500 dark:text-zinc-400">{{ __('초대 이메일') }}</div>
            <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ [INVITED_EMAIL_EXPRESSION] }}</div>
        </div>

        <form method="POST" action="{{ route('[SUBMIT_ROUTE_NAME]', [TOKEN_EXPRESSION]) }}" class="flex flex-col gap-6">
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
                {{ __('[SUBMIT_BUTTON_TEXT]') }}
            </flux:button>
        </form>
    </div>
</[AUTH_LAYOUT_COMPONENT]>
```

## 사용할 때

- Laravel Blade와 Flux를 사용하며 공개 회원가입을 닫고 초대 링크로만 계정을 만들 때 사용한다.
- 서버에서 초대 토큰의 유효성, 만료, 폐기, 고객사 상태와 재사용 여부를 이미 검사하는 흐름에 사용한다.

## 사용하지 않을 때

- 공개 회원가입 화면에는 사용하지 않는다.
- React, Vue 또는 별도 프런트엔드 애플리케이션에는 그대로 사용하지 않는다.
- 프로젝트에 승인된 별도 디자인 시스템이나 기존 가입 화면이 있으면 그 화면을 우선한다.
- 이 화면만으로 초대 보안이 완성된 것으로 보지 않는다. 서버 검증과 요청 횟수 제한이 없으면 사용하지 않는다.
