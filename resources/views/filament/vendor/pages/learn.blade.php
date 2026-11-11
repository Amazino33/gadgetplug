{{--
    Learn GadgetPlug: every feature this person can open, with a tick for the
    ones used (FeatureUsage, the registry's first use), plus What's new.

    Honest ticks: owners are judged on the whole business; staff on their own
    use, except features whose tables don't record who did them, which say
    "Whole business" on the card.
--}}
<x-filament-panels::page>
    @include('engagement.tip-scripts')
    @include('engagement.learn-scripts')

    <div class="gp-learn">
        <section class="gp-learn-progress" aria-labelledby="gp-learn-progress-heading">
            <h2 id="gp-learn-progress-heading" class="gp-learn-progress-text">
                You’ve used <strong>{{ $progress['used'] }}</strong> of {{ $progress['total'] }} features
            </h2>
            <div
                class="gp-progress"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="{{ $progress['total'] }}"
                aria-valuenow="{{ $progress['used'] }}"
                aria-labelledby="gp-learn-progress-heading"
            ><span style="width: {{ $progress['percent'] }}%"></span></div>
            <p class="gp-learn-note">
                @if ($judgedOnBusiness)
                    Ticks count anyone at {{ $vendorName }}.
                @else
                    Ticks show what you’ve done yourself. Cards marked “Whole business” count anyone on the team, because the app doesn’t record who did them.
                @endif
            </p>
        </section>

        @if ($announcements !== [])
            <section class="gp-learn-section" aria-labelledby="gp-whats-new">
                <h2 id="gp-whats-new" class="gp-learn-heading">
                    What’s new
                    @if ($unread > 0)
                        <span class="gp-learn-new-count">{{ $unread }} new</span>
                    @endif
                </h2>

                <div class="gp-learn-list">
                    @foreach ($announcements as $item)
                        @php($tip = $item['tip'])
                        <article
                            class="gp-learn-item"
                            wire:key="announcement-{{ $tip->id }}"
                            x-data="gpLearnItem(@js(['read' => $item['read'], 'answer' => $item['answer']]))"
                            x-init="watchRead(() => $wire.markRead({{ $tip->id }}))"
                            x-on:gp-media-played="$wire.announcementPlayed({{ $tip->id }})"
                        >
                            <span class="gp-unread-dot" x-show="!read" x-transition.opacity.duration.600ms aria-label="New"></span>

                            @include('engagement.media', ['video' => $item['card']['video'], 'imageUrl' => $item['card']['imageUrl']])

                            <div class="gp-learn-item-body">
                                <time class="gp-learn-date" datetime="{{ ($tip->starts_at ?? $tip->created_at)?->toDateString() }}">
                                    {{ ($tip->starts_at ?? $tip->created_at)?->timezone('Africa/Lagos')->format('j M Y') }}
                                </time>
                                <h3 class="gp-learn-title">{{ $item['card']['title'] }}</h3>

                                @if ($item['card']['messageHtml'] !== '')
                                    <div class="gp-tip-message">{!! $item['card']['messageHtml'] !!}</div>
                                @endif

                                @if ($item['card']['hasButton'])
                                    <div class="gp-learn-actions">
                                        <button
                                            type="button"
                                            class="gp-tip-primary"
                                            x-on:click="go($wire.openAnnouncement({{ $tip->id }}))"
                                        >{{ $item['card']['buttonLabel'] }}</button>
                                    </div>
                                @endif

                                @include('engagement.learn-thumbs', ['send' => "\$wire.rateAnnouncement({$tip->id}, answer)"])
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="gp-learn-section" aria-labelledby="gp-features">
            <h2 id="gp-features" class="gp-learn-heading">Features</h2>

            @if ($features === [])
                <p class="gp-learn-note">There’s nothing to show you here yet.</p>
            @endif

            <div class="gp-learn-grid">
                @foreach ($features as $feature)
                    <article
                        @class(['gp-learn-card', 'is-used' => $feature['usedAt'] !== null])
                        wire:key="feature-{{ $feature['key'] }}"
                        x-data="gpLearnItem(@js(['answer' => $feature['answer']]))"
                        x-on:gp-media-played="$wire.featurePlayed(@js($feature['key']))"
                    >
                        @include('engagement.media', ['video' => $feature['video'], 'imageUrl' => null])

                        <div class="gp-learn-item-body">
                            <h3 class="gp-learn-title">
                                @if ($feature['usedAt'])
                                    <span class="gp-tick" title="Used">
                                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                        <span class="sr-only">Used:</span>
                                    </span>
                                @endif
                                {{ $feature['title'] }}
                            </h3>

                            @if ($feature['businessWide'] && ! $judgedOnBusiness)
                                <span class="gp-learn-chip">Whole business</span>
                            @endif

                            @if ($feature['description'] !== '')
                                <p class="gp-learn-desc">{{ $feature['description'] }}</p>
                            @endif

                            <p @class(['gp-learn-status', 'is-used' => $feature['usedAt'] !== null])>
                                @if ($feature['usedAt'])
                                    {{ $feature['businessWide'] ? 'Your business has used this' : 'You’ve used this' }}
                                    since {{ $feature['usedAt']->timezone('Africa/Lagos')->format('j M Y') }}
                                @else
                                    {{ $feature['businessWide'] && ! $judgedOnBusiness ? 'Nobody in your business has used this yet' : 'Not used yet' }}
                                @endif
                            </p>

                            <div class="gp-learn-actions">
                                <button
                                    type="button"
                                    class="gp-tip-primary"
                                    x-on:click="go($wire.showMe(@js($feature['key'])))"
                                >Show me</button>

                                @if ($feature['helpUrl'])
                                    <a href="{{ $feature['helpUrl'] }}" class="gp-tip-link">Read the guide</a>
                                @endif
                            </div>

                            @include('engagement.learn-thumbs', ['send' => '$wire.rateFeature('.\Illuminate\Support\Js::from($feature['key']).', answer)'])
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
