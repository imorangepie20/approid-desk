<?php

namespace App\Actions;

use App\Enums\ServiceContractStatus;
use App\Models\ServiceContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConfirmContractSignature
{
    public function handle(User $actor, ServiceContract $contract): ServiceContract
    {
        return DB::transaction(function () use ($actor, $contract): ServiceContract {
            $locked = ServiceContract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('confirmSignature', $locked);

            if ($locked->signature_confirmed_at !== null) {
                return $locked;
            }

            $path = $locked->document_path;
            if (! in_array($locked->status, [ServiceContractStatus::Draft, ServiceContractStatus::Active], true)
                || $path === null || ! str_starts_with($path, 'contracts/')
                || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['contract' => '유효한 비공개 계약서 파일과 계약 상태를 확인해 주세요.']);
            }

            $locked->forceFill([
                'signature_confirmed_at' => now(),
                'signature_confirmed_by' => $actor->id,
                'status' => ServiceContractStatus::Active,
            ])->save();

            return $locked;
        });
    }
}
