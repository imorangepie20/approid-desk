<?php

// Test-only subprocess; connection configuration is supplied through stdin.
use App\Actions\RegisterAttachment;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require __DIR__.'/../../vendor/autoload.php';
$payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
if (($payload['database']['database'] ?? '') !== 'testing') {
    throw new RuntimeException('Only the testing database is allowed.');
}
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $payload['database'],
    'app.key' => $payload['app_key'], 'cache.default' => 'array', 'session.driver' => 'array',
    'queue.default' => 'database',
    'attachments.max_per_request' => 1, 'filesystems.disks.attachments' => [
        'driver' => 'local', 'root' => $payload['attachment_root'], 'visibility' => 'private', 'throw' => true,
    ]]);
DB::purge('mysql');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$announced = false;
DB::connection()->beforeExecuting(function (string $sql) use (&$announced): void {
    if (! $announced && str_contains($sql, '`work_requests`') && str_contains($sql, 'for update')) {
        $announced = true;
        fwrite(STDOUT, "LOCKING\n");
        fflush(STDOUT);
    }
});
$stream = tmpfile();
fwrite($stream, "Synthetic quota test.\n");
try {
    $file = new UploadedFile(stream_get_meta_data($stream)['uri'], 'quota.txt', 'text/plain', UPLOAD_ERR_OK, true);
    $attachment = (new RegisterAttachment)->handle(User::findOrFail($payload['actor']), WorkRequest::findOrFail($payload['request']), $file);
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 'registered', 'id' => $attachment->id])."\n");
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 'rejected', 'fields' => array_keys($exception->errors())])."\n");
} finally {
    fclose($stream);
}
