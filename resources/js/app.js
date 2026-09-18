// The shell's motion API, wired into the bundle.
//
// `resources/js/shell/motion.js` is the single entry point for animation so
// that `prefers-reduced-motion` is enforced in one place. That only holds if
// the module actually reaches the browser: Vite emits manifest entries for
// declared inputs and their import graph, so a module nothing imports is
// silently absent in production and 404s in dev. Importing it here puts it in
// the graph.
import { startDragAndDrop } from './shell/dnd.js';
import { move, springs, micro } from './shell/motion.js';
import { registerPreview } from './shell/preview.js';

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

    // The preview's tabs, syntax highlighting and Word conversion. The heavy
    // libraries behind it are dynamic imports inside the component, so this
    // registration costs nothing until a preview is opened.
    registerPreview(window.Alpine);
});

// Delegated to the document, so it survives every Livewire DOM swap.
startDragAndDrop();
