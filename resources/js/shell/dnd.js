/**
 * Dragging in the file browser: rows onto folders to move them, and files
 * from the desktop onto the listing to upload them.
 *
 * Native HTML5 drag events rather than a drag library, and the reason is not
 * preference. Dragging a file in from the operating system is delivered only
 * through the native `drop` event's DataTransfer.files -- no JavaScript
 * library can synthesise that, because the bytes come from outside the page.
 * Since the external case must be native anyway, doing row dragging the same
 * way keeps one set of semantics instead of two.
 *
 * Nothing here decides what is allowed. A drop sends three integers to
 * Browser::dropMove(), which re-resolves and re-authorises both ends; the ring
 * this draws is a hint about the answer, never the answer. See decision on
 * menus: the client predicts, the server decides.
 */

const ACCEPT_CLASSES = ['ring-2', 'ring-inset', 'ring-select'];

/** The row currently being dragged, as {type, id}. */
let dragging = null;

function livewireComponent(el) {
    const root = el.closest('[wire\\:id]');

    if (!root || !window.Livewire) {
        return null;
    }

    return window.Livewire.find(root.getAttribute('wire:id'));
}

/**
 * A solid inset ring, never a dashed outline.
 *
 * Dashed borders are the web's default for "drop here" and they read as
 * provisional -- an area under construction rather than a target that will
 * accept what you are holding. The design plan calls for 2px solid inset, so
 * the row keeps its size and only its edge changes.
 */
function markAccepting(el) {
    el.classList.add(...ACCEPT_CLASSES);
}

function clearAccepting(el) {
    el.classList.remove(...ACCEPT_CLASSES);
}

function clearAllAccepting() {
    document
        .querySelectorAll('.ring-select')
        .forEach((el) => clearAccepting(el));
}

/** Folder rows in the listing, and directories in the sidebar tree. */
function dropTargetFor(event) {
    const folderRow = event.target.closest?.('[data-drop-directory-id]');

    return folderRow ?? null;
}

function subjectOf(row) {
    if (row.hasAttribute('data-file-id')) {
        return { type: 'file', id: Number(row.getAttribute('data-file-id')) };
    }

    if (row.hasAttribute('data-directory-id')) {
        return { type: 'directory', id: Number(row.getAttribute('data-directory-id')) };
    }

    return null;
}

function onDragStart(event) {
    const row = event.target.closest?.('[draggable="true"]');

    if (!row) {
        return;
    }

    dragging = subjectOf(row);

    if (!dragging) {
        return;
    }

    event.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag unless something is set.
    event.dataTransfer.setData('text/plain', `${dragging.type}:${dragging.id}`);
}

function onDragEnd() {
    dragging = null;
    clearAllAccepting();
}

function onDragOver(event) {
    const target = dropTargetFor(event);

    // An external file drag carries no `dragging` row but does carry files;
    // the whole listing accepts those.
    const external = !dragging && [...(event.dataTransfer?.types ?? [])].includes('Files');

    if (!target && !external) {
        return;
    }

    // Dropping a folder onto itself is the one refusal worth predicting in
    // the client, because the server's answer is never in doubt and the ring
    // would otherwise invite an action that always fails.
    if (target && dragging?.type === 'directory'
        && Number(target.getAttribute('data-drop-directory-id')) === dragging.id) {
        return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';

    if (target) {
        clearAllAccepting();
        markAccepting(target);
    }
}

function onDragLeave(event) {
    const target = dropTargetFor(event);

    if (target && !target.contains(event.relatedTarget)) {
        clearAccepting(target);
    }
}

function uploadFiles(component, files) {
    // One at a time, in order: the component holds a single `upload`
    // property, and starting a second upload into it before the first has
    // committed is the race issue #106 was.
    const next = (index) => {
        if (index >= files.length) {
            return;
        }

        component.upload(
            'upload',
            files[index],
            () => {
                component.call('store').then(() => next(index + 1));
            },
            () => next(index + 1),
        );
    };

    next(0);
}

function onDrop(event) {
    const target = dropTargetFor(event);
    const files = [...(event.dataTransfer?.files ?? [])];

    clearAllAccepting();

    if (files.length > 0) {
        const component = livewireComponent(event.target);

        if (!component) {
            return;
        }

        event.preventDefault();
        uploadFiles(component, files);

        return;
    }

    if (!target || !dragging) {
        return;
    }

    event.preventDefault();

    const component = livewireComponent(target);

    if (!component) {
        return;
    }

    component.call(
        'dropMove',
        dragging.type,
        dragging.id,
        Number(target.getAttribute('data-drop-directory-id')),
    );

    dragging = null;
}

/**
 * Listeners are delegated to the document rather than bound per row, because
 * Livewire replaces the listing's DOM on every update and anything bound to a
 * row would be lost the first time a file was renamed.
 */
export function startDragAndDrop() {
    document.addEventListener('dragstart', onDragStart);
    document.addEventListener('dragend', onDragEnd);
    document.addEventListener('dragover', onDragOver);
    document.addEventListener('dragleave', onDragLeave);
    document.addEventListener('drop', onDrop);

    // A file dropped anywhere else would otherwise be opened by the browser,
    // replacing the page -- which looks exactly like the app crashing.
    window.addEventListener('dragover', (event) => {
        if ([...(event.dataTransfer?.types ?? [])].includes('Files')) {
            event.preventDefault();
        }
    });

    window.addEventListener('drop', (event) => {
        if (!event.defaultPrevented && [...(event.dataTransfer?.files ?? [])].length > 0) {
            event.preventDefault();
        }
    });
}
