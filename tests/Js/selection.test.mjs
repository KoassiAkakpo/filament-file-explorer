import assert from 'node:assert/strict';
import { test } from 'node:test';
import { at, loadExplorer, selected } from './harness.mjs';

/** Six files in a 4-column grid: two rows. */
const grid = () => loadExplorer({
    items: [
        { type: 'file', id: 1 },
        { type: 'file', id: 2 },
        { type: 'file', id: 3 },
        { type: 'file', id: 4 },
        { type: 'file', id: 5 },
        { type: 'file', id: 6 },
    ],
    columns: 4,
});

test('shift+arrow keeps extending instead of stopping at two items', () => {
    const { component, sel } = grid();

    sel.toggle('file', 2, false);

    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3']);

    // This is the bug: the cursor came from the anchor, so every further
    // extension re-selected the same pair.
    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3', 'file:4']);

    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3', 'file:4', 'file:5']);
});

test('shift+arrow the other way shrinks the range it grew', () => {
    const { component, sel } = grid();

    sel.toggle('file', 2, false);

    component.moveSelection('ArrowRight', true);
    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3', 'file:4']);

    component.moveSelection('ArrowLeft', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3']);

    // Past the anchor, the range flips to the other side of it.
    component.moveSelection('ArrowLeft', true);
    component.moveSelection('ArrowLeft', true);
    assert.deepEqual(selected(sel), ['file:1', 'file:2']);
});

test('the anchor stays where the selection started', () => {
    const { component, sel } = grid();

    sel.toggle('file', 3, false);

    component.moveSelection('ArrowRight', true);
    component.moveSelection('ArrowRight', true);

    assert.equal(at(sel.anchor), 'file:3');
    assert.equal(at(sel.cursor), 'file:5');
});

test('shift+down extends by rows in the grid', () => {
    const { component, sel } = grid();

    sel.toggle('file', 1, false);

    component.moveSelection('ArrowDown', true);

    // Row two, same column: items 1 through 5.
    assert.deepEqual(selected(sel), ['file:1', 'file:2', 'file:3', 'file:4', 'file:5']);
});

test('an arrow without shift moves the anchor with the cursor', () => {
    const { component, sel } = grid();

    sel.toggle('file', 2, false);

    component.moveSelection('ArrowRight', false);
    assert.deepEqual(selected(sel), ['file:3']);
    assert.equal(at(sel.anchor), 'file:3');

    // So a shift-extension afterwards runs from the item just moved to.
    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:3', 'file:4']);
});

test('shift+arrow with nothing selected starts a range', () => {
    const { component, sel } = grid();

    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:1']);

    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:1', 'file:2']);
});

test('shift+click sets the far end a later shift+arrow extends from', () => {
    const { component, sel } = grid();

    sel.toggle('file', 2, false);
    sel.click('file', 4, { shiftKey: true }, component.$el);

    assert.deepEqual(selected(sel), ['file:2', 'file:3', 'file:4']);

    component.moveSelection('ArrowRight', true);
    assert.deepEqual(selected(sel), ['file:2', 'file:3', 'file:4', 'file:5']);
});

test('a range spanning folders and files keeps both', () => {
    const { component, sel } = loadExplorer({
        items: [
            { type: 'folder', id: 10 },
            { type: 'folder', id: 11 },
            { type: 'file', id: 1 },
            { type: 'file', id: 2 },
        ],
        columns: 4,
    });

    sel.toggle('folder', 11, false);

    component.moveSelection('ArrowRight', true);
    component.moveSelection('ArrowRight', true);

    assert.deepEqual(selected(sel), ['folder:11', 'file:1', 'file:2']);
});

test('clearing forgets both ends', () => {
    const { sel } = grid();

    sel.toggle('file', 2, false);
    sel.clear({ sync: false });

    assert.equal(at(sel.anchor), null);
    assert.equal(at(sel.cursor), null);
});

test('ctrl+a selects everything on show', () => {
    const { component, sel } = grid();

    sel.selectAll(component.$el);

    assert.equal(sel.count(), 6);
});
