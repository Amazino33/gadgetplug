<?php

use App\Livewire\Engagement\TipPopup;
use App\Models\SystemAnnouncement;
use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\TipEligibility;
use App\Support\Engagement\TipFeedback;
use App\Support\Engagement\TipMessage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function popupFor(User $user, ?Vendor $vendor, string $route = 'filament.vendor.pages.dashboard', ?int $previewTipId = null)
{
    actInTipsPanel($user, $vendor);

    return Livewire::withoutLazyLoading()->test(TipPopup::class, [
        'routeName' => $route,
        'previewTipId' => $previewTipId,
    ]);
}

function debtTip(array $overrides = []): Tip
{
    return makeTip(array_merge([
        'feature_key' => 'customer_debts',
        'title' => 'Did you know customers can buy on credit?',
        'message' => 'Choose Debt (Owing) at checkout in Sales Point, then see exactly who owes you here.',
        'button_label' => 'See who owes you',
    ], $overrides));
}

function tipManager(): User
{
    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage_in_app_tips', 'guard_name' => 'web']);
    setPermissionsTeamId(null);
    $user->givePermissionTo('manage_in_app_tips');

    return $user;
}

// ── Showing ──────────────────────────────────────────────────────────────────

it('renders the eligible tip, and logs nothing until the browser shows it', function () {
    $ctx = engagementVendor();
    $tip = debtTip();

    popupFor($ctx['storekeeper'], $ctx['vendor'])
        ->assertSet('tipId', $tip->id)
        ->assertSee('Did you know customers can buy on credit?')
        ->assertSee('See who owes you')
        ->assertSee('Was this helpful?');

    expect(TipEvent::count())->toBe(0);
});

it('renders nothing when no tip is eligible', function () {
    $ctx = engagementVendor();
    debtTip(['status' => Tip::STATUS_DRAFT]);

    popupFor($ctx['storekeeper'], $ctx['vendor'])
        ->assertSet('tipId', null)
        ->assertDontSee('Did you know');
});

it('renders nothing on a create or edit page', function () {
    $ctx = engagementVendor();
    debtTip();

    popupFor($ctx['owner'], $ctx['vendor'], 'filament.vendor.resources.products.create')->assertSet('tipId', null);
});

it('logs shown once, however often the browser reports it', function () {
    $ctx = engagementVendor();
    $tip = debtTip();

    popupFor($ctx['storekeeper'], $ctx['vendor'])->call('markShown')->call('markShown');

    expect(TipEvent::where('tip_id', $tip->id)->where('event', TipEvent::SHOWN)->count())->toBe(1)
        ->and(TipEvent::first())->user_id->toBe($ctx['storekeeper']->id)
        ->vendor_id->toBe($ctx['vendor']->id)
        ->route->toBe('filament.vendor.pages.dashboard');
});

// ── Acting on it ─────────────────────────────────────────────────────────────

it("logs a tap and sends the person to their own business's page", function () {
    $ctx = engagementVendor();
    $tip = debtTip();

    popupFor($ctx['storekeeper'], $ctx['vendor'])
        ->call('markShown')
        ->call('pressButton')
        ->assertRedirect(url('/plug/'.$ctx['vendor']->slug.'/customer-debts'));

    expect(TipEvent::where('tip_id', $tip->id)->orderBy('id')->pluck('event')->all())->toBe([TipEvent::SHOWN, TipEvent::CLICKED])
        ->and(app(TipEligibility::class)->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('retired');
});

it('retires the tip on "Don\'t show again"', function () {
    $ctx = engagementVendor();
    $tip = debtTip(['frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 0]);

    popupFor($ctx['storekeeper'], $ctx['vendor'])->call('markShown')->call('dismiss');

    expect(TipEvent::where('event', TipEvent::DISMISSED)->count())->toBe(1)
        ->and(app(TipEligibility::class)->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('retired');
});

it('counts "Not now" as seen and starts the cooldown', function () {
    $ctx = engagementVendor();
    $tip = debtTip(['frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 3]);

    popupFor($ctx['storekeeper'], $ctx['vendor'])->call('notNow');

    expect(TipEvent::where('tip_id', $tip->id)->orderBy('id')->pluck('event')->all())->toBe([TipEvent::SHOWN, TipEvent::SNOOZED]);

    $this->travel(1)->days();
    expect(app(TipEligibility::class)->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('cooldown');
});

it('records thumbs, with the latest answer counting', function () {
    $ctx = engagementVendor();
    $tip = debtTip();

    popupFor($ctx['storekeeper'], $ctx['vendor'])
        ->call('rate', 'yes') // before it is shown: ignored
        ->call('markShown')
        ->call('rate', 'yes')
        ->call('rate', 'no')
        ->call('rate', 'maybe'); // not an answer: ignored

    expect(TipEvent::whereIn('event', [TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])->count())->toBe(2)
        ->and(TipFeedback::forTip($tip->id))->toMatchArray(['yes' => 0, 'no' => 1, 'percent' => 0])
        ->and(TipFeedback::answerOf($ctx['storekeeper']->id, $ctx['vendor']->id, $tip->id))->toBe('no');
});

it('logs a video play once', function () {
    $ctx = engagementVendor();
    debtTip();

    popupFor($ctx['storekeeper'], $ctx['vendor'])->call('markShown')->call('videoPlayed')->call('videoPlayed');

    expect(TipEvent::where('event', TipEvent::VIDEO_PLAYED)->count())->toBe(1);
});

it('does nothing for actions when there is no tip', function () {
    $ctx = engagementVendor();

    popupFor($ctx['owner'], $ctx['vendor'])->call('markShown')->call('pressButton')->call('dismiss')->call('notNow');

    expect(TipEvent::count())->toBe(0);
});

it('opens the assistant instead of navigating for the Ask GadgetPlug feature', function () {
    $ctx = engagementVendor();
    makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'feature_key' => 'ai_assistant', 'button_label' => 'Try it now']);

    popupFor($ctx['owner'], $ctx['vendor'])
        ->assertSet('buttonUrl', null)
        ->assertSet('buttonEvent', 'gp-open-assistant')
        ->call('pressButton')
        ->assertNoRedirect();

    expect(TipEvent::where('event', TipEvent::CLICKED)->count())->toBe(1);
});

it('gives the chat bubble the listener the assistant button needs', function () {
    // Decision 6: one line in the bubble's markup, nothing else in the assistant.
    expect(file_get_contents(resource_path('views/livewire/assistant/chat-bubble.blade.php')))
        ->toContain('x-on:gp-open-assistant.window="open = true"');
});

it('sends the empty-debts moment tip to Sales Point, for people who can use the till', function () {
    $ctx = engagementVendor();
    makeTip([
        'feature_key' => 'customer_debts', 'button_label' => 'Open Sales Point', 'button_route' => 'pos.vendor',
        'moment' => 'debtors_page_empty_visit',
    ]);

    popupFor($ctx['storekeeper'], $ctx['vendor'], 'filament.vendor.resources.customer-debts.index')
        ->assertSet('buttonUrl', url('/sales-point/'.$ctx['vendor']->slug));
});

// ── Preview as me ────────────────────────────────────────────────────────────

it('previews any tip for a tip manager, whatever its status, and records nothing', function () {
    $ctx = engagementVendor();
    $manager = tipManager();
    $ctx['vendor']->users()->attach($manager->id);
    $tip = debtTip(['status' => Tip::STATUS_DRAFT, 'button_route' => 'filament.vendor.pages.dashboard']);

    popupFor($manager, $ctx['vendor'], previewTipId: $tip->id)
        ->assertSet('tipId', $tip->id)
        ->assertSet('preview', true)
        ->assertSee('Preview: nothing is recorded')
        ->call('markShown')
        ->call('rate', 'yes')
        ->call('pressButton');

    expect(TipEvent::count())->toBe(0);
});

it('ignores a preview request from anyone who cannot manage tips', function () {
    $ctx = engagementVendor();
    $tip = debtTip(['status' => Tip::STATUS_DRAFT]);

    popupFor($ctx['owner'], $ctx['vendor'], previewTipId: $tip->id)
        ->assertSet('tipId', null)
        ->assertSet('preview', false);
});

// ── The render hook ──────────────────────────────────────────────────────────

it('adds the pop-up to the page only while some tip is live', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);

    expect(view('engagement.tip-hook')->render())->not->toContain('gpTip');

    debtTip();

    expect(view('engagement.tip-hook')->render())->toContain('window.gpTip')->toContain('engagement.tip-popup');
});

it('keeps the markers the pop-up checks for an announcement on the page', function () {
    // Decision 8 is read-only: the pop-up looks for the announcement modal's
    // own markup. If that markup changes, this fails instead of tips
    // silently stacking on top of announcements.
    $user = User::factory()->create();
    $this->actingAs($user);
    SystemAnnouncement::create([
        'title' => 'Maintenance tonight', 'message' => 'Back at 6am.', 'target_group' => 'users',
        'is_active' => true, 'is_dismissible' => true,
    ]);

    $html = Livewire::test('system-announcement-modal')->html();

    expect($html)->toContain('wire:name="system-announcement-modal"')
        ->and($html)->toMatch('/class="fixed inset-0/');

    expect(file_get_contents(resource_path('views/engagement/tip-scripts.blade.php')))
        ->toContain('[wire\\\\:name="system-announcement-modal"] .fixed')
        ->toContain('.driver-popover')
        ->toContain('.fi-modal-open');
});

// ── Message safety ───────────────────────────────────────────────────────────

it('keeps bold and safe links in a message and strips everything else', function () {
    $html = TipMessage::html(
        '<p>Use <strong>credit</strong> <a href="https://gadgetplug.com.ng/help" onclick="x()">here</a>'
        .'<script>alert(1)</script><a href="javascript:alert(1)">bad</a><img src=x onerror=y></p>'
    );

    expect($html)->toBe('<p>Use <strong>credit</strong> <a href="https://gadgetplug.com.ng/help" rel="noopener">here</a>bad</p>') // a script's contents go with it
        ->and(TipMessage::html("Plain <b>text</b>\nline two"))->toContain('<strong>text</strong>')
        ->and(TipMessage::html("Two\nlines"))->toBe("Two<br>\nlines");
});
