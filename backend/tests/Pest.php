<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

require_once __DIR__.'/Support/intake.php';
require_once __DIR__.'/Support/design.php';
require_once __DIR__.'/Support/bulk.php';
require_once __DIR__.'/Support/printing.php';
