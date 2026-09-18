// The single entry point for every animation in the shell (design plan §5).
//
// Nothing else calls `motion`'s `animate()` directly. If it did,
// `prefers-reduced-motion` would be unenforceable -- each call site would
// have to remember to check it, and one that forgets silently breaks the
// promise the design makes to a viewer who asked for less motion. `move()`
// checks once, here, and short-circuits to the end state instead of
// animating when the query matches.
//
// Rows are exempt from all of this: hover backgrounds change instantly and
// selection never animates (Select, design plan §5, motion #1). That is a
// rule for the callers that build rows (Tasks 3-8), not something this
// helper enforces -- there is nothing to opt out of if a row never calls
// `move()` in the first place.
import { animate } from 'motion';

const reduce = matchMedia('(prefers-reduced-motion: reduce)');

/**
 * Animate `el` from its current state through `keyframes`, unless the viewer
 * has asked for reduced motion -- in which case the end state is applied
 * immediately and no animation runs.
 *
 * @param {Element} el
 * @param {Record<string, unknown>} keyframes
 * @param {Record<string, unknown> & { keepUnderReducedMotion?: boolean }} [options]
 * @returns {Promise<unknown>}
 */
export function move(el, keyframes, options = {}) {
    if (reduce.matches && !options.keepUnderReducedMotion) {
        Object.assign(
            el.style,
            Object.fromEntries(
                Object.entries(keyframes).map(([k, v]) => [k, Array.isArray(v) ? v.at(-1) : v]),
            ),
        );

        return Promise.resolve();
    }

    return animate(el, keyframes, options).finished;
}

// The twelve named motions (design plan §5). Every spring `move()` is asked
// to run should come from here rather than a duration invented at the call
// site.
export const springs = {
    reveal: { type: 'spring', stiffness: 420, damping: 38, mass: 1 },
    unfold: { type: 'spring', stiffness: 520, damping: 42, mass: 1 },
    lift: { type: 'spring', stiffness: 600, damping: 30, mass: 1 },
    settle: { type: 'spring', stiffness: 700, damping: 40, mass: 1 },
    stamp: { type: 'spring', stiffness: 800, damping: 22, mass: 1 },
    sheet: { type: 'spring', stiffness: 380, damping: 36, mass: 1 },

    // Micro-interactions (design plan §5, "Micro-interactions"): the tab
    // underline is the only member of that tier that is a spring rather than
    // a CSS-friendly bezier, because it is the only one that carries weight
    // rather than acknowledging a tap. It still goes through `move()`.
    tabUnderline: { type: 'spring', stiffness: 500, damping: 40, mass: 1 },
};

// The micro-interaction tier (design plan §5, "Micro-interactions"): a touch,
// not a change. Every one is under 120ms. Unlike `springs`, these are
// CSS-friendly durations and timing functions, not `move()` calls -- a CSS
// `transition` already honours `prefers-reduced-motion` via the
// `motion-reduce:` variant at the call site, so routing a button's :active
// scale through JS would only add a frame of latency to something that is
// supposed to feel instant. Exported so later tasks reach for a named value
// instead of inventing a duration.
//
// Rows stay exempt here too: this tier belongs to things you press --
// buttons, chevrons, checkboxes, tabs -- not to things you sweep a pointer
// across.
export const micro = {
    // Button press (:active): scale 0.97, releases on the same curve.
    press: { duration: 80, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    // Icon button hover: glyph opacity ink-2 -> ink. No background, no lift.
    iconHover: { duration: 80, easing: 'linear' },
    // Checkbox / switch toggle: the knob travels; the border goes to
    // `select` on the same frame.
    checkboxTravel: { duration: 120, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    // Chevron (tree, sort, disclosure): rotate 90deg, the same curve as
    // Unfold, so a chevron and the row it discloses read as one gesture.
    chevron: { duration: 120, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    // Focus ring: fades in on keyboard focus only, never on mouse focus.
    focusRing: { duration: 60, easing: 'linear' },
    // Toolbar / menu item hover: background to `chrome`. Items in an open
    // menu respond instantly, since the pointer is already committed.
    menuItemHover: { duration: 60, easing: 'linear' },
    // Sort column click: the arrow flips 180deg, the same curve as chevron.
    sortArrow: { duration: 120, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    // Copy / small confirmation: glyph swaps to a tick, holds, then swaps
    // back -- both transitions are the same crossfade.
    confirmGlyph: { duration: 100, easing: 'linear', hold: 1200 },
};
