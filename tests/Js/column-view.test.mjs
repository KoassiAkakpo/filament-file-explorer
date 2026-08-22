import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadExplorer, selected, wireCalls } from './harness.mjs';

/** A single pane, as the column view renders one: a vertical list. */
const pane = () => loadExplorer({
    viewMode: 'columns',
    columns: 1,
    items: [
        { type: 'folder', id: 11 },
        { type: 'file', id: 12 },
        { type: 'file', id: 13 },
    ],
});

test('right arrow descends into the selected folder', () => {
    const { component, sel, calls } = pane();

    sel.toggle('folder', 11, false);
    calls.length = 0;

    component.moveSelection('ArrowRight', false);

    assert.deepEqual(wireCalls(calls), ['columnInto(11)']);
});

test('right arrow does nothing on a file, since there is nothing to enter', () => {
    const { component, sel, calls } = pane();

    sel.toggle('file', 12, false);
    calls.length = 0;

    component.moveSelection('ArrowRight', false);

    assert.deepEqual(wireCalls(calls), []);
    // And it does not fall through to moving inside the pane either: in this
    // view, right means "into", not "next".
    assert.deepEqual(selected(sel), ['file:12']);
});

test('right arrow does nothing with several folders selected', () => {
    const { component, sel, calls } = pane();

    sel.select([11, 99], []);
    calls.length = 0;

    component.moveSelection('ArrowRight', false);

    assert.deepEqual(wireCalls(calls), []);
});

test('left arrow walks back out', () => {
    const { component, calls } = pane();

    component.moveSelection('ArrowLeft', false);

    assert.deepEqual(wireCalls(calls), ['columnBack()']);
});

test('shift+arrow keeps extending inside the pane instead of navigating', () => {
    const { component, sel, calls } = pane();

    sel.toggle('file', 12, false);
    calls.length = 0;

    component.moveSelection('ArrowRight', true);

    // Selecting, not navigating: nothing reached the component but the sync.
    assert.deepEqual(selected(sel), ['file:12', 'file:13']);
    assert.deepEqual(wireCalls(calls), []);
});

test('up and down stay inside the pane', () => {
    const { component, sel, calls } = pane();

    sel.toggle('folder', 11, false);
    calls.length = 0;

    component.moveSelection('ArrowDown', false);

    assert.deepEqual(selected(sel), ['file:12']);
    assert.deepEqual(wireCalls(calls), []);
});

test('the other views keep moving with left and right', () => {
    const { component, sel, calls } = loadExplorer({
        items: [
            { type: 'file', id: 1 },
            { type: 'file', id: 2 },
        ],
    });

    sel.toggle('file', 1, false);
    calls.length = 0;

    component.moveSelection('ArrowRight', false);

    // No navigation outside the column view: right is "next item" everywhere
    // else, and that must not change.
    assert.deepEqual(selected(sel), ['file:2']);
    assert.deepEqual(wireCalls(calls), []);
});
