{{--
    "Was this helpful?" on a Learn-page item. Small and quiet, like the
    pop-up's. The answer is kept in the item's gpLearnItem state; $send is the
    JS call that records it, with `answer` in scope.
--}}
<div class="gp-tip-feedback gp-learn-feedback">
    <span x-show="!answer">Was this helpful?</span>
    <span x-show="answer" x-cloak>Thanks for telling us.</span>
    <button type="button" class="gp-tip-thumb" x-bind:aria-pressed="answer === 'yes'" x-on:click="rate('yes', (answer) => {{ $send }})" aria-label="Yes, helpful">👍</button>
    <button type="button" class="gp-tip-thumb" x-bind:aria-pressed="answer === 'no'" x-on:click="rate('no', (answer) => {{ $send }})" aria-label="No, not helpful">👎</button>
</div>
