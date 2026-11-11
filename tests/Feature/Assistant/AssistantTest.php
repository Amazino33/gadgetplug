<?php

declare(strict_types=1);

use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\StartThreadAction;
use App\Filament\Resources\AssistantSettings\AssistantSettingResource;
use App\Filament\Vendor\Pages\AssistantSettings;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('bubble is hidden when any of the three switches is off', function () {
    $user = User::factory()->create();

    // 1. All on -> bubble visible
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);
    $setting = AssistantSetting::create([
        'user_id' => $user->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    expect(AssistantAccess::isEnabledFor($user))->toBeTrue();
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertSee('Ask GadgetPlug')
        ->assertSee('Ask about sales, stock, prices or debts');

    // 2. Global switch off -> hidden
    AssistantGlobalSetting::current()->update(['is_enabled' => false]);
    expect(AssistantAccess::isEnabledFor($user))->toBeFalse();
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertDontSee('Ask about sales, stock, prices or debts');

    // Reset global on
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    // 3. Admin disabled -> hidden
    $setting->update(['admin_disabled' => true, 'vendor_disabled' => false]);
    expect(AssistantAccess::isEnabledFor($user->fresh()))->toBeFalse();
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertDontSee('Ask about sales, stock, prices or debts');

    // 4. Vendor disabled -> hidden
    $setting->update(['admin_disabled' => false, 'vendor_disabled' => true]);
    expect(AssistantAccess::isEnabledFor($user->fresh()))->toBeFalse();
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertDontSee('Ask about sales, stock, prices or debts');
});

test('send with all on saves 2 messages with correct role and store_id snapshot', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $user->id,
        'name' => 'Test Vendor',
    ]);
    $store = Store::where('vendor_id', $vendor->id)->first();
    $store->update(['name' => 'Main Branch', 'created_at' => now()->subDays(30)]);

    AssistantGlobalSetting::current()->update(['is_enabled' => true]);
    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;

    $thread = $startAction->execute($user, 'Order inquiry');
    $thread->update(['store_id' => $store->id]);

    $result = $sendAction->execute($user, $thread, 'Can you help check my stock?', $store->id);

    expect($result)->toHaveKeys(['user_message', 'assistant_message', 'thread'])
        ->and($result['user_message']->role)->toBe('user')
        ->and($result['user_message']->content)->toBe('Can you help check my stock?')
        ->and($result['user_message']->store_id)->toBe($store->id)
        ->and($result['user_message']->user_id)->toBe($user->id)
        ->and($result['assistant_message']->role)->toBe('assistant')
        ->and($result['assistant_message']->content)->toBe('The assistant took too long to respond.')
        ->and($result['assistant_message']->store_id)->toBe($store->id)
        ->and($result['assistant_message']->user_id)->toBe($user->id);

    $this->assertDatabaseCount('assistant_messages', 2);
    expect($thread->fresh()->last_message_at)->not->toBeNull();
});

test('send when no store assigned succeeds with store_id null and reply still saved', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;

    $thread = $startAction->execute($user);
    $result = $sendAction->execute($user, $thread, 'Hello without store');

    expect($result['user_message']->store_id)->toBeNull()
        ->and($result['assistant_message']->store_id)->toBeNull()
        ->and($result['assistant_message']->content)->toBe('You have no stores assigned yet.');

    $this->assertDatabaseCount('assistant_messages', 2);
});

test('empty, whitespace, or >2000 chars messages are rejected with zero rows created (zero messages AND zero threads)', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $sendAction = new SendMessageAction;

    // 1. Attempt sending empty message with NO pre-existing thread
    try {
        $sendAction->execute($user, null, '');
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('content');
    }
    // Verify zero threads and zero messages created
    $this->assertDatabaseCount('assistant_threads', 0);
    $this->assertDatabaseCount('assistant_messages', 0);

    // 2. Whitespace only
    try {
        $sendAction->execute($user, null, "   \n\t   ");
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('content');
    }
    $this->assertDatabaseCount('assistant_threads', 0);
    $this->assertDatabaseCount('assistant_messages', 0);

    // 3. Over 2000 chars
    $tooLong = str_repeat('a', 2001);
    try {
        $sendAction->execute($user, null, $tooLong);
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('content');
    }
    $this->assertDatabaseCount('assistant_threads', 0);
    $this->assertDatabaseCount('assistant_messages', 0);
});

test('vendor A cannot read or write to vendor B threads', function () {
    $vendorAUser = User::factory()->create();
    $vendorBUser = User::factory()->create();

    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;

    $threadB = $startAction->execute($vendorBUser, 'Vendor B secret');

    // Vendor A attempting to send message into Vendor B thread must fail with authorization exception
    expect(fn () => $sendAction->execute($vendorAUser, $threadB, 'Hacking message'))
        ->toThrow(AuthorizationException::class);

    // ChatBubble Livewire reading check: Vendor A cannot select Vendor B thread (aborts 403)
    Livewire::actingAs($vendorAUser)
        ->test(ChatBubble::class)
        ->call('selectThread', $threadB->id)
        ->assertStatus(403);
});

test('admin_disabled = true blocks send even with vendor switch on', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'admin_disabled' => true,
        'vendor_disabled' => false,
    ]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;
    $thread = $startAction->execute($user);

    expect(fn () => $sendAction->execute($user, $thread, 'Hello?'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('assistant_messages', 0);
});

test('global off blocks everyone', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => false]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;
    $thread = $startAction->execute($user);

    expect(fn () => $sendAction->execute($user, $thread, 'Should be blocked globally'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('assistant_messages', 0);
});

test('deleting user cascades threads and messages', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;

    $thread = $startAction->execute($user);
    $sendAction->execute($user, $thread, 'User to delete');

    $this->assertDatabaseCount('assistant_threads', 1);
    $this->assertDatabaseCount('assistant_messages', 2);

    $user->delete();

    $this->assertDatabaseCount('assistant_threads', 0);
    $this->assertDatabaseCount('assistant_messages', 0);
});

test('deleting thread cascades messages', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $startAction = new StartThreadAction;
    $sendAction = new SendMessageAction;

    $thread = $startAction->execute($user);
    $sendAction->execute($user, $thread, 'Thread to delete');

    $this->assertDatabaseCount('assistant_threads', 1);
    $this->assertDatabaseCount('assistant_messages', 2);

    $thread->delete();

    $this->assertDatabaseCount('assistant_threads', 0);
    $this->assertDatabaseCount('assistant_messages', 0);
});

test('AssistantSettingResource lists only vendor-panel users', function () {
    $vendorUser = User::factory()->create();
    Vendor::create([
        'user_id' => $vendorUser->id,
        'name' => 'Real Vendor Shop',
    ]);
    AssistantSetting::create([
        'user_id' => $vendorUser->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $regularUser = User::factory()->create(); // Customer / shopper
    AssistantSetting::create([
        'user_id' => $regularUser->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $adminUser = User::factory()->create(); // Admin
    AssistantSetting::create([
        'user_id' => $adminUser->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $scopedRecords = AssistantSettingResource::getEloquentQuery()->pluck('user_id');

    expect($scopedRecords)->toContain($vendorUser->id)
        ->and($scopedRecords)->not->toContain($regularUser->id)
        ->and($scopedRecords)->not->toContain($adminUser->id);
});

test('2000-char message succeeds at the boundary', function () {
    $user = User::factory()->create();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $sendAction = new SendMessageAction;
    $boundaryContent = str_repeat('b', 2000);

    $result = $sendAction->execute($user, null, $boundaryContent);

    expect($result['user_message']->content)->toBe($boundaryContent)
        ->and(mb_strlen($result['user_message']->content))->toBe(2000)
        ->and($result['assistant_message']->content)->toBe('You have no stores assigned yet.');

    $this->assertDatabaseCount('assistant_messages', 2);
    $this->assertDatabaseCount('assistant_threads', 1);
});

test("vendor A cannot toggle vendor B's admin_disabled", function () {
    $vendorA = User::factory()->create();
    $vendorB = User::factory()->create();

    $shopA = Vendor::create(['user_id' => $vendorA->id, 'name' => 'Shop A']);
    $shopB = Vendor::create(['user_id' => $vendorB->id, 'name' => 'Shop B']);

    $settingB = AssistantSetting::create([
        'user_id' => $vendorB->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    // 1. Vendor A has no authorization to access the admin resource that toggles admin_disabled
    $this->actingAs($vendorA);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(AssistantSettingResource::canAccess())->toBeFalse();

    // 2. Vendor A submitting vendor settings cannot mutate admin_disabled or touch Vendor B
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($shopA);

    Livewire::actingAs($vendorA)
        ->test(AssistantSettings::class)
        ->fillForm([
            'vendor_disabled' => true,
        ])
        ->call('save');

    // Vendor B's settings are completely unchanged
    expect($settingB->fresh()->admin_disabled)->toBeFalse()
        ->and($settingB->fresh()->vendor_disabled)->toBeFalse();

    // Vendor A's own admin_disabled remains false
    $settingA = AssistantSetting::where('user_id', $vendorA->id)->first();
    expect($settingA->admin_disabled)->toBeFalse()
        ->and($settingA->vendor_disabled)->toBeTrue();
});
