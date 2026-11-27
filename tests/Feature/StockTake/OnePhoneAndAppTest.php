<?php

use App\Models\StockTakeTask;
use App\Services\StockTake\StockTakeDevice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Release 2, A7 and A8: one phone per task, and Stock Take as an app on the
 * phone (its service worker and home-screen manifest).
 */

function stPhone(array $ctx, StockTakeTask $task, string $path): string
{
    return "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}{$path}";
}

function stPhoneOp(int $aisleId, int $productId): array
{
    return [
        'id'         => (string) Str::uuid(),
        'type'       => 'set',
        'aisle_id'   => $aisleId,
        'product_id' => $productId,
        'lines'      => [['unit_id' => null, 'qty' => '4']],
        'counted_at' => now()->toIso8601ZuluString(),
    ];
}

/** A count task over one aisle with one product, given to keeper A. */
function stPhoneTask(array $ctx): array
{
    $milk  = stProduct($ctx, 'Milk');
    $aisle = stAisle($ctx, 'Aisle 1');
    stMap($ctx, $aisle, $milk);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    return [$task, $aisle, $milk];
}

test('the first phone to download a task holds it; another phone is refused until it is released', function () {
    $ctx = stockTakeContext();
    [$task] = stPhoneTask($ctx);

    $this->actingAs($ctx['keeperA'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 11; moto g power) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36')
        ->getJson(stPhone($ctx, $task, '/pack?device=phone-one'))->assertOk();
    expect($task->fresh())->device_id->toBe('phone-one')->device_label->toBe('Android · Chrome');

    $this->getJson(stPhone($ctx, $task, '/pack?device=phone-one'))->assertOk();
    $this->getJson(stPhone($ctx, $task, '/pack?device=phone-two'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'other_phone')
        ->assertJsonPath('holder', 'Android · Chrome')
        ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_starts_with($m, StockTakeDevice::OTHER_PHONE) && str_contains($m, 'It is open on Android · Chrome, last used'))->etc());
});

test('uploads and the hand-in from another phone are refused; nothing from it is saved', function () {
    $ctx = stockTakeContext();
    [$task, $aisle, $milk] = stPhoneTask($ctx);
    $this->actingAs($ctx['keeperA'])->getJson(stPhone($ctx, $task, '/pack?device=phone-one'))->assertOk();

    $this->postJson(stPhone($ctx, $task, '/sync'), ['device' => 'phone-two', 'ops' => [stPhoneOp($aisle->id, $milk->id)]])
        ->assertStatus(409)->assertJsonPath('code', 'other_phone');
    $this->postJson(stPhone($ctx, $task, '/submit'), ['device' => 'phone-two', 'decisions' => [$milk->id => 'skip']])
        ->assertStatus(409);

    expect($task->entries()->count())->toBe(0)
        ->and($task->fresh()->status)->not->toBe(StockTakeTask::STATUS_SUBMITTED);
});

test('every upload records what the phone still had waiting, for the manager', function () {
    $ctx = stockTakeContext();
    [$task, $aisle, $milk] = stPhoneTask($ctx);

    $this->actingAs($ctx['keeperA'])->postJson(stPhone($ctx, $task, '/sync'), [
        'device'  => 'phone-one',
        'waiting' => 30,
        'ops'     => [stPhoneOp($aisle->id, $milk->id)],
    ])->assertOk();

    expect($task->fresh())->device_id->toBe('phone-one')->device_pending->toBe(29)
        ->and($task->fresh()->device_seen_at)->not->toBeNull();
});

test('a manager releases the task; with counts not yet uploaded only on saying they are lost', function () {
    $ctx = stockTakeContext();
    [$task] = stPhoneTask($ctx);
    $task->forceFill(['device_id' => 'phone-one', 'device_pending' => 3])->save();
    $device = app(StockTakeDevice::class);

    expect(fn () => $device->release($ctx['keeperB'], $task->fresh()))->toThrow(AuthorizationException::class);
    expect(fn () => $device->release($ctx['admin'], $task->fresh()))->toThrow(ValidationException::class);

    $device->release($ctx['admin'], $task->fresh(), acceptLoss: true);
    expect($task->fresh())->device_id->toBeNull()->device_pending->toBeNull();

    // Now another phone can carry on.
    $this->actingAs($ctx['keeperA'])->getJson(stPhone($ctx, $task, '/pack?device=phone-two'))->assertOk();
    expect($task->fresh()->device_id)->toBe('phone-two');
});

test('everything uploaded: released with no question', function () {
    $ctx = stockTakeContext();
    [$task] = stPhoneTask($ctx);
    $task->forceFill(['device_id' => 'phone-one', 'device_pending' => 0])->save();

    app(StockTakeDevice::class)->release($ctx['admin'], $task->fresh());

    expect($task->fresh()->device_id)->toBeNull();
});

test('a handed-in task is not released', function () {
    $ctx = stockTakeContext();
    [$task] = stPhoneTask($ctx);
    $task->forceFill(['device_id' => 'phone-one', 'status' => StockTakeTask::STATUS_SUBMITTED])->save();

    expect(fn () => app(StockTakeDevice::class)->release($ctx['admin'], $task->fresh()))->toThrow(ValidationException::class);
});

test('the service worker: served from /stock-take/, may control /stock-take/ only, never cached, with the app files to store', function () {
    $response = $this->get('/stock-take/sw.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/stock-take/')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    $script = $response->getContent();
    expect($script)->not->toContain('__VERSION__')->not->toContain('__PRECACHE__');

    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    expect($script)->toContain('/build/' . $manifest['resources/js/stock-take/main.jsx']['file']);

    // It only ever deletes its own caches (st-…), never the till's.
    expect($script)->toContain("name.startsWith('st-')");
});

test('the home-screen manifest: GadgetPlug Count, full screen, portrait, inside /stock-take/; none where Stock Take is off', function () {
    $ctx = stockTakeContext();

    $this->get("/stock-take/{$ctx['vendor']->slug}/manifest.webmanifest")
        ->assertOk()
        ->assertJsonPath('name', 'GadgetPlug Count')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('orientation', 'portrait')
        ->assertJsonPath('scope', '/stock-take/')
        ->assertJsonPath('start_url', "/stock-take/{$ctx['vendor']->slug}");

    $ctx['vendor']->update(['stock_take_enabled' => false]);
    $this->get("/stock-take/{$ctx['vendor']->slug}/manifest.webmanifest")->assertNotFound();
});

test('the app page links the manifest; a fresh CSRF token is there for a page opened from the phone', function () {
    $ctx = stockTakeContext();

    $this->actingAs($ctx['keeperA'])->get("/stock-take/{$ctx['vendor']->slug}")
        ->assertOk()
        ->assertSee("/stock-take/{$ctx['vendor']->slug}/manifest.webmanifest", false);

    $csrf = $this->getJson("/stock-take/{$ctx['vendor']->slug}/api/csrf");
    expect($csrf->assertOk()->json('token'))->toBeString()->not->toBeEmpty();
});
