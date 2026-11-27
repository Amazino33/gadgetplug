<?php

use App\Filament\Vendor\Resources\Counters\CounterResource;
use App\Filament\Vendor\Resources\Counters\Pages\ListCounters;
use App\Models\StockTakeCounter;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeCounters;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Counters: people who count stock but have no use for the site. Their name and
 * a 6-digit code, from the shop's QR code.
 */

function stCounters(): StockTakeCounters
{
    return app(StockTakeCounters::class);
}

/** @return array{counter: StockTakeCounter, code: string, key: string} */
function stNewCounter(array $ctx, string $name = 'Chidi Okafor'): array
{
    $made = stCounters()->add($ctx['admin'], $ctx['vendor'], $name, '0803 000 0000', [$ctx['store']->id]);

    return $made + ['key' => stCounters()->joinKey($ctx['vendor'])];
}

function stJoinUrl(array $ctx, ?string $key = null): string
{
    return "/stock-take/{$ctx['vendor']->slug}/join" . ($key ? "?k={$key}" : '');
}

function stJoin(array $ctx, string $key, int $counterId, string $code)
{
    return test()->post("/stock-take/{$ctx['vendor']->slug}/join?k={$key}", ['k' => $key, 'counter' => $counterId, 'code' => $code]);
}

test('adding a counter makes a user who can only count, with a 6-digit code kept hashed', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);
    $user = $made['counter']->user;

    expect($made['code'])->toMatch('/^\d{6}$/')
        ->and($made['counter']->code_hash)->not->toContain($made['code'])
        ->and(Hash::check($made['code'], $made['counter']->code_hash))->toBeTrue()
        ->and($user->name)->toBe('Chidi Okafor')
        ->and($user->email)->toEndWith('@counters.invalid')
        ->and($user->isCounter())->toBeTrue()
        ->and($ctx['vendor']->users()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->stores()->whereKey($ctx['store']->id)->exists())->toBeTrue();

    expect(StockTakeAccess::canPerform($user, $ctx['vendor']->id, $ctx['store']->id))->toBeTrue()
        ->and(StockTakeAccess::canManage($user, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canMap($user, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
});

test('a counter has no way into the panel; the code is not a password for /login', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);
    $user = $made['counter']->user;

    expect($user->canAccessPanel(Filament::getPanel('vendor')))->toBeFalse()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->post('/login', ['email' => $user->email, 'password' => $made['code']])->assertSessionHasErrors();
    $this->assertGuest();
});

test('only someone who may manage stock takes adds counters, and names must tell people apart', function () {
    $ctx = stockTakeContext();

    expect(fn () => stCounters()->add($ctx['keeperA'], $ctx['vendor'], 'Ngozi', null, [$ctx['store']->id]))
        ->toThrow(AuthorizationException::class);

    stNewCounter($ctx, 'Ngozi');

    expect(fn () => stCounters()->add($ctx['admin'], $ctx['vendor'], ' ngozi ', null, [$ctx['store']->id]))
        ->toThrow(ValidationException::class);
});

test('the sign-in page lists the shop\'s counters who are on, and nobody else', function () {
    $ctx   = stockTakeContext();
    $chidi = stNewCounter($ctx, 'Chidi Okafor');
    $ngozi = stNewCounter($ctx, 'Ngozi Eze');
    stCounters()->setActive($ctx['admin'], $ngozi['counter'], false);

    $other = stockTakeContext();
    stNewCounter($other, 'Someone Else');

    $this->get(stJoinUrl($ctx))->assertNotFound();
    $this->get(stJoinUrl($ctx, 'wrong'))->assertNotFound();

    $this->get(stJoinUrl($ctx, $chidi['key']))
        ->assertOk()
        ->assertSee('Chidi Okafor')
        ->assertDontSee('Ngozi Eze')
        ->assertDontSee('Someone Else');

    $ctx['vendor']->update(['stock_take_enabled' => false]);
    $this->get(stJoinUrl($ctx, $chidi['key']))->assertNotFound();
});

test('the right name and code sign the counter in to the phone app, which shows their task', function () {
    $ctx   = stockTakeContext();
    $made  = stNewCounter($ctx);
    $aisle = stAisle($ctx, 'Aisle 1');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $made['counter']->user, null);

    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])
        ->assertRedirect("/stock-take/{$ctx['vendor']->slug}");

    $this->assertAuthenticatedAs($made['counter']->user);
    expect($made['counter']->fresh()->last_signed_in_at)->not->toBeNull();

    $this->get("/stock-take/{$ctx['vendor']->slug}")->assertOk()->assertSee('"isCounter":true', false);
    $this->getJson("/stock-take/{$ctx['vendor']->slug}/api/home")->assertOk()->assertJsonPath('tasks.0.id', $task->id);
});

test('a wrong code and a wrong name get the same words, so nothing is learned by guessing', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);

    $wrongCode = stJoin($ctx, $made['key'], $made['counter']->id, $made['code'] === '000000' ? '111111' : '000000');
    $wrongName = stJoin($ctx, $made['key'], $made['counter']->id + 999, $made['code']);

    $wrongCode->assertSessionHasErrors(['code' => StockTakeCounters::WRONG]);
    $wrongName->assertSessionHasErrors(['code' => StockTakeCounters::WRONG]);
    $this->assertGuest();
});

test('five wrong codes lock that counter, even for the right code; a new code unlocks', function () {
    $ctx   = stockTakeContext();
    $made  = stNewCounter($ctx);
    $wrong = $made['code'] === '123456' ? '654321' : '123456';

    foreach (range(1, StockTakeCounters::MAX_ATTEMPTS) as $_) {
        stJoin($ctx, $made['key'], $made['counter']->id, $wrong)->assertSessionHasErrors('code');
    }

    expect($made['counter']->fresh()->isLocked())->toBeTrue();

    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
    expect(session('errors')->first('code'))->toContain('Too many wrong codes');

    $fresh = stCounters()->resetCode($ctx['admin'], $made['counter']->fresh());
    stJoin($ctx, $made['key'], $made['counter']->id, $fresh)->assertRedirect();
    $this->assertAuthenticatedAs($made['counter']->user);
});

test('a new code stops the old one and signs the counter out everywhere', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);

    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $made['counter']->user_id, 'payload' => '', 'last_activity' => time()]);

    $fresh = stCounters()->resetCode($ctx['admin'], $made['counter']);

    expect(DB::table('sessions')->where('user_id', $made['counter']->user_id)->count())->toBe(0);
    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertSessionHasErrors('code');
    stJoin($ctx, $made['key'], $made['counter']->id, $fresh)->assertRedirect();
});

test('a counter who is turned off cannot sign in, and a session still open is refused', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);

    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertRedirect();
    $this->getJson("/stock-take/{$ctx['vendor']->slug}/api/home")->assertOk();

    stCounters()->setActive($ctx['admin'], $made['counter'], false);

    $this->getJson("/stock-take/{$ctx['vendor']->slug}/api/home")->assertForbidden();

    $this->post('/stock-take/' . $ctx['vendor']->slug . '/sign-out')->assertRedirect();
    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertSessionHasErrors('code');
    $this->assertGuest();

    stCounters()->setActive($ctx['admin'], $made['counter']->fresh(), true);
    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertRedirect();
});

test('a counter cannot sign in at another shop\'s page, or use another shop\'s stock take', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    $made  = stNewCounter($ctx);
    $otherKey = stCounters()->joinKey($other['vendor']);

    stJoin($other, $otherKey, $made['counter']->id, $made['code'])->assertSessionHasErrors('code');

    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertRedirect();
    expect($this->get("/stock-take/{$other['vendor']->slug}")->status())->not->toBe(200); // turned away: the other shop is not theirs
});

test('signing out sends them back to the sign-in page, ready for the next person', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);
    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertRedirect();

    $this->post("/stock-take/{$ctx['vendor']->slug}/sign-out")->assertRedirect(stCounters()->joinUrl($ctx['vendor']));

    $this->assertGuest();
});

test('a new QR code stops the printed one', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);

    $this->get(stJoinUrl($ctx, $made['key']))->assertOk();

    $new = stCounters()->rotateJoinKey($ctx['admin'], $ctx['vendor']);

    expect($new)->not->toBe($made['key']);
    $this->get(stJoinUrl($ctx, $made['key']))->assertNotFound();
    $this->get(stJoinUrl($ctx, $new))->assertOk();

    expect(fn () => stCounters()->rotateJoinKey($ctx['keeperA'], $ctx['vendor']))->toThrow(AuthorizationException::class);
});

test('a counter cannot decide results: counting is all their role allows', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);

    expect(StockTakeAccess::canManage($made['counter']->user, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
    expect(User::find($made['counter']->user_id)->hasVendorPermission($ctx['vendor']->id, 'access_pos'))->toBeFalse()
        ->and(User::find($made['counter']->user_id)->hasVendorPermission($ctx['vendor']->id, 'manage_inventory'))->toBeFalse();
});

function stCounterPanelAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['store']);
}

test('the Counters screen: managers add a counter and are shown the code once; counters are not in Team Members', function () {
    $ctx = stockTakeContext();
    stCounterPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListCounters::class)
        ->callAction('add', ['name' => 'Emeka Obi', 'phone' => '0801'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $counter = StockTakeCounter::where('name', 'Emeka Obi')->firstOrFail();
    expect($counter->vendor_id)->toBe($ctx['vendor']->id);

    Livewire::test(ListCounters::class)->assertCanSeeTableRecords([$counter]);

    expect($ctx['vendor']->users()->whereDoesntHave('counterProfile')->pluck('users.id'))->not->toContain($counter->user_id);
});

test('the Counters screen is for managers only, and shows only this shop\'s counters', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    $mine  = stNewCounter($ctx, 'Mine');
    $theirs = stNewCounter($other, 'Theirs');

    stCounterPanelAs($ctx, $ctx['keeperA']);
    expect(CounterResource::canAccess())->toBeFalse();

    stCounterPanelAs($ctx, $ctx['admin']);
    expect(CounterResource::canAccess())->toBeTrue();

    Livewire::test(ListCounters::class)
        ->assertCanSeeTableRecords([$mine['counter']])
        ->assertCanNotSeeTableRecords([$theirs['counter']]);
});

test('the Counters screen: a new code and turning off work from the table', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);
    stCounterPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListCounters::class)
        ->callTableAction('newCode', $made['counter'])
        ->assertNotified()
        ->callTableAction('toggle', $made['counter']);

    expect($made['counter']->fresh()->is_active)->toBeFalse()
        ->and(Hash::check($made['code'], $made['counter']->fresh()->code_hash))->toBeFalse();
});

test('the QR code is the shop\'s sign-in address', function () {
    $ctx = stockTakeContext();
    stCounterPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListCounters::class)
        ->mountAction('qr')
        ->assertMountedActionModalSee(stCounters()->joinUrl($ctx['vendor']));
});

test('a counter whose session has ended is sent back to the QR sign-in, not the staff login', function () {
    $ctx  = stockTakeContext();
    $made = stNewCounter($ctx);
    $join = stCounters()->joinUrl($ctx['vendor']);

    // Signing in leaves a cookie that remembers the way back.
    stJoin($ctx, $made['key'], $made['counter']->id, $made['code'])->assertCookie('stock_take_join');

    $this->post("/stock-take/{$ctx['vendor']->slug}/sign-out");
    $app = "/stock-take/{$ctx['vendor']->slug}";

    // Signed out and no cookie, or a cookie pointing anywhere else: the usual login.
    $this->flushSession();
    $this->get($app)->assertRedirect(route('login'));
    $this->withCookie('stock_take_join', 'https://evil.example/steal')->get($app)->assertRedirect(route('login'));

    // Signed out with the cookie our own sign-in left: back to the QR sign-in.
    $this->withCookie('stock_take_join', $join)->get($app)->assertRedirect($join);
});
