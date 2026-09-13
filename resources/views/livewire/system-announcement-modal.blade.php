<?php

use Livewire\Volt\Component;
use App\Models\SystemAnnouncement;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Request;

new class extends Component
{
    public ?SystemAnnouncement $announcement = null;
    public bool $show = false;

    public function mount()
    {
        $this->checkAnnouncements();
    }

    public function checkAnnouncements()
    {
        $user = auth()->user();
        
        $query = SystemAnnouncement::where('is_active', true)
            ->where(function($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc');
            
        $announcements = $query->get();
        
        foreach ($announcements as $announcement) {
            // Check Path (using wildcards like `vendor/*`)
            if ($announcement->target_path && !request()->is($announcement->target_path)) {
                continue;
            }
            
            // Check Group
            if (!$this->matchesGroup($announcement, $user)) {
                continue;
            }
            
            // Check Dismissal
            if ($user) {
                $pivot = $announcement->users()->where('user_id', $user->id)->first();
                if ($pivot && ($pivot->pivot->dismissed_at || $pivot->pivot->action_taken_at)) {
                    continue; // Already dismissed or acted
                }
            } else {
                $dismissed = Session::get('dismissed_announcements', []);
                if (in_array($announcement->id, $dismissed)) {
                    continue;
                }
            }
            
            // If we made it here, show this announcement
            $this->announcement = $announcement;
            $this->show = true;
            
            // Record view
            if ($user) {
                // Use syncWithoutDetaching to record read_at if not exists
                $announcement->users()->syncWithoutDetaching([
                    $user->id => ['read_at' => now()]
                ]);
            } else {
                // Ensure we don't count multiple views per session
                $viewed = Session::get('viewed_announcements', []);
                if (!in_array($announcement->id, $viewed)) {
                    $announcement->increment('guest_views');
                    Session::push('viewed_announcements', $announcement->id);
                }
            }
            
            break; // Show only one at a time
        }
    }
    
    protected function matchesGroup($announcement, $user)
    {
        if ($announcement->target_group === 'all') {
            return true;
        }
        
        if (!$user) {
            return false; // Guests only see 'all'
        }
        
        if ($announcement->target_group === 'users') {
            return true; // Any logged in user
        }
        
        if ($announcement->target_group === 'vendors') {
            return $user->vendors()->count() > 0 || $user->isSuperAdmin();
        }
        
        if ($announcement->target_group === 'specific_roles') {
            if (!$announcement->target_roles) return false;
            // The user must have ANY of the target roles globally
            return $user->hasAnyRole($announcement->target_roles);
        }
        
        return false;
    }
    
    public function dismiss()
    {
        if ($this->announcement && $this->announcement->is_dismissible && !$this->announcement->requires_action) {
            if (auth()->check()) {
                $this->announcement->users()->syncWithoutDetaching([
                    auth()->id() => ['dismissed_at' => now()]
                ]);
            } else {
                Session::push('dismissed_announcements', $this->announcement->id);
            }
        }
        
        $this->show = false;
    }
    
    public function takeAction()
    {
        if ($this->announcement && $this->announcement->action_url) {
            if (auth()->check()) {
                $this->announcement->users()->syncWithoutDetaching([
                    auth()->id() => ['action_taken_at' => now()]
                ]);
            } else {
                $this->announcement->increment('guest_clicks');
                Session::push('dismissed_announcements', $this->announcement->id);
            }
            
            $this->show = false;
            return $this->redirect($this->announcement->action_url);
        }
    }
};
?>

<div>
    @if($show && $announcement)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all">
                
                {{-- Header with title and optional close button --}}
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $announcement->title }}
                    </h3>
                    
                    @if($announcement->is_dismissible && !$announcement->requires_action)
                        <button wire:click="dismiss" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors">
                            <span class="sr-only">Close</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    @endif
                </div>

                {{-- Body (Rich Text) --}}
                <div class="px-6 py-5 prose prose-sm sm:prose-base dark:prose-invert max-w-none text-gray-700 dark:text-gray-300">
                    {!! $announcement->message !!}
                </div>

                {{-- Footer Action Buttons --}}
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-3">
                    @if($announcement->is_dismissible && !$announcement->requires_action)
                        <button wire:click="dismiss" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Dismiss
                        </button>
                    @endif
                    @if($announcement->action_url)
                        <button wire:click="takeAction" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-lg hover:bg-blue-700 transition-colors shadow-sm">
                            {{ $announcement->action_text ?: 'Click Here' }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>