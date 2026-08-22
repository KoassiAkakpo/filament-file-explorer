import assert from 'node:assert/strict';
import test from 'node:test';

import { loadExplorer, selected } from './harness.mjs';

/**
 * A finger is not a mouse, and the explorer is a mouse layout: the drag arms at
 * five pixels, which is also how far a finger moves when it means to scroll.
 * These cover the gesture split that fixes it — a touch never drags, it holds.
 */
function explorer() {
    return loadExplorer({
        items: [
            { type: 'folder', id: 11 },
            { type: 'file', id: 22 },
        ],
    });
}

/** A press, with a target that answers closest() the way the DOM would. */
function press(pointerType, { x = 0, y = 0, item = null } = {}) {
    const dispatched = [];
    const element = {
        dispatchEvent(event) {
            dispatched.push(event);

            return true;
        },
    };

    let prevented = 0;

    return {
        dispatched,
        element,
        prevented: () => prevented,
        event: {
            button: 0,
            pointerType,
            clientX: x,
            clientY: y,
            stopPropagation() {},
            preventDefault() {
                prevented++;
            },
            // Guard selectors find nothing; '[data-fe-type]' finds the item.
            target: { closest: (selector) => (item && selector === '[data-fe-type]' ? element : null) },
        },
    };
}

function move(store, x, y) {
    let prevented = 0;

    store.pointerMove({
        clientX: x,
        clientY: y,
        preventDefault() {
            prevented++;
        },
    });

    return prevented;
}

test('a touch that moves scrolls instead of dragging', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event } = press('touch', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);

    // Well past the five pixels that arm a mouse drag.
    const prevented = move(feDrag, 60, 60);

    // Nothing armed and nothing prevented, so the browser goes on scrolling —
    // which is the first thing a tablet user does.
    assert.equal(feDrag.active, false);
    assert.equal(prevented, 0);
});

test('a mouse that moves still drags', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event } = press('mouse', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);
    move(feDrag, 60, 60);

    assert.equal(feDrag.active, true);
});

test('a hold on an item opens its context menu', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event, dispatched } = press('touch', { x: 30, y: 40, item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);

    assert.equal(dispatched.length, 0, 'the menu must wait for the hold');

    app.runTimers();

    assert.equal(dispatched.length, 1);
    assert.equal(dispatched[0].type, 'fe-context');
    assert.equal(dispatched[0].bubbles, true);
    assert.equal(dispatched[0].detail.type, 'folder');
    assert.equal(dispatched[0].detail.id, 11);
    // The point the finger went down on, not wherever it ended up.
    assert.equal(dispatched[0].detail.x, 30);
    assert.equal(dispatched[0].detail.y, 40);
});

test('a hold selects the item it is on', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event } = press('touch', { item: true });

    feDrag.pointerDown(event, 'file', 22, 'report.pdf', app.wire, app.sel);
    app.runTimers();

    // Same as a right-click: the menu acts on something, so the hold makes sure
    // there is something.
    assert.deepEqual(selected(app.sel), ['file:22']);
});

test('a hold does not disturb a selection the item is already in', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');

    app.sel.select([11], [22]);

    const { event } = press('touch', { item: true });

    feDrag.pointerDown(event, 'file', 22, 'report.pdf', app.wire, app.sel);
    app.runTimers();

    assert.deepEqual(selected(app.sel), ['folder:11', 'file:22']);
});

test('moving cancels the hold', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event, dispatched } = press('touch', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);
    move(feDrag, 40, 0);
    app.runTimers();

    assert.equal(dispatched.length, 0);
});

test('a small drift does not cancel the hold', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event, dispatched } = press('touch', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);
    // A finger never holds perfectly still.
    move(feDrag, 4, 3);
    app.runTimers();

    assert.equal(dispatched.length, 1);
});

test('releasing cancels the hold', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event, dispatched } = press('touch', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);
    feDrag.pointerUp({ clientX: 0, clientY: 0 });
    app.runTimers();

    // A tap is a tap: it selects, and it must not also open a menu a moment
    // after the finger has gone.
    assert.equal(dispatched.length, 0);
});

test('the hold swallows the click that follows it', () => {
    const app = explorer();
    const feDrag = app.Alpine.store('feDrag');
    const { event } = press('touch', { item: true });

    feDrag.pointerDown(event, 'folder', 11, 'Reports', app.wire, app.sel);
    app.runTimers();

    // Otherwise the release that ends the hold also lands as a plain click and
    // the menu opens on an item the click has just re-selected alone.
    assert.equal(feDrag.consumeClickSuppression(), true);
});

test('a hold on empty space opens the folder menu', () => {
    const app = explorer();
    const { event } = press('touch', { x: 50, y: 60 });

    app.component.onEmptyPointerDown(event);

    assert.equal(app.component.ctx.open, false);

    app.runTimers();

    assert.equal(app.component.ctx.open, true);
    assert.equal(app.component.ctx.type, 'empty');
});

test('a mouse on empty space arms nothing, so the marquee keeps it', () => {
    const app = explorer();
    const { event } = press('mouse', { x: 50, y: 60 });

    const before = app.pending();
    app.component.onEmptyPointerDown(event);

    assert.equal(app.pending(), before);
    assert.equal(app.component.ctx.open, false);
});
