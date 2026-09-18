// The shell's motion API, wired into the bundle.
//
// `resources/js/shell/motion.js` is the single entry point for animation so
// that `prefers-reduced-motion` is enforced in one place. That only holds if
// the module actually reaches the browser: Vite emits manifest entries for
// declared inputs and their import graph, so a module nothing imports is
// silently absent in production and 404s in dev. Importing it here puts it in
// the graph.
import { move, springs, micro } from './shell/motion.js';

// Exposed two ways, because the shell uses both.
//
// `window.shell` is for plain scripts and for modules that want the raw
// helpers. The Alpine magic is for Blade, where `$move(...)` reads far better
// inline than reaching through a global.
window.shell = { move, springs, micro };

document.addEventListener('alpine:init', () => {
    // Registered on alpine:init rather than at import time: Livewire bundles
    // and starts Alpine itself, so anything registered later is ignored.
    window.Alpine.magic('move', () => move);
    window.Alpine.magic('springs', () => springs);
    window.Alpine.magic('micro', () => micro);
});
