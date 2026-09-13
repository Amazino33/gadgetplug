<?php

use App\Filament\Vendor\Resources\PosSales\PosSaleResource;
use Filament\Tables\Table;
use Illuminate\Contracts\Http\Kernel;
use Livewire\Component;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$resource = new PosSaleResource;
try {
    $table = $resource::table(new Table(app(Component::class)));
    echo 'OK';
} catch (Throwable $e) {
    echo $e->getMessage();
}
