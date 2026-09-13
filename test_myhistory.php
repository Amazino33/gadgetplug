<?php

use App\Models\PosSale;
use Illuminate\Contracts\Http\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
try {
    $sales = PosSale::with(['items', 'payments'])->get();
    echo 'SUCCESS';
} catch (Throwable $e) {
    echo $e->getMessage();
}
