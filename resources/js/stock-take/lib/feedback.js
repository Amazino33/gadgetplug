/**
 * A beep and a buzz, so a counter looking at the shelf knows what a scan did
 * without looking at the phone. Each outcome sounds different:
 *
 *   found          one high beep, one short buzz
 *   counted before two quick mid beeps (Add or Replace)
 *   blocked        one low tone, buzz-buzz (already counted elsewhere; skip)
 *   unknown        low-high-low, a long buzz (not in the system)
 *   tick           a short click (tap-to-count: one more)
 *
 * Mapping uses good() and warn() too.
 *
 * Where the sound comes from: no sound files. Each beep is made here once, as
 * a tiny WAV clip (a few kilobytes, in memory), and played like any audio on
 * a web page. Real audio clips rather than the browser's tone generator,
 * because an iPhone mutes the generator whenever its silent switch is on, and
 * plays clips anyway. Both follow the phone's MEDIA volume (not the ringer).
 *
 * Phones only let a page start sound from a touch. The first tap anywhere in
 * the app wakes every clip, so a beep set off by the camera later is heard.
 * If a clip still cannot play, the tone generator is tried instead.
 */

const SOUND_KEY = 'stockTakeSound';

/**
 * Sound and vibration on or off (B7), kept on this phone. A web page can't
 * read the phone's silent switch, so the counter has a button for it; the
 * phone's own "vibrate off" setting still silences the buzz.
 */
export function soundOn() {
    try {
        return localStorage.getItem(SOUND_KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSound(on) {
    try {
        localStorage.setItem(SOUND_KEY, on ? 'on' : 'off');
    } catch { /* private window: on for this visit */ }
}

/* ---------- the clips ---------- */

// [frequency Hz, length ms, start ms]
const PATTERNS = {
    good:    [[1320, 110, 0]],
    again:   [[990, 80, 0], [990, 80, 130]],
    warn:    [[440, 220, 0]],
    unknown: [[392, 120, 0], [587, 120, 140], [392, 180, 280]],
    tick:    [[1760, 40, 0]],
};
const LOUDNESS = { tick: 0.35 };
const RATE = 22050;
const COPIES = 2; // so a beep can start while the last one still plays

/** A WAV file of square-ish beeps, as a blob: URL. */
export function makeClip(tones, volume = 0.6) {
    return URL.createObjectURL(new Blob([wavBytes(tones, volume)], { type: 'audio/wav' }));
}

/** The WAV file's bytes: 16-bit mono PCM. */
export function wavBytes(tones, volume = 0.6) {
    const end = Math.max(...tones.map(([, ms, at]) => at + ms)) + 20;
    const samples = Math.ceil((end / 1000) * RATE);
    const pcm = new Int16Array(samples);

    for (const [frequency, ms, at] of tones) {
        const from = Math.floor((at / 1000) * RATE);
        const count = Math.floor((ms / 1000) * RATE);
        const fade = Math.min(Math.floor(RATE * 0.004), count / 2);
        for (let i = 0; i < count && from + i < samples; i++) {
            const t = i / RATE;
            // A square wave softened by its first harmonics: a clear scanner beep.
            const s = Math.sin(2 * Math.PI * frequency * t)
                + Math.sin(2 * Math.PI * 3 * frequency * t) / 3
                + Math.sin(2 * Math.PI * 5 * frequency * t) / 5;
            const edge = Math.min(1, i / fade, (count - i) / fade);
            pcm[from + i] = Math.max(-32767, Math.min(32767, s * 0.8 * edge * volume * 32767));
        }
    }

    const bytes = new ArrayBuffer(44 + pcm.length * 2);
    const v = new DataView(bytes);
    const text = (at, s) => [...s].forEach((c, i) => v.setUint8(at + i, c.charCodeAt(0)));
    text(0, 'RIFF'); v.setUint32(4, 36 + pcm.length * 2, true); text(8, 'WAVE');
    text(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, RATE, true); v.setUint32(28, RATE * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
    text(36, 'data'); v.setUint32(40, pcm.length * 2, true);
    new Int16Array(bytes, 44).set(pcm);

    return bytes;
}

let clips = null; // name => { list: [Audio], next: 0 }

function allClips() {
    if (clips) return clips;
    clips = {};
    try {
        for (const [name, tones] of Object.entries(PATTERNS)) {
            const url = makeClip(tones, LOUDNESS[name] ?? 0.6);
            clips[name] = {
                list: Array.from({ length: COPIES }, () => {
                    const el = new Audio(url);
                    el.preload = 'auto';
                    return el;
                }),
                next: 0,
            };
        }
    } catch {
        clips = {};
    }
    return clips;
}

/* ---------- the tone generator, as a fallback ---------- */

let audio = null;

function context() {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state !== 'running') audio.resume?.().catch?.(() => {});
        return audio;
    } catch {
        return null;
    }
}

function generate(name) {
    const ctx = context();
    if (!ctx) return;
    try {
        for (const [frequency, ms, delay] of PATTERNS[name]) {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            const at   = ctx.currentTime + delay / 1000;
            osc.type = 'square';
            osc.frequency.value = frequency;
            gain.gain.setValueAtTime(name === 'tick' ? 0.1 : 0.25, at);
            gain.gain.linearRampToValueAtTime(0.0001, at + ms / 1000);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(at);
            osc.stop(at + ms / 1000);
        }
    } catch { /* no sound on this device */ }
}

/* ---------- waking the sound on a touch ---------- */

let woken = false;

/**
 * Called from inside a tap: loads every clip there (what an iPhone needs
 * before it lets a clip play later without a tap; Android allows it once the
 * page has been touched at all) and wakes the tone generator.
 */
export function unlockSound() {
    context();
    if (woken) return;

    const all = Object.values(allClips()).flatMap((c) => c.list);
    all.forEach((el) => { try { el.load(); } catch { /* fine */ } });
    woken = all.length > 0;
}

if (typeof window !== 'undefined') {
    // touchend and click count as a "touch" on every phone; pointerdown only with a mouse.
    ['touchend', 'click', 'keydown', 'pointerup'].forEach((type) => {
        window.addEventListener(type, unlockSound, { capture: true, passive: true });
    });
    // Back from the background: sound may have been paused again.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') audio?.resume?.().catch?.(() => {});
    });
}

/* ---------- playing ---------- */

function play(name) {
    if (!soundOn()) return;

    const clip = allClips()[name];
    if (!clip?.list.length) { generate(name); return; }

    const el = clip.list[clip.next];
    clip.next = (clip.next + 1) % clip.list.length;

    try {
        el.muted = false;
        el.currentTime = 0;
        const played = el.play();
        played?.catch?.(() => generate(name));
    } catch {
        generate(name);
    }
}

const buzz = (pattern) => {
    if (!soundOn()) return;
    try { navigator.vibrate?.(pattern); } catch { /* not allowed here */ }
};

/** ✓ found. */
export function good() {
    play('good');
    buzz(60);
}

/** ↺ counted before on this aisle: Add or Replace. */
export function again() {
    play('again');
    buzz([40, 50, 40]);
}

/** ⚠ refused: already counted elsewhere, not on this recount, finish this one first. */
export function warn() {
    play('warn');
    buzz([80, 60, 80]);
}

/** ❓ a barcode nothing in the shop has. */
export function unknown() {
    play('unknown');
    buzz(250);
}

/** Tap-to-count: one more. */
export function tick() {
    play('tick');
    buzz(25);
}
