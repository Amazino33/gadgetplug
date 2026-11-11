{{--
    Client side of the tip pop-up and its media. Inline and tiny rather than a
    bundle: it only loads on pages that can show a tip, and one saved request
    matters more than the bytes on these vendors' connections.

    Read-only checks before showing (decision 8): a tip never shows on a page
    load where the SystemAnnouncement modal or a guided tour is showing or
    offered, and never on top of an open Filament modal (it waits for that one
    to close). It never changes how those work.
--}}
<script>
    window.gpTip ??= (cfg) => ({
        visible: false,
        busy: false,
        feedbackVisible: false,
        answered: cfg.answered ?? null,
        dragY: 0,
        startY: null,

        schedule() {
            setTimeout(() => this.attempt(0), Math.max(1, cfg.delay) * 1000);
        },

        // Another pop-up owns this page load: the announcement modal, or a
        // tour (driver.js) that is running or being offered.
        otherPopupOnPage() {
            return Boolean(
                document.querySelector('[wire\\:name="system-announcement-modal"] .fixed')
                || document.querySelector('.driver-popover')
                || document.body.classList.contains('driver-active')
            );
        },

        filamentModalOpen() {
            return Boolean(document.querySelector('.fi-modal-open'));
        },

        attempt(tries) {
            if (this.otherPopupOnPage()) {
                return;
            }

            if (this.filamentModalOpen()) {
                if (tries < 30) {
                    setTimeout(() => this.attempt(tries + 1), 2000);
                }

                return;
            }

            this.visible = true;

            if (!cfg.preview) {
                this.$wire.markShown();
            }

            setTimeout(() => { this.feedbackVisible = true; }, 1500);
        },

        async tap() {
            if (this.busy) return;
            this.busy = true;

            await this.$wire.pressButton();

            // A button that opens something on this page (the assistant)
            // rather than going somewhere.
            if (cfg.event) {
                window.dispatchEvent(new CustomEvent(cfg.event));
            }

            this.visible = false;
            this.busy = false;
        },

        notNow() {
            this.visible = false;
            this.$wire.notNow();
        },

        dismiss() {
            this.visible = false;
            this.$wire.dismiss();
        },

        rate(answer) {
            this.answered = answer;
            this.$wire.rate(answer);
        },

        dragStart(e) {
            this.startY = e.touches[0].clientY;
        },

        dragMove(e) {
            if (this.startY === null) return;
            this.dragY = Math.max(0, e.touches[0].clientY - this.startY);
        },

        dragEnd() {
            if (this.dragY > 80) {
                this.notNow();
            }

            this.dragY = 0;
            this.startY = null;
        },
    });

    // Poster first; the clip loads only once it is on screen, muted, looped,
    // inline. With Save-Data or reduced motion it waits for a tap instead.
    window.gpTipMedia ??= (cfg) => ({
        playing: false,

        init() {
            const quiet = navigator.connection?.saveData
                || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (quiet) return;

            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    this.play();
                }
            });

            observer.observe(this.$el);
        },

        play() {
            if (this.playing) return;
            this.playing = true;

            const clip = this.$refs.clip;
            clip.src = cfg.url;

            if (cfg.kind === 'mp4') {
                clip.muted = true;
                clip.play().catch(() => { this.playing = false; });
            }

            this.$dispatch('gp-media-played');
        },
    });
</script>
