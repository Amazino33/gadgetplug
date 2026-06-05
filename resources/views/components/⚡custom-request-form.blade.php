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

        $user  = auth()->user();
        $name  = $user ? $user->name  : 'Guest Visitor';
        $email = $user ? $user->email : 'No email provided';

        ClientRequest::create([
            'client_name'  => $name,
            'client_email' => $email,
            'request_text' => $this->clientRequest,
            'is_completed' => false,
        ]);

        $token  = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');

        $text  = "💬 <b>New To-do Request</b>\n";
        $text .= "👤 <b>Client:</b> {$name}\n\n";
        $text .= "📝 <b>Task:</b>\n" . htmlspecialchars($this->clientRequest);

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);

        if ($response->successful()) {
            $this->reset('clientRequest');
            $this->successMessage = 'Sent! We will check our inventory and get back to you.';
        } else {
            $this->addError('clientRequest', 'Telegram Error: ' . $response->body());
        }
    }
};
?>

<div x-data="{ open: false }" class="fixed bottom-6 right-6 z-99999 font-sans">

    {{-- Chat window --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        class="mb-4 w-80 rounded-2xl overflow-hidden shadow-2xl border border-gray-200 dark:border-[#2a3a2a] bg-white dark:bg-[#162016]"
        style="display: none;"
    >
        {{-- Header --}}
        <div class="bg-blue-600 dark:bg-blue-700 px-4 py-3.5 flex items-center justify-between">
            <div>
                <p class="text-white font-bold text-sm">GadgetPlug Support</p>
                <p class="text-blue-200 text-xs mt-0.5">Usually replies instantly</p>
            </div>
            <button @click="open = false"
                class="text-white/80 hover:text-white transition-colors p-1 rounded-lg hover:bg-white/10">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Body --}}
        <div class="p-4 bg-gray-50 dark:bg-[#0d1a0d]">
            @if ($successMessage)
                <div class="flex items-start gap-2.5 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl px-4 py-3">
                    <svg class="w-4 h-4 text-green-600 dark:text-green-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    <p class="text-sm text-green-700 dark:text-green-400 font-medium">{{ $successMessage }}</p>
                </div>
            @else
                <p class="text-xs text-gray-500 dark:text-[#7a9e7c] mb-3 text-center">Send us a message below.</p>

                <form wire:submit="submitRequest" class="space-y-3">
                    <div>
                        <textarea
                            wire:model="clientRequest"
                            rows="3"
                            placeholder="I'm looking for…"
                            class="w-full text-sm rounded-xl px-3.5 py-2.5 resize-none outline-none font-sans
                                   bg-white dark:bg-[#162016]
                                   border border-gray-200 dark:border-[#2a3a2a]
                                   text-gray-800 dark:text-[#e8f5e9]
                                   placeholder:text-gray-400 dark:placeholder:text-[#4a6a4c]
                                   focus:border-blue-500 dark:focus:border-blue-500
                                   transition-colors"
                        ></textarea>

                        @error('clientRequest')
                            <p class="text-red-500 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submitRequest"
                        class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60
                               text-white font-bold text-sm px-4 py-2.5 rounded-xl border-none cursor-pointer transition-colors"
                    >
                        <span wire:loading.remove wire:target="submitRequest">Send Message</span>
                        <span wire:loading wire:target="submitRequest" class="flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            Sending…
                        </span>
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Floating toggle button --}}
    <button
        @click="open = !open"
        class="ml-auto flex items-center justify-center w-14 h-14 rounded-full
               bg-blue-600 hover:bg-blue-700 active:scale-95
               text-white shadow-xl border-none cursor-pointer transition-all duration-200"
    >
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
        </svg>
        <svg x-show="open" style="display:none;" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </button>

</div>
