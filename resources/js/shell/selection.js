// The client-side selection model for the file list (design plan §6,
// "Selection"; implementation plan Task 4).
//
// Selection is deliberately NOT server state. Design plan §5 motion #1,
// "Select", is explicit: "No animation. Background changes on the same
// frame" -- a Livewire round trip before a row's background changes would
// itself read as lag to someone filing fast. So this store never calls
// Livewire and never reaches for the shell's animation helper
// (resources/js/shell/motion.js): there is nothing here to animate in the
// first place, which is the point. The row's own `x-bind:class` reacts to
// this store's state instantly, on the same tick as the click.
//
// It lives as an Alpine.store() -- rather than component-local `x-data` --
// because it has to survive a Livewire morph: sorting the list re-renders
// every row, and design plan §6 says selection "survives a sort change and
// column change". A store is just a JS object Alpine keeps around across
// those morphs; it is not part of the DOM Livewire diffs.
document.addEventListener('alpine:init', () => {
    Alpine.store('selection', {
        /** @type {number[]} */
        ids: [],

        /** The last row a plain or ctrl/cmd click landed on -- the start of the next shift-click range. */
        anchor: null,

        isSelected(id) {
            return this.ids.includes(id);
        },

        anySelected() {
            return this.ids.length > 0;
        },

        clear() {
            this.ids = [];
            this.anchor = null;
        },

        /** Plain click: select this one and clear the rest (design plan §6). */
        selectOnly(id) {
            this.ids = [id];
            this.anchor = id;
        },

        /** Ctrl/Cmd-click, or a row's own checkbox: toggle without touching the rest (design plan §6). */
        toggle(id) {
            this.ids = this.isSelected(id)
                ? this.ids.filter((existing) => existing !== id)
                : [...this.ids, id];
            this.anchor = id;
        },

        selectAll(ids) {
            this.ids = [...ids];
            this.anchor = ids.length > 0 ? ids[ids.length - 1] : null;
        },

        /**
         * Shift-click: extend the selection from the anchor to `id`, in the
         * list's own current row order (`orderedIds`) -- not an order this
         * store assumes, because sort and grouping change it and the range
         * must follow what is actually on screen.
         */
        range(id, orderedIds) {
            if (this.anchor === null) {
                this.selectOnly(id);

                return;
            }

            const from = orderedIds.indexOf(this.anchor);
            const to = orderedIds.indexOf(id);

            if (from === -1 || to === -1) {
                this.selectOnly(id);

                return;
            }

            const [start, end] = from < to ? [from, to] : [to, from];
            this.ids = orderedIds.slice(start, end + 1);
        },

        /**
         * Reads the ids of every row currently rendered in the grid, top to
         * bottom, as data-row-id declares them. This is what a shift-click
         * range extends across, so it has to be the DOM's actual order, not
         * a remembered one -- the order changes the moment the sort does.
         *
         * @param {Element|null} gridEl
         * @returns {number[]}
         */
        orderedIds(gridEl) {
            if (!gridEl) {
                return [];
            }

            return Array.from(gridEl.querySelectorAll('[data-row-id]')).map(
                (row) => Number(row.dataset.rowId),
            );
        },

        /**
         * The single entry point a row's click handler calls. Dispatches to
         * plain / ctrl-cmd / shift behaviour per design plan §6: "Click
         * selects one and clears the rest. Ctrl/Cmd-click toggles.
         * Shift-click extends a range from the anchor." No animation runs
         * for any of these -- Select (§5, motion #1) is explicitly instant.
         *
         * @param {number} id
         * @param {MouseEvent} event
         * @param {Element|null} gridEl
         */
        click(id, event, gridEl) {
            if (event.shiftKey) {
                this.range(id, this.orderedIds(gridEl));

                return;
            }

            if (event.ctrlKey || event.metaKey) {
                this.toggle(id);

                return;
            }

            this.selectOnly(id);
        },
    });
});
