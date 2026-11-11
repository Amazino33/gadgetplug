{{--
    The per-tip report: funnel, thumbs and plays, then one row per person.
    Bars are one hue (magnitude, one series), sized against "Saw", and every
    bar carries its own number, so nothing is read from colour alone. The
    table below is the full data behind them.
--}}
<x-filament-panels::page>
    @php
        $s = $this->summary;
        $base = max(1, $s['saw']);
        $steps = [
            ['label' => 'Saw it', 'value' => $s['saw'], 'help' => 'People shown the tip, not counting those already using the feature.'],
            ['label' => 'Tapped the button', 'value' => $s['tapped'], 'help' => 'Of those, tapped the tip’s button.'],
        ];
        if ($s['has_feature']) {
            $steps[] = ['label' => 'Used it', 'value' => $s['used'], 'help' => 'Went on to use the feature within '.$this->windowDays.' days of first seeing the tip, tapped or not.'];
        }
    @endphp

    <div class="gp-report">
        <section class="gp-report-panel" aria-labelledby="gp-funnel-heading">
            <h2 id="gp-funnel-heading" class="gp-report-heading">Did it work?</h2>

            <dl class="gp-funnel">
                @foreach ($steps as $step)
                    <div class="gp-funnel-row" title="{{ $step['help'] }}">
                        <dt class="gp-funnel-label">{{ $step['label'] }}</dt>
                        <dd class="gp-funnel-bar-cell">
                            <span class="gp-funnel-track">
                                <span class="gp-funnel-bar" style="width: {{ $s['saw'] > 0 ? max(1, round(100 * $step['value'] / $base)) : 0 }}%"></span>
                            </span>
                            <span class="gp-funnel-value">
                                {{ number_format($step['value']) }}
                                @if ($s['saw'] > 0 && ! $loop->first)
                                    <span class="gp-funnel-share">{{ round(100 * $step['value'] / $base) }}%</span>
                                @endif
                            </span>
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="gp-report-note">
                @if ($s['has_feature'])
                    <strong>{{ number_format($s['already_using']) }}</strong> more {{ $s['already_using'] === 1 ? 'was' : 'were' }} already using the feature when they first saw the tip, so they are left out above.
                    Owners are judged on their whole business; staff on their own use where the app records who did it.
                @else
                    This tip isn’t linked to a feature, so there is no “Used it” step.
                @endif
            </p>
        </section>

        <div class="gp-report-tiles">
            <div class="gp-report-tile">
                <p class="gp-report-tile-label">Found it helpful</p>
                <p class="gp-report-tile-value">{{ $s['percent'] === null ? '–' : $s['percent'].'%' }}</p>
                <p class="gp-report-tile-sub">{{ $s['yes'] }} 👍 · {{ $s['no'] }} 👎 (latest answer per person)</p>
            </div>
            <div class="gp-report-tile">
                <p class="gp-report-tile-label">Video plays</p>
                <p class="gp-report-tile-value">{{ number_format($s['plays']) }}</p>
                <p class="gp-report-tile-sub">First play per showing</p>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
