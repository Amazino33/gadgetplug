{{--
    gpLearnItem: one Learn-page item (a feature card or a What's new entry).

    - watchRead(mark): an unread announcement counts as read once at least a
      quarter of it has stayed on screen for 1.5 seconds. Then its dot fades
      and mark() records it (a `shown` event on the Learn route).
    - rate(answer, send): thumbs, kept here so the page needn't re-render.
    - go(call): awaits a Livewire call that either redirects (returns nothing)
      or names a browser event to fire instead, such as gp-open-assistant.
--}}
<script>
    window.gpLearnItem ??= (cfg) => ({
        read: cfg.read ?? true,
        answer: cfg.answer ?? null,

        watchRead(mark) {
            if (this.read) {
                return;
            }

            if (! ('IntersectionObserver' in window)) {
                this.read = true;
                mark();

                return;
            }

            let timer = null;
            const observer = new IntersectionObserver((entries) => {
                const visible = entries.some((entry) => entry.isIntersecting);

                if (visible && ! timer) {
                    timer = setTimeout(() => {
                        observer.disconnect();
                        this.read = true;
                        mark();
                    }, 1500);
                } else if (! visible && timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            }, { threshold: 0.25 });

            observer.observe(this.$el);
        },

        rate(answer, send) {
            this.answer = answer;
            send(answer);
        },

        async go(call) {
            const result = await call;

            if (typeof result === 'string' && result !== '') {
                window.dispatchEvent(new CustomEvent(result));
            }
        },
    });
</script>
