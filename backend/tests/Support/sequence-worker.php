<?php

declare(strict_types=1);

/*
 * Child process for JobNumberConcurrencyTest: boots the application against the SQLite file in
 * DB_DATABASE and takes `$argv[1]` job numbers, each in its own transaction, printing one per line.
 */

use App\Modules\Orders\Actions\GenerateJobNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.connections.sqlite.busy_timeout' => 20000]);
DB::purge('sqlite');

$count = (int) ($argv[1] ?? 10);
$generator = $app->make(GenerateJobNumber::class);

for ($i = 0; $i < $count; $i++) {
    $number = DB::transaction(fn () => $generator->handle('CMB', 'JRSY'));
    fwrite(STDOUT, $number.PHP_EOL);
    usleep(random_int(0, 3000)); // interleave with the other workers
}
