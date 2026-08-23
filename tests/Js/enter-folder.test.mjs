import assert from 'node:assert/strict';
import test from 'node:test';

import { loadExplorer, selected, wireCalls } from './harness.mjs';

/**
 * A double-click on a folder is a click first, and that click queues a debounced
 * setSelection. The navigation goes out on the second click, the sync forty
 * milliseconds later — so the server cleared the selection and was then told to
 * select the folder just left. The $wire.selectedFolders watch read that back as
 * a real selection, and the toolbar said "1 selected" over a folder that does
 * not appear in its own listing.
 */
function explorer() {
    return loadExplorer({
        items: [
            { type: 'folder', id: 11 },
            { type: 'file', id: 22 },
        ],
    });
}

const click = (el) => ({ shiftKey: false, ctrlKey: false, metaKey: false, target: el, currentTarget: el });

test('a click queues a sync', () => {
    const app = explorer();

    app.sel.click('folder', 11, click(app.elements[0]), app.elements[0]);

    assert.equal(app.sel.syncPending(), true);

    app.runTimers();

    assert.deepEqual(wireCalls(app.calls), ['setSelection([11], [])']);
});

test('opening a folder drops the sync that had not gone out', () => {
    const app = explorer();

    app.sel.click('folder', 11, click(app.elements[0]), app.elements[0]);
    app.component.enterFolder(11);

    // The queued setSelection is gone rather than racing the navigation.
    assert.equal(app.sel.syncPending(), false);

    app.runTimers();

    assert.deepEqual(wireCalls(app.calls), ['navigateToFolder(11)']);
});

test('opening a folder clears the selection without waiting for the server', () => {
    const app = explorer();

    app.sel.click('folder', 11, click(app.elements[0]), app.elements[0]);
    app.component.enterFolder(11);

    // The toolbar counts this, so it has to be right the moment the folder
    // opens — not one round trip later.
    assert.deepEqual(selected(app.sel), []);
});

test('the sync cancel has one owner', () => {
    const app = explorer();

    app.sel.click('folder', 11, click(app.elements[0]), app.elements[0]);
    assert.equal(app.sel.syncPending(), true);

    app.sel.cancelSync();
    assert.equal(app.sel.syncPending(), false);

    app.runTimers();

    // Nothing reached the server, and nothing had to reach into the timer from
    // outside to arrange it.
    assert.deepEqual(wireCalls(app.calls), []);
});
