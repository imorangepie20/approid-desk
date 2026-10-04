<?php

// Test-only subprocess. Configuration arrives over stdin, never command arguments.
use App\Actions\RetryNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
if (($payload['database']['database'] ?? '') !== 'testing') {
    throw new RuntimeException('Only the testing database is allowed.');
}
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql',
    'database.connections.mysql' => $payload['database'],
    'app.key' => $payload['app_key'],
    'cache.default' => 'array',
    'session.driver' => 'array',
]);
DB::purge('mysql');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
Queue::fake();
$announced = false;
DB::connection()->beforeExecuting(function (string $sql) use ($payload, &$announced): void {
    if (! $announced && str_contains($sql, '`'.$payload['lock_table'].'`') && str_contains($sql, 'for update')) {
        $announced = true;
        fwrite(STDOUT, "LOCKING\n");
        fflush(STDOUT);
    }
});
try {
    $delivery = (new RetryNotificationDelivery)->handle(
        User::findOrFail($payload['actor']),
        NotificationDelivery::findOrFail($payload['delivery']),
    );
    fwrite(STDOUT, 'RESULT:'.json_encode([
        'status' => 'queued',
        'id' => $delivery->id,
        'jobs' => Queue::pushed(SendQueuedNotifications::class)->count(),
    ])."\n");
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'RESULT:'.json_encode([
        'status' => 'rejected',
        'fields' => array_keys($exception->errors()),
    ])."\n");
}
