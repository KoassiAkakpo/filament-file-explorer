import assert from 'node:assert/strict';
import { test } from 'node:test';
import { at, loadExplorer, selected, wireCalls } from './harness.mjs';

/**
 * Two explorers on one page — the panel's own and a FileExplorerPicker in a
 * modal — sharing one realm and therefore the Alpine stores.
 *
 * The selection used to be a single store, so both read and wrote the same one:
 * clicking in the modal moved the page's highlight, and setSelection went to
 * whichever component initialised last.
 */
function twoExplorers() {
    const page = loadExplorer({
        items: [
            { type: 'folder', id: 11 },
            { type: 'file', id: 12 },
            { type: 'file', id: 13 },
        ],
    });

    const picker = page.spawn({
        items: [
            { type: 'folder', id: 21 },
            { type: 'file', id: 22 },
            { type: 'file', id: 23 },
        ],
    });

    return { page, picker };
}

test('two explorers hold separate selections', () => {
    const { page, picker } = twoExplorers();

    assert.notEqual(page.sel, picker.sel);

    page.sel.toggle('file', 12, false);
    picker.sel.toggle('file', 22, false);

    assert.deepEqual(selected(page.sel), ['file:12']);
    assert.deepEqual(selected(picker.sel), ['file:22']);
});

test('selecting in one leaves the other alone', () => {
    const { page, picker } = twoExplorers();

    page.sel.select([11], [12, 13]);

    assert.deepEqual(selected(picker.sel), []);
    assert.equal(at(picker.sel.anchor), null);
    assert.equal(at(picker.sel.cursor), null);
});

test('each syncs to its own component', () => {
    const { page, picker } = twoExplorers();

    page.sel.toggle('file', 12, false);
    page.sel.flushSync();

    // The bug this replaces: one shared wire reference, set by whichever
    // component initialised last, so both explorers reported to that one.
    assert.deepEqual(wireCalls(page.calls), ['setSelection([], [12])']);
    assert.deepEqual(wireCalls(picker.calls), []);

    picker.sel.toggle('folder', 21, false);
    picker.sel.flushSync();

    assert.deepEqual(wireCalls(picker.calls), ['setSelection([21], [])']);
    assert.deepEqual(wireCalls(page.calls), ['setSelection([], [12])']);
});

test('clearing one does not clear the other', () => {
    const { page, picker } = twoExplorers();

    page.sel.select([], [12]);
    picker.sel.select([], [22]);

    picker.sel.clear();

    assert.deepEqual(selected(page.sel), ['file:12']);
    assert.deepEqual(selected(picker.sel), []);
    assert.deepEqual(wireCalls(picker.calls), ['clearSelection()']);
    assert.deepEqual(wireCalls(page.calls), []);
});

test('the keyboard in one moves only its own cursor', () => {
    const { page, picker } = twoExplorers();

    page.sel.toggle('file', 12, false);
    picker.sel.toggle('file', 22, false);

    page.component.moveSelection('ArrowRight', true);

    assert.deepEqual(selected(page.sel), ['file:12', 'file:13']);
    assert.equal(at(page.sel.cursor), 'file:13');

    // Reading the page's items would have been the giveaway: the picker holds
    // different ids entirely.
    assert.deepEqual(selected(picker.sel), ['file:22']);
    assert.equal(at(picker.sel.cursor), 'file:22');
});

test('selecting everything in one selects only its own items', () => {
    const { page, picker } = twoExplorers();

    page.sel.selectAll(page.container);

    assert.deepEqual(selected(page.sel), ['folder:11', 'file:12', 'file:13']);
    assert.deepEqual(selected(picker.sel), []);
});

test('a drag prepares the selection of the explorer it started in', () => {
    const { page, picker } = twoExplorers();
    const feDrag = page.Alpine.store('feDrag');

    picker.sel.select([], [22]);

    // The views hand pointerDown their own selection. The drag store is still
    // one for the page — a user drags in one explorer at a time — so what it
    // must not do is reach for a selection of its own.
    feDrag.pointerDown(
        { button: 0, clientX: 0, clientY: 0, stopPropagation() {}, target: { closest: () => null } },
        'folder',
        11,
        'Reports',
        page.wire,
        page.sel,
    );

    assert.deepEqual(selected(page.sel), ['folder:11']);
    assert.deepEqual(selected(picker.sel), ['file:22']);
    assert.equal(feDrag.sel, page.sel);
});
