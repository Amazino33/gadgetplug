<?php

declare(strict_types=1);

use App\Enums\Assistant\AnswerStatus;
use App\Filament\Pages\AssistantGlobalSettingsPage;
use App\Filament\Pages\AssistantVendorUsageReport;
use App\Filament\Resources\AssistantUnansweredQuestions\AssistantUnansweredQuestionResource;
use App\Filament\Resources\AssistantUnansweredQuestions\Pages\ListAssistantUnansweredQuestions;
use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantUnansweredQuestion;
use App\Models\AssistantVendorDailyUsage;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * Phase 1.3 admin screens: the unanswered-question review list, usage per
 * business, and the cap settings (the platform default and the per-business
 * override). Platform staff only, like the other Assistant screens; a
 * business owner is refused by page guard and by direct URL.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function adminVisSuperAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

/** @return array{owner: User, vendor: Vendor, question: AssistantUnansweredQuestion} */
function adminVisData(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Review Phones']);

    $question = AssistantUnansweredQuestion::create([
        'vendor_id' => $vendor->id, 'user_id' => $owner->id,
        'question' => 'How many phones did we transfer to Ikeja?', 'answer' => "I can't check that yet.",
        'reason' => AnswerStatus::CannotAnswer,
    ]);

    AssistantVendorDailyUsage::create([
        'vendor_id' => $vendor->id, 'date' => CarbonImmutable::parse('2026-11-06'),
        'question_count' => 37, 'input_tokens' => 5000, 'output_tokens' => 300, 'cost_micros' => 1234,
    ]);

    return ['owner' => $owner, 'vendor' => $vendor, 'question' => $question];
}

test('the platform admin sees unanswered questions and can mark one reviewed with a note', function () {
    $admin = adminVisSuperAdmin();
    $data = adminVisData();

    $this->actingAs($admin)->get('/admin/assistant-unanswered-questions')->assertOk()
        ->assertSee('How many phones did we transfer to Ikeja?');

    Livewire::actingAs($admin)
        ->test(ListAssistantUnansweredQuestions::class)
        ->assertCanSeeTableRecords([$data['question']])
        ->callTableAction('review', $data['question'], ['review_note' => 'Needs a transfers tool.']);

    $reviewed = $data['question']->fresh();

    expect($reviewed->reviewed_at)->not->toBeNull()
        ->and($reviewed->reviewed_by)->toBe($admin->id)
        ->and($reviewed->review_note)->toBe('Needs a transfers tool.');

    // Reviewed ones leave the default (open) list.
    Livewire::actingAs($admin)
        ->test(ListAssistantUnansweredQuestions::class)
        ->assertCanNotSeeTableRecords([$reviewed]);
});

test('a business owner cannot open the unanswered list or the usage report, by guard or by URL', function () {
    $data = adminVisData();

    $this->actingAs($data['owner']);

    expect(AssistantUnansweredQuestionResource::canAccess())->toBeFalse()
        ->and(AssistantVendorUsageReport::canAccess())->toBeFalse();

    // The panel turns a non-admin away before routing (a redirect), and the
    // pages' own canAccess() says no however they were reached.
    $this->get('/admin/assistant-unanswered-questions')->assertRedirect();
    $this->get('/admin/assistant-vendor-usage-report')->assertRedirect();
});

test('usage by business shows each business\'s questions for the day against its cap', function () {
    $admin = adminVisSuperAdmin();
    $data = adminVisData();
    $data['vendor']->update(['assistant_daily_cap' => 40]);

    $this->actingAs($admin)->get('/admin/assistant-vendor-usage-report')->assertOk()
        ->assertSee('Review Phones')
        ->assertSee('of 40');
});

test('the platform default questions per business is a setting', function () {
    $admin = adminVisSuperAdmin();
    AssistantGlobalSetting::current();

    Livewire::actingAs($admin)
        ->test(AssistantGlobalSettingsPage::class)
        ->assertSet('data.daily_vendor_cap', 100)
        ->set('data.daily_vendor_cap', 150)
        ->call('save');

    expect(AssistantGlobalSetting::current()->daily_vendor_cap)->toBe(150);
});

test('the admin sets one business\'s own number on its Vendor page; empty means the default', function () {
    $admin = adminVisSuperAdmin();
    $data = adminVisData();

    Livewire::actingAs($admin)
        ->test(EditVendor::class, ['record' => $data['vendor']->getRouteKey()])
        ->fillForm(['assistant_daily_cap' => 250])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($data['vendor']->fresh()->assistant_daily_cap)->toBe(250);

    Livewire::actingAs($admin)
        ->test(EditVendor::class, ['record' => $data['vendor']->getRouteKey()])
        ->fillForm(['assistant_daily_cap' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($data['vendor']->fresh()->assistant_daily_cap)->toBeNull();
});
