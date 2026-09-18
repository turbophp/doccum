// Drag and drop for the file list (design plan §6, "Drag and drop"; §5
// motions 5 Lift, 6 Accept, 7 Settle, 11 Progress; implementation plan
// Task 7).
//
// Three drag sources -- a file row, a folder row, a batch of files dragged
// in from the desktop -- and two kinds of drop target this module cares
// about: a folder row in the list, and a directory node in the tree. Every
// one of them ends at App\Livewire\Files\UploadTray, which re-checks
// authorisation from zero. Nothing here is an authorisation boundary: this
// module's job is only to make the pointer look right while a drag is in
// the air, and it is allowed to be wrong about that -- refuse a target the
// server would actually allow, or show a ring for one it will not -- without
// that ever becoming a security question, because the drop is re-decided
// server-side regardless of what the ring said.
//
// This module owns no markup of its own in the list or the tree (Task 4 and
// Task 3 do). It hooks what those views already emit --
// data-file-row/data-row-id, data-folder-row/data-folder-id,
// data-file-grid, and the tree's role="treeitem"/wire:key -- with plain DOM
// listeners, rather than adding Alpine directives to files it does not own.
// The one exception is App\Livewire\Files\UploadTray's own root
// (data-upload-tray), which this task does own.
//
// Every animation goes through window.shell.move()/springs, exactly as
// resources/js/shell/motion.js requires: nothing here calls motion's
// animate() directly, so prefers-reduced-motion stays enforceable in the one
// place that checks it.

const SPRING_LOAD_MS = 600;
const DRAG_MIME = 'application/x-doccum-row';

/** True once, cheaply: dragstart/dragover fire often and this never changes mid-session. */
const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

let dragState = null;
/** @type {{ el: Element, timer: number } | null} */
let springLoad = null;
let emptyDragImageEl = null;

function shell() {
    // window.shell is registered by resources/js/app.js, which imports
    // motion.js so it actually reaches the bundle (see that file's own
    // comment). A defensive fallback keeps this module from throwing if it
    // is ever loaded before that registration runs.
    return window.shell ?? { move: (el, kf) => Promise.resolve(applyEndState(el, kf)), springs: {} };
}

function applyEndState(el, keyframes) {
    Object.assign(
        el.style,
        Object.fromEntries(Object.entries(keyframes).map(([k, v]) => [k, Array.isArray(v) ? v.at(-1) : v])),
    );
}

function emptyDragImage() {
    // Pointed at by setDragImage() so the browser's own drag preview is
    // invisible and the cloned ghost built below is the only thing that
    // moves (design plan §6: "setDragImage pointed at an empty element").
    if (!emptyDragImageEl) {
        emptyDragImageEl = document.createElement('canvas');
        emptyDragImageEl.width = 1;
        emptyDragImageEl.height = 1;
    }

    return emptyDragImageEl;
}

// --- Making existing rows draggable, without touching the files that render them ---

function fileRowsAndFolderRows() {
    return document.querySelectorAll('[data-file-row], [data-folder-row]');
}

function ensureRowsAreDraggable() {
    fileRowsAndFolderRows().forEach((row) => {
        if (row.getAttribute('draggable') === 'true') {
            return;
        }

        row.setAttribute('draggable', 'true');
    });
}

// FileList's rows are re-rendered by Livewire on every sort, navigation and
// upload -- a one-time querySelectorAll at load time would stop covering new
// rows the moment the first Livewire morph replaces them. Observing the
// whole document is the same trick selection.js's row bindings get for free
// from Alpine/Livewire's own morph lifecycle; this module has no directive
// to hook, so it re-scans instead.
function watchForNewRows() {
    ensureRowsAreDraggable();

    new MutationObserver(() => ensureRowsAreDraggable()).observe(document.body, {
        childList: true,
        subtree: true,
    });
}

// --- Reading what a row already tells us, rather than adding markup for it ---

function rowIdOf(row) {
    if (row.hasAttribute('data-folder-row')) {
        return { subjectType: 'directory', id: Number(row.dataset.folderId) };
    }

    if (row.hasAttribute('data-file-row')) {
        return { subjectType: 'file', id: Number(row.dataset.rowId) };
    }

    return null;
}

// A file row's own "Archived period" state glyph already renders,
// unconditionally, in FileList's state cell (design plan §4) -- both the
// narrow and the wide variant exist in the DOM regardless of viewport width
// or sort order, each carrying a visually-hidden label for the icon's
// meaning. Reading that label is how this module learns a dragged file's own
// period is archived without FileList adding a dedicated data attribute for
// it. This is a heuristic on rendered text, not a hook built for this
// purpose -- it breaks if that label's wording changes, which a
// data-archived attribute on the row would not. Worth adding there later;
// out of this task's owned files today.
function rowIsArchived(row) {
    return Array.from(row.querySelectorAll('.sr-only')).some((el) => el.textContent?.trim() === 'Archived period');
}

function directoryIdFromTreeNode(node) {
    const key = node.getAttribute('wire:key') ?? '';
    const match = /^tree-node-(\d+)$/.exec(key);

    return match ? Number(match[1]) : null;
}

function treeNodeIsCollapsed(node) {
    return node.getAttribute('aria-expanded') === 'false';
}

function nearestWireRoot(el) {
    return el.closest('[wire\\:id]');
}

function uploadTrayEl() {
    return document.querySelector('[data-upload-tray]');
}

// --- The custom ghost (design plan §5, motion 5, "Lift") ---

function buildGhost(row, count) {
    const rect = row.getBoundingClientRect();
    const layer = document.createElement('div');

    layer.setAttribute('data-drag-ghost', '');
    layer.style.position = 'fixed';
    layer.style.top = '0';
    layer.style.left = '0';
    layer.style.zIndex = '50';
    layer.style.pointerEvents = 'none';
    layer.style.width = `${rect.width}px`;

    // "Multi-drag: up to three clones offset 3px each with a count in ink on
    // sheet" (design plan §5). One clone is enough to prove the row moved;
    // three is the ceiling the design sets so the stack never reads as the
    // whole selection.
    const stackCount = Math.min(count, 3);

    for (let i = stackCount - 1; i >= 0; i--) {
        const clone = row.cloneNode(true);
        clone.style.position = 'absolute';
        clone.style.top = `${i * 3}px`;
        clone.style.left = `${i * 3}px`;
        clone.style.margin = '0';
        clone.style.boxShadow = '0 6px 16px rgba(31,37,43,0.18)';
        clone.style.background = 'var(--color-sheet)';
        layer.appendChild(clone);
    }

    if (count > 1) {
        const badge = document.createElement('span');
        badge.textContent = String(count);
        badge.style.position = 'absolute';
        badge.style.top = '-8px';
        badge.style.right = '-8px';
        badge.style.minWidth = '18px';
        badge.style.height = '18px';
        badge.style.borderRadius = '9999px';
        badge.style.display = 'flex';
        badge.style.alignItems = 'center';
        badge.style.justifyContent = 'center';
        badge.style.fontSize = '11px';
        badge.style.color = 'var(--color-ink)';
        badge.style.background = 'var(--color-sheet)';
        badge.style.border = '1px solid var(--color-rule)';
        layer.appendChild(badge);
    }

    document.body.appendChild(layer);
    positionGhost(layer, rect.left, rect.top);

    return layer;
}

function positionGhost(ghostEl, clientX, clientY) {
    ghostEl.style.transform = `translate(${clientX}px, ${clientY}px)`;
}

function removeGhost() {
    dragState?.ghostEl?.remove();
}

// --- The Accept ring: a solid 2px inset ring, never dashed (design plan §6) ---

function applyAccept(el) {
    el.style.outline = '2px solid var(--color-select)';
    el.style.outlineOffset = '-2px';
    el.style.backgroundColor = 'color-mix(in oklab, var(--color-select) 12%, var(--color-sheet))';
}

function clearAccept(el) {
    el.style.outline = '';
    el.style.outlineOffset = '';
    el.style.backgroundColor = '';
}

function setReason(reason) {
    document.dispatchEvent(new CustomEvent('shell-drag-overlay', { detail: { show: false, reason } }));
}

function clearReason() {
    document.dispatchEvent(new CustomEvent('shell-drag-overlay', { detail: { show: false, reason: null } }));
}

// --- Spring-loaded folders: 600ms hover opens a folder row or expands a tree node ---

function armSpringLoad(target, kind) {
    if (springLoad?.el === target) {
        return;
    }

    disarmSpringLoad();

    springLoad = {
        el: target,
        timer: window.setTimeout(() => {
            if (kind === 'folder-row') {
                window.Livewire?.dispatch('directory-selected', { directoryId: Number(target.dataset.folderId) });
            } else if (kind === 'tree-node' && treeNodeIsCollapsed(target)) {
                const wireRoot = nearestWireRoot(target);
                const directoryId = directoryIdFromTreeNode(target);

                if (wireRoot && directoryId !== null) {
                    window.Livewire?.find(wireRoot.getAttribute('wire:id'))?.call('toggle', directoryId);
                }
            }
        }, SPRING_LOAD_MS),
    };
}

function disarmSpringLoad() {
    if (springLoad) {
        window.clearTimeout(springLoad.timer);
        springLoad = null;
    }
}

// --- Drag lifecycle ---

function onDragStart(event) {
    const row = event.target.closest('[data-file-row], [data-folder-row]');

    if (!row) {
        return; // Not one of ours -- let the browser handle it (or ignore it) as usual.
    }

    const subject = rowIdOf(row);

    if (!subject) {
        return;
    }

    const selection = window.Alpine?.store?.('selection');
    const ids = subject.subjectType === 'file' && selection?.isSelected(subject.id) && selection.ids.length > 1
        ? [...selection.ids]
        : [subject.id];

    dragState = {
        subjectType: subject.subjectType,
        ids,
        archived: subject.subjectType === 'file' && rowIsArchived(row),
        sourceRow: row,
        ghostEl: null,
    };

    event.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag at all unless setData() is called.
    event.dataTransfer.setData(DRAG_MIME, JSON.stringify({ subjectType: subject.subjectType, ids }));

    // Safari's setDragImage/drag-event pair cannot reliably reposition a
    // custom ghost -- `drag` fires far less often there, and sometimes not
    // at all for the duration of a single gesture, which is this design's
    // own documented risk (§11). Rather than ship a ghost that visibly
    // freezes mid-drag on Safari, this falls back to the native drag image
    // there and skips the ring-only setDragImage() call entirely.
    if (!isSafari) {
        event.dataTransfer.setDragImage(emptyDragImage(), 0, 0);
        dragState.ghostEl = buildGhost(row, ids.length);

        shell().move(
            dragState.ghostEl,
            { transform: [dragState.ghostEl.style.transform, dragState.ghostEl.style.transform], scale: [1, 1.02] },
            shell().springs.lift,
        );
    }
}

function onDrag(event) {
    if (!dragState?.ghostEl || (event.clientX === 0 && event.clientY === 0)) {
        return; // The final `drag` event on drop fires with (0, 0) in most browsers.
    }

    positionGhost(dragState.ghostEl, event.clientX, event.clientY);
}

function currentDropTarget(event) {
    const folderRow = event.target.closest('[data-folder-row]');

    if (folderRow) {
        return { el: folderRow, kind: 'folder-row', directoryId: Number(folderRow.dataset.folderId) };
    }

    const treeNode = event.target.closest('[role="treeitem"]');

    if (treeNode) {
        return { el: treeNode, kind: 'tree-node', directoryId: directoryIdFromTreeNode(treeNode) };
    }

    return null;
}

let acceptedEl = null;

function onDragOver(event) {
    const isDesktopFiles = event.dataTransfer?.types?.includes('Files') && !dragState;

    if (!dragState && !isDesktopFiles) {
        return;
    }

    const target = currentDropTarget(event);

    if (isDesktopFiles) {
        // Files-from-the-desktop dropzone: the whole grid is the target
        // (handled by onDragEnterGrid/onDragLeaveGrid below); a folder row
        // under the cursor still wins individually (design plan §6).
        event.preventDefault();

        if (target?.kind === 'folder-row') {
            event.preventDefault();
            applyAccept(target.el);
            acceptedEl = target.el;
        } else if (acceptedEl) {
            clearAccept(acceptedEl);
            acceptedEl = null;
        }

        return;
    }

    if (!target || target.directoryId === null) {
        if (acceptedEl) {
            clearAccept(acceptedEl);
            acceptedEl = null;
        }

        disarmSpringLoad();
        clearReason();

        return;
    }

    event.preventDefault();

    const valid = isValidTarget(target);

    if (acceptedEl && acceptedEl !== target.el) {
        clearAccept(acceptedEl);
        acceptedEl = null;
    }

    if (valid) {
        event.dataTransfer.dropEffect = 'move';
        applyAccept(target.el);
        acceptedEl = target.el;
        clearReason();
        armSpringLoad(target.el, target.kind);
    } else {
        event.dataTransfer.dropEffect = 'none';

        if (acceptedEl === target.el) {
            clearAccept(target.el);
            acceptedEl = null;
        }

        disarmSpringLoad();
        setReason(dragState?.archived ? 'Period archived' : null);
    }
}

function isValidTarget(target) {
    if (!dragState) {
        return false;
    }

    // "Any target when the file's own period is archived... the menu and
    // the drop follow the same rule" (design plan §6): nothing is ever a
    // valid target for an archived file, so no ring appears anywhere for
    // the whole drag, not just on the target that would have refused it.
    if (dragState.archived) {
        return false;
    }

    if (dragState.subjectType === 'directory' && dragState.ids.includes(target.directoryId)) {
        return false; // Dropping a folder onto itself. Descendant cycles are the server's to catch (CannotMoveDirectoryIntoItself).
    }

    return true;
}

function onDrop(event) {
    const isDesktopFiles = event.dataTransfer?.types?.includes('Files') && !dragState;

    if (isDesktopFiles) {
        onDropDesktopFiles(event);

        return;
    }

    if (!dragState) {
        return;
    }

    const target = currentDropTarget(event);

    if (!target || target.directoryId === null || !isValidTarget(target)) {
        return;
    }

    event.preventDefault();

    const targetDirectoryId = target.directoryId;
    const { subjectType, ids, sourceRow, ghostEl } = dragState;

    // Settle: the ghost springs toward the target, then the source row Folds
    // once the server actually confirms the move (design plan §5, motions 7
    // and 4). Listened for below, on 'drop-settled'/'drop-refused', rather
    // than assumed here -- the drop is not real until UploadTray says so.
    if (ghostEl) {
        const rect = target.el.getBoundingClientRect();

        shell()
            .move(ghostEl, { scale: [1.02, 0.6], opacity: [1, 0] }, shell().springs.settle)
            .then(() => ghostEl.remove());

        positionGhost(ghostEl, rect.left + rect.width / 2, rect.top + rect.height / 2);
    }

    if (sourceRow) {
        pendingFold.set(sourceRow, { subjectType, ids });
    }

    ids.forEach((id) => {
        window.Livewire?.dispatch('shell-drop', { subjectType, subjectId: id, targetDirectoryId });
    });
}

const pendingFold = new Map();

function onDropSettled(event) {
    const { subjectType, subjectId } = event.detail ?? {};

    document.querySelectorAll('[data-file-row], [data-folder-row]').forEach((row) => {
        const subject = rowIdOf(row);

        if (subject && subject.subjectType === subjectType && subject.id === subjectId) {
            // Fold: the row that just left collapses rather than merely
            // vanishing on the next Livewire render (design plan §5,
            // motion 4).
            shell()
                .move(row, { height: [row.getBoundingClientRect().height, 0], opacity: [1, 0] }, { duration: 160 })
                .then(() => {});
        }
    });
}

function onDropRefused(event) {
    setReason(event.detail?.reason ?? null);
    window.setTimeout(() => clearReason(), 4000);
}

function onDragEnd() {
    removeGhost();
    disarmSpringLoad();

    if (acceptedEl) {
        clearAccept(acceptedEl);
        acceptedEl = null;
    }

    dragState = null;
    document.body.style.cursor = '';
}

// --- Files dragged in from the desktop ---

let dragEnterCount = 0;

function onDragEnterGrid(event) {
    if (dragState || !event.dataTransfer?.types?.includes('Files')) {
        return;
    }

    const grid = event.target.closest('[data-file-grid]');

    if (!grid) {
        return;
    }

    dragEnterCount += 1;

    const tray = uploadTrayEl();
    const archived = tray?.dataset.periodArchived === 'true';
    const canUpload = tray?.dataset.canUpload !== 'false';
    const directoryName = tray?.dataset.directoryName ?? '';

    const reason = archived
        ? 'This period is archived. Uploads are rejected.'
        : !canUpload
            ? 'No upload access here'
            : null;

    positionOverlayOverGrid(grid);
    document.dispatchEvent(new CustomEvent('shell-drag-overlay', {
        detail: { show: true, reason: reason ?? `Drop to upload into ${directoryName}` },
    }));
}

function onDragLeaveGrid(event) {
    const grid = event.target.closest('[data-file-grid]');

    if (!grid) {
        return;
    }

    dragEnterCount = Math.max(0, dragEnterCount - 1);

    if (dragEnterCount === 0) {
        document.dispatchEvent(new CustomEvent('shell-drag-overlay', { detail: { show: false, reason: null } }));
    }
}

function positionOverlayOverGrid(grid) {
    const overlay = document.querySelector('[data-drop-overlay]');

    if (!overlay) {
        return;
    }

    const rect = grid.getBoundingClientRect();

    overlay.style.top = `${rect.top}px`;
    overlay.style.left = `${rect.left}px`;
    overlay.style.width = `${rect.width}px`;
    overlay.style.height = `${rect.height}px`;
    overlay.style.background = 'color-mix(in oklab, var(--color-sheet) 96%, transparent)';
    overlay.style.outline = '2px solid var(--color-select)';
    overlay.style.outlineOffset = '-2px';
}

function onDropDesktopFiles(event) {
    const grid = event.target.closest('[data-file-grid]');
    const folderRow = event.target.closest('[data-folder-row]');

    if (!grid && !folderRow) {
        return;
    }

    event.preventDefault();
    dragEnterCount = 0;
    document.dispatchEvent(new CustomEvent('shell-drag-overlay', { detail: { show: false, reason: null } }));

    const tray = uploadTrayEl();

    if (!tray) {
        // Nothing on this page is mounted to receive the upload -- there is
        // no server-side destination for these bytes. This module never
        // invents one; see this task's report for where UploadTray is meant
        // to be embedded.
        return;
    }

    const wireId = tray.getAttribute('wire:id');
    const files = Array.from(event.dataTransfer?.files ?? []);

    if (!wireId || files.length === 0) {
        return;
    }

    if (folderRow) {
        window.Livewire?.find(wireId)?.set('uploadTargetDirectoryId', Number(folderRow.dataset.folderId));
    }

    const keyed = files.map((file, index) => ({ file, key: `${Date.now()}-${index}` }));

    keyed.forEach(({ file, key }) => {
        document.dispatchEvent(new CustomEvent('shell-upload-progress', {
            detail: { key, name: file.name, percent: 0 },
        }));
    });

    window.Livewire?.find(wireId)?.uploadMultiple(
        'incoming',
        keyed.map((k) => k.file),
        () => {
            keyed.forEach(({ key }) => {
                document.dispatchEvent(new CustomEvent('shell-upload-settled', { detail: { key, status: 'done' } }));
            });
        },
        () => {
            keyed.forEach(({ key }) => {
                document.dispatchEvent(new CustomEvent('shell-upload-settled', { detail: { key, status: 'failed' } }));
            });
        },
        (event_) => {
            keyed.forEach(({ key }) => {
                document.dispatchEvent(new CustomEvent('shell-upload-progress', {
                    detail: { key, name: '', percent: event_.detail?.progress ?? 0 },
                }));
            });
        },
    );
}

// --- Wiring ---

function init() {
    watchForNewRows();

    document.addEventListener('dragstart', onDragStart);
    document.addEventListener('drag', onDrag);
    document.addEventListener('dragover', onDragOver);
    document.addEventListener('dragenter', onDragEnterGrid);
    document.addEventListener('dragleave', onDragLeaveGrid);
    document.addEventListener('drop', onDrop);
    document.addEventListener('dragend', onDragEnd);

    document.addEventListener('drop-settled', onDropSettled);
    document.addEventListener('drop-refused', onDropRefused);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
