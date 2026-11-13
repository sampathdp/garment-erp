<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\GenerateJobNumber;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('generates sequential job numbers that keep the branch and page codes', function () {
    intakeWorld($this);
    $a = createOrder($this);
    $b = createOrder($this, ['page_id' => $this->pageB->id], $this->ownerB);
    $c = createOrder($this);

    expect([$a->order_no, $b->order_no, $c->order_no])->toBe(['CMB-JRSY-26-00001', 'CMB-KITS-26-00002', 'CMB-JRSY-26-00003']);
});

it('never reuses a number and keeps issued numbers unchanged', function () {
    intakeWorld($this);
    $first = createOrder($this);

    // A failed order (validation inside the transaction) returns nothing to the sequence and creates no row.
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['unit_price_minor' => 0]))->assertStatus(422);
    $next = createOrder($this);

    expect($next->order_no)->toBe('CMB-JRSY-26-00002');

    // Editing the order never touches its number.
    $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$first->id}", ['gross_quantity' => 3, 'version' => $first->version])->assertOk()
        ->assertJsonPath('data.order_no', 'CMB-JRSY-26-00001');
    expect(Order::query()->pluck('order_no')->unique()->count())->toBe(2);
});

it('restarts the running number each year', function () {
    intakeWorld($this);
    createOrder($this);
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:10:00', 'Asia/Colombo'));
    expect(createOrder($this, ['due_date' => '2027-02-01'])->order_no)->toBe('CMB-JRSY-27-00001');
});

it('never hands out the same number to concurrent processes', function () {
    // The workers commit real rows in the test database, outside this test's rolled-back
    // transaction, so the sequence row is removed through a separate autocommit connection.
    $db = config('database.connections.'.config('database.default'));
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $db['database']), $db['username'], $db['password']);
    $yearNumber = (int) now('Asia/Colombo')->year;
    $clear = fn () => $pdo->prepare("DELETE FROM sequences WHERE scope_type = 'order' AND scope_id = 0 AND year = ?")->execute([$yearNumber]);
    $clear();

    $workers = 4;
    $perWorker = 15;
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_URL' => '',
        'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
    ]);

    $numbers = [];
    $errors = '';
    try {
        $processes = [];
        for ($w = 0; $w < $workers; $w++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/sequence-worker.php'), (string) $perWorker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            expect($process)->not->toBeFalse();
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $numbers = [...$numbers, ...array_filter(explode(PHP_EOL, (string) stream_get_contents($pipes[1])))];
            $errors .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $errors);
        }
    } finally {
        $clear();
    }

    $year = substr((string) $yearNumber, -2);
    $expected = array_map(fn (int $n) => sprintf('CMB-JRSY-%s-%05d', $year, $n), range(1, $workers * $perWorker));
    sort($numbers);
    if (count($numbers) !== $workers * $perWorker) { file_put_contents(sys_get_temp_dir()."/jobnum-debug.txt", "NUMBERS
".implode("
", $numbers)."
ERRORS
".$errors); }

    expect($numbers)->toHaveCount($workers * $perWorker)
        ->and(array_unique($numbers))->toHaveCount($workers * $perWorker)
        ->and($numbers)->toBe($expected); // unique AND gap-free
})->group('concurrency');

it('takes the number inside the inserting transaction (row lock on MySQL)', function () {
    DB::transaction(function () {
        expect(app(GenerateJobNumber::class)->handle('CMB', 'JRSY'))->toEndWith('-00001')
            ->and(app(GenerateJobNumber::class)->handle('CMB', 'JRSY'))->toEndWith('-00002');
    });
});
