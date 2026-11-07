<?php

declare(strict_types=1);

use App\Actions\Assistant\FlagMessageAction;
use App\Actions\Assistant\UnflagMessageAction;
use App\Filament\Resources\AssistantMessages\AssistantMessageResource;
use App\Filament\Resources\AssistantMessages\Pages\ListAssistantMessages;
use App\Filament\Resources\AssistantMessages\Pages\ViewAssistantMessage;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function createSuperAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

test('admin can list all messages across vendors without scoping leak', function () {
    $admin = createSuperAdmin();

    $userA = User::factory()->create(['name' => 'Vendor Alice']);
    $userB = User::factory()->create(['name' => 'Vendor Bob']);

    $threadA = AssistantThread::create(['user_id' => $userA->id]);
    $threadB = AssistantThread::create(['user_id' => $userB->id]);

    $msgA = AssistantMessage::create([
        'thread_id' => $threadA->id,
        'user_id' => $userA->id,
        'role' => 'user',
        'content' => 'Message from Alice',
    ]);

    $msgB = AssistantMessage::create([
        'thread_id' => $threadB->id,
        'user_id' => $userB->id,
        'role' => 'user',
        'content' => 'Message from Bob',
    ]);

    Livewire::actingAs($admin)
        ->test(ListAssistantMessages::class)
        ->assertCanSeeTableRecords([$msgA, $msgB])
        ->assertSee('Message from Alice')
        ->assertSee('Message from Bob');
});

test('admin flags a message and sets all three fields correctly', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Suspicious inquiry content',
    ]);

    $action = new FlagMessageAction;
    $flagged = $action->execute($admin, $message, 'Needs compliance review');

    expect($flagged->isFlagged())->toBeTrue()
        ->and($flagged->flagged_at)->not->toBeNull()
        ->and($flagged->flagged_by)->toBe($admin->id)
        ->and($flagged->flag_note)->toBe('Needs compliance review')
        ->and($flagged->flaggedBy->id)->toBe($admin->id);

    // Overwrite flag test (last write wins)
    $secondAdmin = createSuperAdmin();
    $overwritten = $action->execute($secondAdmin, $message, 'Second review note');

    expect($overwritten->flagged_by)->toBe($secondAdmin->id)
        ->and($overwritten->flag_note)->toBe('Second review note');
});

test('admin unflags a message and nulls all three fields', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Flagged message',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
        'flag_note' => 'Some reason',
    ]);

    expect($message->isFlagged())->toBeTrue();

    $action = new UnflagMessageAction;
    $unflagged = $action->execute($admin, $message);

    expect($unflagged->isFlagged())->toBeFalse()
        ->and($unflagged->flagged_at)->toBeNull()
        ->and($unflagged->flagged_by)->toBeNull()
        ->and($unflagged->flag_note)->toBeNull();
});

test('flag note > 280 chars is rejected and message remains unchanged', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Clean message',
    ]);

    $action = new FlagMessageAction;
    $tooLong = str_repeat('x', 281);

    try {
        $action->execute($admin, $message, $tooLong);
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('flag_note');
    }

    $message->refresh();
    expect($message->isFlagged())->toBeFalse()
        ->and($message->flagged_at)->toBeNull()
        ->and($message->flagged_by)->toBeNull();
});

test('non-admin hitting the flag Action receives 403 and message remains untouched', function () {
    $vendorUser = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $vendorUser->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $vendorUser->id,
        'role' => 'user',
        'content' => 'Secret message',
    ]);

    $flagAction = new FlagMessageAction;
    $unflagAction = new UnflagMessageAction;

    expect(fn () => $flagAction->execute($vendorUser, $message, 'Unauthorized attempt'))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $unflagAction->execute($vendorUser, $message))
        ->toThrow(AuthorizationException::class);

    $message->refresh();
    expect($message->isFlagged())->toBeFalse();
});

test('vendor panel and chat bubble expose no flag data', function () {
    $admin = createSuperAdmin();
    $vendorUser = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $vendorUser->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $vendorUser->id,
        'role' => 'user',
        'content' => 'Confidential vendor talk',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
        'flag_note' => 'Confidential investigation note',
    ]);

    // 1. Non-admin cannot access AssistantMessageResource
    expect(AssistantMessageResource::canAccess())->toBeFalse();

    // 2. ChatBubble does not render flag note or flag status
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);
    Livewire::actingAs($vendorUser)
        ->test(ChatBubble::class)
        ->set('activeThreadId', $thread->id)
        ->assertSee('Confidential vendor talk')
        ->assertDontSee('Confidential investigation note')
        ->assertDontSee('Flagged');
});

test('flagged-only filter returns only flagged rows', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $flagged = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'This is flagged',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
        'flag_note' => 'Spam',
    ]);

    $unflagged = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'This is normal',
    ]);

    Livewire::actingAs($admin)
        ->test(ListAssistantMessages::class)
        ->filterTable('flagged', true)
        ->assertCanSeeTableRecords([$flagged])
        ->assertCanNotSeeTableRecords([$unflagged]);
});

test('vendor, store, role, and flagged filters compose with AND', function () {
    $admin = createSuperAdmin();
    $vendorUserA = User::factory()->create();
    $vendorUserB = User::factory()->create();

    $vendorA = Vendor::create(['user_id' => $vendorUserA->id, 'name' => 'Vendor A']);
    $storeA = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Store Branch A']);

    $threadA = AssistantThread::create(['user_id' => $vendorUserA->id]);
    $threadB = AssistantThread::create(['user_id' => $vendorUserB->id]);

    // Matching record: user A, store A, role user, flagged
    $match = AssistantMessage::create([
        'thread_id' => $threadA->id,
        'user_id' => $vendorUserA->id,
        'store_id' => $storeA->id,
        'role' => 'user',
        'content' => 'Perfect match',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
    ]);

    // Mismatched role (assistant)
    $wrongRole = AssistantMessage::create([
        'thread_id' => $threadA->id,
        'user_id' => $vendorUserA->id,
        'store_id' => $storeA->id,
        'role' => 'assistant',
        'content' => 'Wrong role match',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
    ]);

    // Mismatched user (user B)
    $wrongUser = AssistantMessage::create([
        'thread_id' => $threadB->id,
        'user_id' => $vendorUserB->id,
        'store_id' => $storeA->id,
        'role' => 'user',
        'content' => 'Wrong user match',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(ListAssistantMessages::class)
        ->filterTable('user_id', $vendorUserA->id)
        ->filterTable('store_id', $storeA->id)
        ->filterTable('role', 'user')
        ->filterTable('flagged', true)
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongRole, $wrongUser]);
});

test('thread context page returns surrounding messages in correct chronological order', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    // Create 10 messages in chronological order
    $messages = [];
    for ($i = 1; $i <= 10; $i++) {
        $messages[] = AssistantMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'role' => $i % 2 === 0 ? 'assistant' : 'user',
            'content' => "Thread sequence #{$i}",
        ]);
    }

    $targetMessage = $messages[5]; // Message #6

    Livewire::actingAs($admin)
        ->test(ViewAssistantMessage::class, ['record' => $targetMessage->id])
        ->assertSee('Thread sequence #1')
        ->assertSee('Thread sequence #6')
        ->assertSee('Thread sequence #10')
        ->assertSee("Target Message #{$targetMessage->id}");
});

test('deleting the flagging admin nulls flagged_by but keeps flagged_at', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Audit record',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
        'flag_note' => 'Retained note',
    ]);

    expect($message->flagged_by)->toBe($admin->id);

    // Delete the admin
    $admin->delete();

    $message->refresh();
    expect($message->flagged_by)->toBeNull()
        ->and($message->flagged_at)->not->toBeNull()
        ->and($message->flag_note)->toBe('Retained note')
        ->and($message->isFlagged())->toBeTrue();
});

test('deleting a message via cascade still works without FK breakage', function () {
    $admin = createSuperAdmin();
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Message to cascade delete',
        'flagged_at' => now(),
        'flagged_by' => $admin->id,
    ]);

    expect(AssistantMessage::count())->toBe(1);

    // Deleting the thread cascades to the message
    $thread->delete();
    expect(AssistantMessage::count())->toBe(0);
});
