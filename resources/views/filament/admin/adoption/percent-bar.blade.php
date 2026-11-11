{{-- One feature's share of businesses using it: a bar plus the numbers it stands for. --}}
@php($row = $getRecord())
<div class="gp-adoption-cell" title="{{ $row['vendors_using'] }} of {{ $row['vendors_total'] }} businesses">
    <span class="gp-funnel-track gp-adoption-track">
        <span class="gp-funnel-bar" style="width: {{ $row['vendors_using'] > 0 ? max(1, $row['percent']) : 0 }}%"></span>
    </span>
    <span class="gp-funnel-value">
        {{ $row['percent'] }}%
        <span class="gp-funnel-share">{{ number_format($row['vendors_using']) }} of {{ number_format($row['vendors_total']) }}</span>
    </span>
</div>
