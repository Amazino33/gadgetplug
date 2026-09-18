<?php

use App\Models\SystemAnnouncement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function dismissibleAnnouncement(array $attrs = []): SystemAnnouncement
{
    return SystemAnnouncement::create(array_merge([
        'title'           => 'Make sure you record all your sales including the Kitchen',
        'message'         => '<p>All records needs to be recorded.</p>',
        'target_group'    => 'all',
        'is_dismissible'  => true,
        'requires_action' => false,
        'is_active'       => true,
    ], $attrs));
}

test('a guest sees a dismissible announcement and dismissing it hides the modal', function () {
    $announcement = dismissibleAnnouncement();

    Volt::test('system-announcement-modal')
        ->assertSet('show', true)
        ->call('dismiss')
        ->assertSet('show', false);

    expect(Session::get('dismissed_announcements'))->toContain($announcement->id);
});

test('a dismissed announcement stays gone on the next page load', function () {
    dismissibleAnnouncement();

    Volt::test('system-announcement-modal')->call('dismiss');

    // A fresh mount, the way the next page view builds the component.
    Volt::test('system-announcement-modal')->assertSet('show', false);
});
