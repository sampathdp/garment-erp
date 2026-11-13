<?php

declare(strict_types=1);

/*
 * Child process for the job-number concurrency test: boots the application against the MySQL test
 * database given in the DB_* environment and takes `$argv[1]` job numbers, each in its own
 * transaction (one attempt, so a deadlock fails the test), printing one per line.
 */

use App\Modules\Orders\Actions\GenerateJobNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$count = (int) ($argv[1] ?? 10);
$generator = $app->make(GenerateJobNumber::class);

for ($i = 0; $i < $count; $i++) {
    $number = DB::transaction(fn () => $generator->handle('CMB', 'JRSY'));
    fwrite(STDOUT, $number.PHP_EOL);
    usleep(random_int(0, 3000)); // interleave with the other workers
}
