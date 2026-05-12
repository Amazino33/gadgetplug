<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

new class extends Component {
    public string $name    = '';
    public string $email   = '';
    public string $phone   = '';
    public string $address = '';

    public string $currentPassword = '';
    public string $newPassword     = '';
    public string $confirmPassword = '';

    public bool $profileSaved  = false;
    public bool $passwordSaved = false;
    public ?string $passwordError = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->name    = $user->name;
        $this->email   = $user->email;
        $this->phone   = $user->phone   ?? '';
        $this->address = $user->address ?? '';
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255|unique:users,email,' . auth()->id(),
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        auth()->user()->update([
            'name'    => $this->name,
            'email'   => $this->email,
            'phone'   => $this->phone  ?: null,
            'address' => $this->address ?: null,
        ]);

        $this->profileSaved = true;
        $this->dispatch('profile-saved');
    }

    public function changePassword(): void
    {
        $this->passwordError = null;

        $this->validate([
            'currentPassword' => 'required|string',
            'newPassword'     => ['required', 'string', Password::min(8)],
            'confirmPassword' => 'required|same:newPassword',
        ]);

        if (! Hash::check($this->currentPassword, auth()->user()->password)) {
            $this->passwordError = 'Current password is incorrect.';
            return;
        }

        auth()->user()->update(['password' => Hash::make($this->newPassword)]);

        $this->currentPassword = '';
        $this->newPassword     = '';
        $this->confirmPassword = '';
        $this->passwordSaved   = true;
    }
}; ?>

<div>
<x-layouts.account active="account.profile">

    <div class="space-y-5">

        {{-- Profile card --}}
        <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Personal Information</h2>

            @if ($profileSaved)
            <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-2.5 text-[12px] text-brand font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Profile updated successfully.
            </div>
            @endif

            <form wire:submit="saveProfile" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Full Name</label>
                        <input wire:model="name" type="text" placeholder="Your full name"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('name') border-red-400 @enderror">
                        @error('name')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Email Address</label>
                        <input wire:model="email" type="email" placeholder="you@example.com"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('email') border-red-400 @enderror">
                        @error('email')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Phone Number</label>
                        <input wire:model="phone" type="tel" placeholder="+234 800 000 0000"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Delivery Address</label>
                        <input wire:model="address" type="text" placeholder="Street, City, State"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                    </div>
                </div>
                <div class="flex justify-end pt-1">
                    <button type="submit"
                        class="h-10 px-6 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center gap-2">
                        <span wire:loading.remove wire:target="saveProfile">Save Changes</span>
                        <span wire:loading wire:target="saveProfile">Saving…</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- Password card --}}
        <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Change Password</h2>

            @if ($passwordSaved)
            <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-2.5 text-[12px] text-brand font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Password changed successfully.
            </div>
            @endif

            @if ($passwordError)
            <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-2.5 text-[12px] text-red-600 dark:text-red-400 font-semibold">
                {{ $passwordError }}
            </div>
            @endif

            <form wire:submit="changePassword" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Current Password</label>
                    <input wire:model="currentPassword" type="password"
                        class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('currentPassword') border-red-400 @enderror">
                    @error('currentPassword')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">New Password</label>
                        <input wire:model="newPassword" type="password"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('newPassword') border-red-400 @enderror">
                        @error('newPassword')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Confirm New Password</label>
                        <input wire:model="confirmPassword" type="password"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('confirmPassword') border-red-400 @enderror">
                        @error('confirmPassword')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex justify-end pt-1">
                    <button type="submit"
                        class="h-10 px-6 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center gap-2">
                        <span wire:loading.remove wire:target="changePassword">Update Password</span>
                        <span wire:loading wire:target="changePassword">Updating…</span>
                    </button>
                </div>
            </form>
        </div>

    </div>

</x-layouts.account>
</div>
