<?php

use Livewire\Component;
use Livewire\Attributes\Rule;
use Illuminate\Support\Facades\Http;
use App\Models\ClientRequest;

new class extends Component
{
    #[Rule('required|min:5|max:1000')]
    public $clientRequest = '';

    public $successMessage = '';

    public function submitRequest()
    {
        $this->validate();

        $user = auth()->user();
        $name = $user ? $user->name : 'Guest Visitor';
        $email = $user ? $user->email : 'No email provided';

        ClientRequest::create([
            'client_name' => $name,
            'client_email' => $email,
            'request_text' => $this->clientRequest,
            'is_completed' => false,
        ]);

        $token = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');
        
        // Switched to HTML tags to prevent user-input crashes
        $text = "💬 <b>New To-do Request</b>\n";
        $text .= "👤 <b>Client:</b> {$name}\n\n";
        $text .= "📝 <b>Task:</b>\n" . htmlspecialchars($this->clientRequest);

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if ($response->successful()) {
            $this->reset('clientRequest');
            $this->successMessage = 'Sent! We will check our inventory and get back to you.';
        } else {
            // This will now print the EXACT error from Telegram on your screen
            $this->addError('clientRequest', 'Telegram Error: ' . $response->body());
        }
    }
};
?>

<div x-data="{ open: false }" style="position: fixed; bottom: 24px; right: 24px; z-index: 99999; font-family: sans-serif;">

    <!-- Chat Window Panel -->
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        style="display: none; background: white; width: 320px; border-radius: 16px; box-shadow: 0 25px 50px rgba(0,0,0,0.2); border: 1px solid #f0f0f0; margin-bottom: 16px; overflow: hidden;"
    >
        <!-- Header -->
        <div style="background: #2563eb; padding: 16px; color: white; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-weight: 700; font-size: 14px;">GadgetPlug Support</div>
                <div style="font-size: 12px; color: #bfdbfe;">Usually replies instantly</div>
            </div>
            <button @click="open = false" style="background: none; border: none; color: white; cursor: pointer; padding: 4px;">
                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div style="padding: 16px; background: #f9fafb;">
            @if ($successMessage)
                <div style="margin-bottom: 16px; padding: 12px; background: #dcfce7; border: 1px solid #4ade80; color: #166534; font-size: 14px; border-radius: 8px;">
                    {{ $successMessage }}
                </div>
            @else
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 12px; text-align: center;">Send us a message below.</p>

                <form wire:submit="submitRequest">
                    <div style="margin-bottom: 12px;">
                        <textarea
                            wire:model="clientRequest"
                            rows="3"
                            style="width: 100%; font-size: 14px; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; resize: none; box-sizing: border-box; outline: none; font-family: sans-serif;"
                            placeholder="I'm looking for..."
                        ></textarea>

                        @error('clientRequest')
                            <span style="color: #ef4444; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        style="width: 100%; background: #2563eb; color: white; font-weight: 700; padding: 10px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; display: flex; justify-content: center; align-items: center;"
                        wire:loading.attr="disabled"
                        wire:target="submitRequest"
                    >
                        <span wire:loading.remove wire:target="submitRequest">Send Message</span>
                        <span wire:loading wire:target="submitRequest">
                            <svg style="animation: spin 1s linear infinite; width: 16px; height: 16px; margin-right: 8px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Sending...
                        </span>
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- Floating Toggle Button -->
    <button
        @click="open = !open"
        style="background: #2563eb; color: white; border-radius: 9999px; padding: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; margin-left: auto; transition: transform 0.2s;"
        onmouseover="this.style.transform='scale(1.05)'"
        onmouseout="this.style.transform='scale(1)'"
    >
        <svg x-show="!open" style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
        </svg>
        <svg x-show="open" style="display: none; width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </button>

    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</div>