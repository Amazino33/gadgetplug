// Alpine itself is bundled with Livewire, so nothing imports it here — this
// file only registers behaviour against it. It is loaded from <head> while
// Livewire boots at the end of <body>, so the alpine:init listener inside is
// always registered before Alpine dispatches that event.
import './feed';
