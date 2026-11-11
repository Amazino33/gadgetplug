<div>
    @if ($progress)
        <aside class="gp-learn-card-mini" aria-labelledby="gp-learn-mini-heading">
            <div class="gp-learn-card-mini-body">
                <p id="gp-learn-mini-heading" class="gp-learn-card-mini-title">
                    Learn GadgetPlug
                    @if ($unread > 0)
                        <span class="gp-learn-new-count">{{ $unread }} new</span>
                    @endif
                </p>
                <p class="gp-learn-card-mini-text">You’ve used <strong>{{ $progress['used'] }}</strong> of {{ $progress['total'] }} features</p>
                <div
                    class="gp-progress"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="{{ $progress['total'] }}"
                    aria-valuenow="{{ $progress['used'] }}"
                    aria-labelledby="gp-learn-mini-heading"
                ><span style="width: {{ $progress['percent'] }}%"></span></div>
                <a href="{{ $url }}" class="gp-tip-link gp-learn-card-mini-link">See what else it can do</a>
            </div>
            <button type="button" class="gp-tip-close" aria-label="Hide this card" wire:click="dismiss">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
            </button>
        </aside>
    @endif
</div>
