<?php

use Livewire\Component;
use Livewire\Attributes\Rule;
use Illuminate\Support\Facades\Http;

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

        $token = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');
        
        // Switched to HTML tags to prevent user-input crashes
        $text = "💬 <b>New Floating Chat Request</b>\n";
        $text .= "👤 <b>Client:</b> {$name}\n";
        $text .= "📧 <b>Email:</b> {$email}\n\n";
        $text .= "📝 <b>Message:</b>\n" . htmlspecialchars($this->clientRequest);

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

<div x-data="{ open: false }" class="fixed bottom-6 right-6 z-50 font-sans">
    
    <!-- Chat Window Panel -->
    <div 
        x-show="open" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        class="bg-white w-80 rounded-2xl shadow-2xl border border-gray-100 mb-4 overflow-hidden"
        style="display: none;"
    >
        <!-- Chat Header -->
        <div class="bg-blue-600 p-4 text-white flex justify-between items-center">
            <div>
                <h3 class="font-bold text-sm">GadgetPlug Support</h3>
                <p class="text-xs text-blue-200">Usually replies instantly</p>
            </div>
            <button @click="open = false" class="text-white hover:text-gray-200 focus:outline-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Chat Body -->
        <div class="p-4 bg-gray-50">
            @if ($successMessage)
                <div class="mb-4 p-3 bg-green-100 border border-green-400 text-green-700 text-sm rounded-lg">
                    {{ $successMessage }}
                </div>
            @else
                <p class="text-xs text-gray-500 mb-3 text-center">Send us a message below.</p>
                
                <form wire:submit="submitRequest">
                    <div class="mb-3">
                        <textarea 
                            wire:model="clientRequest" 
                            rows="3" 
                            class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 resize-none p-3" 
                            placeholder="I'm looking for..."></textarea>
                        
                        @error('clientRequest') 
                            <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> 
                        @enderror
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none transition-colors flex justify-center items-center"
                        wire:loading.attr="disabled"
                        wire:target="submitRequest"
                    >
                        <span wire:loading.remove wire:target="submitRequest">Send Message</span>
                        <span wire:loading wire:target="submitRequest" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
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
        class="bg-blue-600 hover:bg-blue-700 text-white rounded-full p-4 shadow-lg focus:outline-none transition-transform hover:scale-105 flex items-center justify-center ml-auto"
    >
        <!-- Open Icon (Chat Bubble) -->
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
        </svg>
        <!-- Close Icon (X) -->
        <svg x-show="open" style="display: none;" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </button>
</div>