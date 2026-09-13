import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/**
 * Guided tours for the vendor panel.
 *
 * Loaded only where a vendor works — the panel's BODY_END render hook and the
 * procurement wizard layout — never on the storefront or the admin panel. It is
 * its own Vite entry for exactly that reason: resources/js/app.js is shared with
 * the shop front, and shipping a tour engine to customers would be paying for
 * something nobody there can use.
 *
 * Resuming across pages
 * ---------------------
 * The flows worth touring cross page loads, and two of them cross into a Blade
 * wizard on a different layout. So a tour is a list of *chapters*, each with a
 * regex for the page it belongs to, and resuming works by matching the current
 * URL rather than by trusting a saved step number. When a chapter ends we write
 * down which chapter comes next and stop; when a page loads we ask whether it is
 * the page that chapter is waiting for. A vendor who wanders off in the middle
 * simply never triggers the next chapter, and the marker expires on its own.
 */

const RESUME_KEY = 'gp.tour.resume';

// A resume marker older than this is stale: the vendor got distracted and this
// is a new session's worth of intent, not a paused tour.
const RESUME_TTL_MS = 30 * 60 * 1000;

function readConfig() {
    const el = document.getElementById('gp-tours-config');

    if (!el) {
        return null;
    }

    try {
        return JSON.parse(el.textContent);
    } catch (e) {
        return null;
    }
}

const config = readConfig();

// Every storage access is wrapped: private windows and locked-down browsers
// throw on localStorage rather than returning null, and a tour is not worth
// taking a page down for.
function readResume() {
    try {
        const raw = localStorage.getItem(RESUME_KEY);
        if (!raw) return null;

        const parsed = JSON.parse(raw);
        if (!parsed || Date.now() - (parsed.ts ?? 0) > RESUME_TTL_MS) {
            clearResume();
            return null;
        }

        return parsed;
    } catch (e) {
        return null;
    }
}

function writeResume(marker) {
    try {
        localStorage.setItem(RESUME_KEY, JSON.stringify({ ...marker, ts: Date.now() }));
    } catch (e) {
        /* nothing we can do, and nothing that should break the page */
    }
}

function clearResume() {
    try {
        localStorage.removeItem(RESUME_KEY);
    } catch (e) {
        /* as above */
    }
}

/** Which chapter of this tour belongs to the page we are on, or -1. */
function chapterIndexFor(tour, path) {
    return tour.chapters.findIndex((chapter) => {
        try {
            return new RegExp(chapter.match).test(path);
        } catch (e) {
            return false;
        }
    });
}

/**
 * Tell the server this person has now seen this tour.
 *
 * Fire-and-forget with keepalive, because it is usually sent as the vendor is
 * about to navigate. Nothing on screen depends on the response; the local `seen`
 * set below is what stops a second offer in this page's lifetime.
 */
function markSeen(tourKey, status) {
    if (!config?.endpoint) return;

    config.seen = [...new Set([...(config.seen ?? []), tourKey])];

    try {
        fetch(config.endpoint, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrf ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                tour_key: tourKey,
                vendor_id: config.vendorId,
                status,
            }),
        }).catch(() => {});
    } catch (e) {
        /* offline, or the request was cancelled by the navigation — fine */
    }
}

/**
 * Turn a chapter into driver.js steps, dropping any whose element is not on the
 * page.
 *
 * Pre-filtering rather than leaning on driver's own skipMissingElement, because
 * the last surviving step needs the "you click it, I'll pick up on the next
 * page" wording, and that is only knowable once we know which step is last.
 */
function buildSteps(chapter, isFinalChapter, armNextChapter) {
    const present = chapter.steps.filter((step) => {
        if (!step.element) return true;

        try {
            return document.querySelector(step.element) !== null;
        } catch (e) {
            return false;
        }
    });

    return present.map((step, index) => {
        const isLast = index === present.length - 1;

        return {
            element: step.element,
            // Arming the next chapter happens the moment the last step of this
            // one is *shown*, not when its popover is dismissed. The step is
            // "now tap the real button", and a vendor who does exactly that
            // navigates away without ever pressing our Got it — which, if the
            // marker were written on destroy, would silently kill the tour at
            // the exact moment it was working.
            onHighlighted:
                isLast && !isFinalChapter ? () => armNextChapter?.() : undefined,
            popover: {
                title: step.title,
                description: step.body,
                side: step.side ?? 'bottom',
                align: 'start',
                doneBtnText: isLast
                    ? (step.done_label ?? (isFinalChapter ? 'Done' : 'Got it'))
                    : undefined,
            },
        };
    });
}

let active = null;

function runChapter(tour, chapterIndex) {
    const chapter = tour.chapters[chapterIndex];
    if (!chapter) return false;

    const isFinalChapter = chapterIndex === tour.chapters.length - 1;

    const steps = buildSteps(chapter, isFinalChapter, () =>
        writeResume({ key: tour.key, chapter: chapterIndex + 1 }),
    );

    // Nothing on this page to point at. Happens legitimately — a step's button
    // is permission-gated, or the page is still empty — and silently doing
    // nothing beats an empty spotlight over the corner of the screen.
    if (steps.length === 0) {
        clearResume();
        return false;
    }

    if (active) {
        active.destroy();
        active = null;
    }

    // Set before the popover renders: the hooks below run during destroy(), and
    // "was this closed or completed?" is the only thing they need to know.
    let completedChapter = false;

    const instance = driver({
        steps,
        showProgress: steps.length > 1,
        progressText: '{{current}} of {{total}}',
        allowClose: true,
        // The whole point is to make the vendor click the real button, so the
        // spotlight must not swallow the click.
        disableActiveInteraction: false,
        // Livewire can finish rendering a beat after load; give a step's element
        // a moment to appear before deciding it is missing.
        waitForElement: 1500,
        smoothScroll: true,
        stagePadding: 6,
        popoverClass: 'gp-tour-popover',
        overlayClickBehavior: 'close',
        nextBtnText: 'Next',
        prevBtnText: 'Back',
        onNextClick: () => {
            if (instance.isLastStep()) {
                completedChapter = true;
                instance.destroy();
                return;
            }

            instance.moveNext();
        },
        onCloseClick: () => instance.destroy(),
        onDestroyed: () => {
            active = null;

            if (!completedChapter) {
                clearResume();
                markSeen(tour.key, 'dismissed');
                return;
            }

            if (isFinalChapter) {
                clearResume();
                markSeen(tour.key, 'completed');
                return;
            }

            // Chapter done, more to come. The marker was already armed when the
            // last step appeared (see buildSteps), so there is nothing left to
            // do but get out of the way: the vendor's own click on the real
            // button is what carries them to the page it is waiting on.
        },
    });

    active = instance;
    instance.drive();

    return true;
}

/**
 * Start a tour from a button, wherever the vendor happens to be.
 *
 * If a chapter matches this page, begin there. Otherwise arm chapter one and
 * send them to the page it starts on — which is what makes "Start" work from
 * the help centre, which is not part of any tour.
 */
function start(tourKey) {
    const tour = config?.tours?.[tourKey];
    if (!tour) return;

    markSeen(tourKey, 'offered');

    const index = chapterIndexFor(tour, window.location.pathname);

    if (index >= 0) {
        clearResume();
        runChapter(tour, index);
        return;
    }

    writeResume({ key: tourKey, chapter: 0 });
    window.location.href = tour.start_path;
}

/**
 * The once-only offer on a vendor's first visit to a page a tour starts on.
 *
 * "Seen" is recorded the moment the offer is *shown*, not when it is answered,
 * so navigating away without choosing still counts. Being asked twice is the
 * specific annoyance this is meant to avoid.
 */
function offer(tour) {
    let accepted = false;

    markSeen(tour.key, 'offered');

    const instance = driver({
        allowClose: true,
        showButtons: ['next', 'close'],
        popoverClass: 'gp-tour-popover gp-tour-offer',
        steps: [
            {
                popover: {
                    title: tour.title,
                    description:
                        `${tour.description}<br><br>Want me to walk you through it on the real screens? ` +
                        `You can always start it later from <strong>Help &amp; Guides</strong>.`,
                    doneBtnText: 'Show me',
                },
            },
        ],
        onNextClick: () => {
            accepted = true;
            instance.destroy();
        },
        onCloseClick: () => instance.destroy(),
        onDestroyed: () => {
            active = null;

            if (accepted) {
                runChapter(tour, chapterIndexFor(tour, window.location.pathname));
            } else {
                markSeen(tour.key, 'dismissed');
            }
        },
    });

    active = instance;
    instance.drive();
}

function boot() {
    if (!config?.tours) return;

    const path = window.location.pathname;

    // A tour already in flight wins: the vendor asked for this one, and
    // interrupting it to offer a different one would be absurd.
    const marker = readResume();

    if (marker) {
        const tour = config.tours[marker.key];

        if (tour && chapterIndexFor(tour, path) === marker.chapter) {
            runChapter(tour, marker.chapter);
            return;
        }

        // Not this page. Leave the marker armed — they may still be on their
        // way — and do not offer anything over the top of it.
        if (tour) return;

        clearResume();
    }

    if (!config.autoOffer) return;

    const seen = new Set(config.seen ?? []);

    // Only the first chapter, and only one tour per page: a page that starts
    // three tours should ask about one of them, not stack three popovers.
    const candidate = Object.values(config.tours).find(
        (tour) => !seen.has(tour.key) && chapterIndexFor(tour, path) === 0,
    );

    if (candidate) {
        offer(candidate);
    }
}

window.gpTours = { start, boot, config };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

// The panel does not run in SPA mode today, but a wire:navigate anywhere in it
// would otherwise leave the tour dead on the next page.
document.addEventListener('livewire:navigated', boot);
