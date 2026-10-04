<?php

use App\Actions\AdjustContractMonth;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
if (($payload['database']['database'] ?? '') !== 'testing') {
    throw new RuntimeException('Only the testing database is allowed.');
}
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $payload['database'],
    'app.key' => $payload['app_key'], 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('mysql');
Carbon::setTestNow($payload['now']);
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$announced = false;
DB::connection()->beforeExecuting(function (string $sql) use ($payload, &$announced): void {
    if (! $announced && str_contains($sql, '`'.$payload['lock_table'].'`') && str_contains($sql, 'for update')) {
        $announced = true;
        fwrite(STDOUT, "LOCKING\n");
        fflush(STDOUT);
    }
});
try {
    $actor = User::findOrFail($payload['actor']);
    $month = ContractMonth::findOrFail($payload['month']);
    $result = match ($payload['action']) {
        'confirm' => (new ConfirmWorkLog)->handle($actor, WorkLog::findOrFail($payload['log']), 1),
        'confirm_non_billable' => (new ConfirmNonBillableWorkLog)->handle($actor, WorkLog::findOrFail($payload['log']), 1),
        'cancel' => (new TransitionWorkRequest)->handle($actor, WorkRequest::findOrFail($payload['request']),
            WorkRequestStatus::Cancelled, '동시 취소 검증'),
        'close' => (new CloseContractMonth)->handle($actor, $month),
        'adjust' => (new AdjustContractMonth)->handle($actor, $month, TimeLedgerEntry::findOrFail($payload['related']),
            TimeLedgerType::AdjustDecrease, 60, '동시 조정 검증', $payload['key']),
        'draft' => (new SaveWorkLogDraft)->handle($actor, WorkRequest::findOrFail($payload['request']),
            ['worked_on' => $month->month->toDateString(), 'minutes' => 30, 'description' => '동시 저장 검증', 'is_billable' => true]),
    };
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 'ok', 'id' => $result->id])."\n");
} catch (ValidationException $error) {
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())])."\n");
}
