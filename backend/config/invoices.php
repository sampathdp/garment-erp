<?php

declare(strict_types=1);

return [
    // Tax is applied only when required (client question Q9: VAT/SSCL registration). 0 = no tax line.
    'tax_percent' => (int) env('INVOICE_TAX_PERCENT', 0),
];
