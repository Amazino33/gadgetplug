<?php

use App\Jobs\SendDeliveryMessageJob;
use App\Models\DeliveryMessage;
use App\Models\PlatformMessagingSetting;
use App\Models\User;
use App\Services\Messaging\MessageDigest;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\WhatsAppGate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Phase 5: staying on Wawp, automated WhatsApp is throttled (lanes, gaps,
// per-number spacing, P3 hours, caps with warm-up), braked (session error or
// five failures in a row → pause, alert, P1 by SMS), digested (non-urgent
// messages to one number combined) and never used for marketing.

/** Wawp as the WhatsApp driver, Termii for SMS, both faked. */
function onWawp(int $wawpStatus = 200, array $wawpBody = ['id' => 'true_123@c.us_ABC']): void
{
    config([
        'services.messaging.whatsapp_driver' => 'wawp',
        'services.messaging.sms_driver'      => 'termii',
        'services.wawp.instance_id'          => 'TEST_INSTANCE',
        'services.wawp.access_token'         => 'TEST_TOKEN',
        'services.termii.api_key'            => 'TEST_TERMII',
        'services.termii.sender_id'          => 'GadgetPlug',
        'messaging.ops_number'               => '08099990000',
        'messaging.throttle.enabled'         => true,
    ]);

    Http::fake([
        'api.wawp.net/*'        => Http::response($wawpBody, $wawpStatus),
        'api.ng.termii.com/*'   => Http::response(['message_id' => 'SMS1', 'message' => 'Successfully Sent'], 200),
    ]);
}

function waMessage(string $to, string $key = 'storekeeper_new_order', array $over = []): DeliveryMessage
{
    return DeliveryMessage::create($over + [
        'vendor_id'      => null,
        'recipient_type' => 'storekeeper',
        'channel'        => 'whatsapp',
        'to_number'      => $to,
        'body'           => "Message {$key} to {$to}",
        'status'         => 'queued',
        'template_key'   => $key,
    ]);
}

function gate(): WhatsAppGate
{
    return app(WhatsAppGate::class);
}

/** Run the job once, recording a release instead of acting on it. */
function runJob(DeliveryMessage $message): SendDeliveryMessageJob
{
    $job = (new SendDeliveryMessageJob($message->id))->withFakeQueueInteractions();

    try {
        $job->handle(app(MessagingService::class));
    } catch (RuntimeException) {
        // A failed send rethrows so the queue retries; the row records it.
    }

    return $job;
}

beforeEach(function () {
    // Daytime in Lagos unless a test says otherwise.
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(11, 0)->setTimezone('UTC'));
});

// ── Lanes ───────────────────────────────────────────────────────────────────

it('puts each message in its lane by template, and delays only non-urgent WhatsApp for the digest', function () {
    onWawp();
    Queue::fake();

    $p1 = waMessage('08011110001', 'customer_delivery_code');
    $p2 = waMessage('08011110002', 'storekeeper_new_order');
    $p3 = waMessage('08011110003', 'storekeeper_low_stock');
    $sms = waMessage('08011110004', 'customer_delivery_code', ['channel' => 'sms']);

    foreach ([$p1, $p2, $p3, $sms] as $m) {
        app(MessagingService::class)->dispatch($m);
    }

    expect([$p1->priority, $p2->priority, $p3->priority])->toBe([1, 2, 3]);

    Queue::assertPushedOn('messaging-p1', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $p1->id && $job->delay === null);
    Queue::assertPushedOn('messaging-p2', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $p2->id && $job->delay !== null);
    Queue::assertPushedOn('messaging-p3', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $p3->id && $job->delay !== null);
    Queue::assertPushedOn('messaging', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $sms->id);
});

it('lets P1 go after a short gap while P2 and P3 still have to wait', function () {
    onWawp();

    expect(gate()->decide(waMessage('08022220001', 'storekeeper_new_order'))['action'])->toBe('send');

    $this->travel(9)->seconds();

    // 9s later: past any P1 gap (3–8s), short of any P2 (10–25s) or P3 (30–60s).
    expect(gate()->decide(waMessage('08022220002', 'customer_delivery_code'))['action'])->toBe('send');

    $this->travel(1)->seconds();
    $p2 = gate()->decide(waMessage('08022220003', 'storekeeper_new_order'));
    $p3 = gate()->decide(waMessage('08022220004', 'storekeeper_low_stock'));

    expect($p2['action'])->toBe('wait')
        ->and($p3['action'])->toBe('wait')
        ->and($p3['seconds'])->toBeGreaterThan($p2['seconds'] - 3);
});

it('rolls each message its own random gap inside its lane\'s range', function () {
    onWawp();

    $gaps = collect(range(1, 30))->map(fn ($i) => gate()->gapFor(waMessage('0803333'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'customer_delivery_code')));
    $p3 = collect(range(1, 30))->map(fn ($i) => gate()->gapFor(waMessage('0804444'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'vendor_daily_summary')));

    expect($gaps->min())->toBeGreaterThanOrEqual(3)->and($gaps->max())->toBeLessThanOrEqual(8)
        ->and($gaps->unique()->count())->toBeGreaterThan(1)
        ->and($p3->min())->toBeGreaterThanOrEqual(30)->and($p3->max())->toBeLessThanOrEqual(60)
        ->and($p3->unique()->count())->toBeGreaterThan(1);
});

it('keeps at least 30 seconds between two messages to the same number', function () {
    onWawp();
    config(['messaging.throttle.lanes.1' => [3, 3]]);

    expect(gate()->decide(waMessage('08055550000', 'customer_delivery_code'))['action'])->toBe('send');

    $this->travel(10)->seconds();
    $second = gate()->decide(waMessage('08055550000', 'customer_received'));
    $other = gate()->decide(waMessage('08055559999', 'customer_received'));

    expect($second['action'])->toBe('wait')
        ->and($second['reason'])->toBe('per_recipient_gap')
        ->and($second['seconds'])->toBeGreaterThanOrEqual(20)
        ->and($other['action'])->toBe('send');

    $this->travel(21)->seconds();
    expect(gate()->decide(waMessage('08055550000', 'customer_received'))['action'])->toBe('send');
});

it('releases a waiting message back to the queue with a delay, never sleeping', function () {
    onWawp();
    config(['messaging.throttle.digest_window' => 0]);

    $first = waMessage('08066660001', 'customer_delivery_code');
    runJob($first);
    $second = waMessage('08066660001', 'customer_received');
    $job = runJob($second);

    expect($first->fresh()->status)->toBe('sent')
        ->and($second->fresh()->status)->toBe('queued');
    $job->assertReleased();
});

// ── P3 hours ────────────────────────────────────────────────────────────────

it('holds P3 outside 08:00–20:00 Lagos time but lets P1 through', function () {
    onWawp();
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(21, 30)->setTimezone('UTC'));

    $p3 = gate()->decide(waMessage('08077770001', 'storekeeper_undispatched'));
    $p1 = gate()->decide(waMessage('08077770002', 'customer_delivery_code'));

    $opens = now()->setTimezone('Africa/Lagos')->addDay()->setTime(8, 0);

    expect($p3['action'])->toBe('wait')
        ->and($p3['reason'])->toBe('outside_p3_hours')
        ->and(now()->addSeconds($p3['seconds'])->gte($opens))->toBeTrue()
        ->and($p1['action'])->toBe('send');
});

// ── Caps ────────────────────────────────────────────────────────────────────

it('at the hourly cap makes P3 wait and sends P1 by SMS instead', function () {
    onWawp();
    config(['messaging.throttle.hourly_cap' => 2]);

    foreach (['08088880001', '08088880002'] as $n) {
        waMessage($n)->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    }

    $p3 = waMessage('08088880003', 'storekeeper_low_stock');
    $p1 = waMessage('08088880004', 'customer_delivery_code');

    expect(gate()->decide($p3))->toMatchArray(['action' => 'wait', 'reason' => 'hourly_cap']);

    runJob($p1);
    $p1->refresh();

    expect($p1->channel)->toBe('sms')
        ->and($p1->status)->toBe('sent')
        ->and($p1->provider_response['fallback_from'])->toBe('whatsapp')
        ->and($p1->provider_response['fallback_reason'])->toBe('hourly_cap');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'termii') && $r['to'] === '2348088880004');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wawp') && str_contains($r['chatId'] ?? '', '2348088880004'));
});

it('uses the lower warm-up daily cap while warming up', function () {
    config(['messaging.throttle.warmup' => true, 'messaging.throttle.warmup_daily_cap' => 3, 'messaging.throttle.daily_cap' => 400]);
    expect(gate()->dailyCap())->toBe(3);

    config(['messaging.throttle.warmup' => false]);
    expect(gate()->dailyCap())->toBe(400);
});

// ── The brake ───────────────────────────────────────────────────────────────

function superAdmin(): User
{
    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    return $admin;
}

it('pauses after five failures in a row, alerts admin, and sends P1 by SMS while paused', function () {
    onWawp(500, ['error' => 'upstream']);
    $admin = superAdmin();
    config(['messaging.throttle.per_recipient_gap' => 0]);

    foreach (range(1, 5) as $i) {
        $this->travel(61)->seconds();
        runJob(waMessage('0801000000'.$i, 'customer_received'));
    }

    $settings = PlatformMessagingSetting::current();

    expect($settings->whatsapp_paused_at)->not->toBeNull()
        ->and($settings->whatsapp_consecutive_failures)->toBe(5)
        ->and($admin->notifications()->count())->toBe(1);

    // The ops number was told by SMS.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'termii') && $r['to'] === '2348099990000' && str_contains($r['sms'], 'PAUSED'));

    // While paused: urgent goes by SMS, the rest is held.
    $p1 = waMessage('08010000099', 'customer_delivery_code');
    $p2 = waMessage('08010000098', 'storekeeper_new_order');
    runJob($p1);
    runJob($p2);

    expect($p1->fresh()->channel)->toBe('sms')
        ->and($p1->fresh()->status)->toBe('sent')
        ->and($p2->fresh()->status)->toBe(DeliveryMessage::STATUS_HELD);
});

it('pauses at once on a session error and the failing P1 still reaches the customer by SMS', function () {
    onWawp(404, ['code' => 'invalid_session', 'message' => 'Session not found']);

    $p1 = waMessage('08010101010', 'customer_delivery_code');
    runJob($p1);

    expect(gate()->isPaused())->toBeTrue()
        ->and(PlatformMessagingSetting::current()->whatsapp_pause_reason)->toContain('invalid_session')
        ->and($p1->fresh()->channel)->toBe('sms')
        ->and($p1->fresh()->status)->toBe('sent');
});

it('refuses manual WhatsApp sends while paused', function () {
    onWawp();
    gate()->pause('test');

    $result = app(MessagingService::class)->send(waMessage('08012121212', 'customer_dispatched'));

    expect($result->status)->toBe('failed')
        ->and($result->provider_response['error'])->toBe('whatsapp_paused');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wawp'));
});

it('resumes from the panel: unpaused, counters cleared, held messages queued again', function () {
    onWawp();
    gate()->pause('test');
    $held = waMessage('08013131313', 'storekeeper_new_order', ['status' => DeliveryMessage::STATUS_HELD]);
    $admin = superAdmin();

    Queue::fake();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(App\Filament\Pages\MessagingSettings::class)
        ->assertSee('PAUSED')
        ->callAction('resumeWhatsApp')
        ->assertHasNoActionErrors();

    expect(gate()->isPaused())->toBeFalse()
        ->and(PlatformMessagingSetting::current()->whatsapp_consecutive_failures)->toBe(0)
        ->and($held->fresh()->status)->toBe('queued');
    Queue::assertPushedOn('messaging-p2', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $held->id);
});

it('shows WhatsApp health on the admin dashboard', function () {
    onWawp();
    waMessage('08014141414')->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    $this->actingAs(superAdmin());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(App\Filament\Widgets\WhatsAppHealthWidget::class)
        ->assertSee('Connected')
        ->assertSee('Sent today')
        ->assertSee('1 / '.gate()->dailyCap());
});

// ── Digest ──────────────────────────────────────────────────────────────────

it('combines non-urgent messages to the same number within two minutes into one', function () {
    onWawp();

    $a = waMessage('08015151515', 'vendor_new_order', ['body' => 'Order A']);
    $this->travel(40)->seconds();
    $b = waMessage('08015151515', 'vendor_new_order', ['body' => 'Order B']);
    $this->travel(40)->seconds();
    $c = waMessage('08015151515', 'storekeeper_new_order', ['body' => 'Order C']);
    $urgent = waMessage('08015151515', 'customer_delivery_code', ['body' => 'Your code']);
    $other = waMessage('08016161616', 'vendor_new_order', ['body' => 'Someone else']);
    $this->travel(3)->minutes();
    $late = waMessage('08015151515', 'vendor_new_order', ['body' => 'Order D, much later']);

    // Not the leader: it waits for the earliest to take it in.
    expect(app(MessageDigest::class)->prepare($b->fresh()))->toBe('wait');

    runJob($a->fresh());

    expect($a->fresh()->status)->toBe('sent')
        ->and($a->fresh()->body)->toContain('You have 3 updates')->toContain('Order A')->toContain('Order B')->toContain('Order C')
        ->and($b->fresh()->status)->toBe(DeliveryMessage::STATUS_MERGED)
        ->and($b->fresh()->merged_into_id)->toBe($a->id)
        ->and($c->fresh()->status)->toBe(DeliveryMessage::STATUS_MERGED)
        // Urgent, another number, and outside the window are left alone.
        ->and($urgent->fresh()->status)->toBe('queued')
        ->and($other->fresh()->status)->toBe('queued')
        ->and($late->fresh()->status)->toBe('queued');

    // The merged ones' own jobs do nothing.
    runJob($b->fresh());
    expect($b->fresh()->status)->toBe(DeliveryMessage::STATUS_MERGED);
});

// ── Never marketing on Wawp ─────────────────────────────────────────────────

it('refuses and logs a marketing or broadcast message on Wawp, and never retries it', function () {
    onWawp();

    $promo = waMessage('08017171717', 'promo', ['category' => DeliveryMessage::CATEGORY_MARKETING, 'body' => 'Big sale!']);
    runJob($promo);

    expect($promo->fresh()->status)->toBe('failed')
        ->and($promo->fresh()->provider_response['error'])->toBe('marketing_refused')
        ->and($promo->fresh()->send_failures)->toBe(0);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wawp'));
});
