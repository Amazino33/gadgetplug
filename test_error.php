<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$req = Request::create('/plug/zeelink-tech/price-list', 'GET');
$resp = $kernel->handle($req);
echo $resp->getContent();
