import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Loads resources/js/file-explorer.js the way the browser would, with just
 * enough of Alpine and the DOM for the selection and keyboard logic.
 *
 * No build step and no dependencies: the file is a plain script, so it runs in
 * a vm context with globals stubbed. Everything the explorer does to the DOM
 * goes through data-fe-items / data-fe-type / data-id, which makes fake items
 * cheap to build.
 */
export function loadExplorer(options = {}) {
    const { columns = 4 } = options;
    const realm = createRealm();
    const first = createExplorer(realm, { columns, ...options });

    return {
        ...first,
        /**
         * A second explorer on the same page, sharing the realm and therefore
         * the Alpine stores — which is the situation the selection used to get
         * wrong.
         */
        spawn: (extra = {}) => createExplorer(realm, { columns, ...options, ...extra }),
        /**
         * The same explorer, initialised again.
         *
         * This is what a Livewire round trip does: the root x-data attribute is
         * rewritten and Alpine runs the initialiser afresh. The selection has to
         * survive it, or every keystroke starts from nothing.
         */
        reinit: (extra = {}) => createExplorer(realm, {
            columns,
            ...options,
            componentId: first.componentId,
            // Same component, so the same $wire and the same record of calls.
            reuse: { wire: first.wire, calls: first.calls },
            ...extra,
        }),
        // The stores really are shared between the two, which is what makes
        // the isolation worth asserting.
        Alpine: realm.Alpine,
        /** Every HTTP request the slice transport made, in order. */
        requests: realm.requests,
        /** Replaces what those requests answer with. */
        answerWith: realm.answerWith,
        /** Lets a scheduled long press elapse. Nothing fires without it. */
        runTimers: realm.runTimers,
        pending: () => realm.timers.length,
    };
}

function createRealm() {
    const source = readFileSync(resolve(here, '../../resources/js/file-explorer.js'), 'utf8');

    const timers = [];
    let timerId = 0;

    const requests = [];

    // Answers every slice request as the controller would: the last one of a
    // file carries the signed reference back. A test can replace it to make one
    // fail.
    let responder = (url) => {
        if (url.includes('/begin')) {
            return { token: 'T'.repeat(40), chunk_bytes: 4, chunks: 1 };
        }

        return { received: 1, chunks: 1, complete: true, path: 'signed:tmp-file' };
    };

    function realmFetch(url, options) {
        requests.push({ url, method: options.method || 'GET', body: options.body });

        const answer = responder(String(url), options, requests.length);

        if (answer instanceof Error) return Promise.reject(answer);

        if (answer && answer.status && answer.status >= 400) {
            return Promise.resolve({ ok: false, status: answer.status, json: () => Promise.resolve({}) });
        }

        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(answer) });
    }

    const stores = {};
    const Alpine = {
        store(name, value) {
            if (value !== undefined) stores[name] = value;

            return stores[name];
        },
    };

    const context = {
        // Enough of window for the drag store, which binds its pointer
        // listeners there.
        //
        // Alpine is on it deliberately: the script registers its stores at load
        // when it finds window.Alpine, and without that the whole bootstrap path
        // never ran here — a call to a function renamed out from under it threw
        // in the browser with every test green.
        window: {
            get Alpine() {
                return Alpine;
            },
            addEventListener() {},
            removeEventListener() {},
            dispatchEvent() {},
            // The context menu positions itself inside the viewport, so it has
            // to have one.
            innerWidth: 1200,
            innerHeight: 800,
        },
        // Enough document for the mouse drag path, which is under test now that
        // touch takes a different one: it makes a ghost, marks the body, and asks
        // what is under the pointer.
        document: {
            querySelector: () => null,
            hidden: false,
            addEventListener() {},
            body: {
                classList: { add() {}, remove() {} },
                appendChild() {},
            },
            createElement: () => ({
                style: {},
                classList: { add() {}, remove() {} },
                remove() {},
            }),
            elementFromPoint: () => null,
        },
        Alpine,
        // The slice transport talks HTTP, so the realm has to. Recorded rather
        // than mocked per test: what matters about a sliced upload is the
        // sequence of requests it makes, and that only reads as a sequence if
        // one place collects it.
        fetch: (url, options = {}) => realmFetch(url, options),
        FormData: class {
            constructor() {
                this.parts = [];
            }

            append(name, value, filename) {
                this.parts.push([name, value, filename]);
            }
        },
        setInterval: () => 0,
        clearInterval: () => {},
        // Timers are queued, never fired on their own: the debounced selection
        // sync must stay unfired for the tests that count $wire calls, while the
        // long press has to be able to elapse on demand. runTimers() below is
        // the only thing that runs them.
        setTimeout(fn, delay) {
            if (typeof fn !== 'function') return 0;

            timers.push({ id: ++timerId, fn, delay });

            return timerId;
        },
        clearTimeout(id) {
            const at = timers.findIndex((timer) => timer.id === id);

            if (at !== -1) timers.splice(at, 1);
        },
        // The drag store announces a long press with one, and dispatches the
        // same event the right-click handler does.
        CustomEvent: class {
            constructor(type, options = {}) {
                this.type = type;
                this.detail = options.detail ?? null;
                this.bubbles = !!options.bubbles;
            }
        },
        console,
    };
    context.globalThis = context;

    vm.createContext(context);
    vm.runInContext(source, context);

    /**
     * Runs every timer whose delay has come, longest first is not needed — the
     * explorer never schedules a timer from a timer.
     */
    const runTimers = (elapsed = Infinity) => {
        const due = timers.filter((timer) => timer.delay <= elapsed);

        for (const timer of due) {
            const at = timers.indexOf(timer);

            if (at !== -1) timers.splice(at, 1);

            timer.fn();
        }
    };

    return {
        context,
        Alpine,
        spawned: 0,
        runTimers,
        timers,
        requests,
        answerWith(fn) {
            responder = fn;
        },
    };
}

/**
 * Enough of Alpine's reactivity to model the one constraint that matters: nested
 * objects are handed out wrapped, so anything stored as a property gets a
 * wrapper around it.
 *
 * The Livewire $wire proxy cannot survive that — its methods stop working when
 * invoked on a wrapper — which is why the selection keeps it in a closure. A
 * stub without this could not tell the difference, and did not: the bug shipped
 * with all seventeen tests green.
 */
/** Memoised across calls, the way Vue and Alpine memoise: one proxy per object. */
const reactiveProxies = new WeakMap();

function reactive(target) {
    if (target === null || typeof target !== 'object') return target;
    if (reactiveProxies.has(target)) return reactiveProxies.get(target);

    const proxy = new Proxy(target, {
        get(object, key, receiver) {
            const value = Reflect.get(object, key, receiver);

            // $wire, $el, $refs and friends are Alpine magics: they are resolved
            // beside the data, not inside it, so they come back untouched. That
            // is what makes holding $wire in a closure work and holding it as a
            // property of the data not.
            if (typeof key === 'string' && key.startsWith('$')) {
                return value;
            }

            return (typeof value === 'object' && value !== null) ? reactive(value) : value;
        },
    });

    reactiveProxies.set(target, proxy);

    return proxy;
}

function createExplorer(realm, { items = [], columns = 4, componentId = null, reuse = null, viewMode = 'grid', uploadLimits = {}, abilities = null } = {}) {
    // Livewire gives every component on the page its own id, and the selection
    // is keyed by it — so the harness has to hand out distinct ones too, or two
    // explorers would look like one.
    const id = componentId ?? `explorer-${++realm.spawned}`;

    const elements = items.map((item, index) => ({
        _item: item,
        offsetTop: Math.floor(index / columns) * 100,
        offsetLeft: (index % columns) * 100,
        getAttribute(name) {
            if (name === 'data-fe-type') return item.type;
            if (name === 'data-id') return String(item.id);

            return null;
        },
        focus() {},
        scrollIntoView() {},
        closest: () => container,
    }));

    const container = {
        querySelectorAll: () => elements,
        closest: () => container,
        matches: () => true,
        // The view mode is read off this attribute rather than kept in x-data,
        // so the harness has to answer it the way the DOM does.
        getAttribute: (name) => (name === 'data-fe-view' ? viewMode : null),
    };

    const calls = reuse?.calls ?? [];

    /**
     * Stands in for the Livewire proxy: it refuses to be called on a wrapper
     * the way the real one effectively does, and it records what the server
     * would then be holding, so an initialiser that reseeds from it sees what a
     * browser would.
     */
    const wire = reuse?.wire ?? {
        selectedFolders: [],
        selectedFiles: [],
        setSelection(folders, files) {
            assertRawWire(this, wire);
            this.selectedFolders = folders;
            this.selectedFiles = files;
            calls.push(['setSelection', folders, files]);
        },
        clearSelection() {
            assertRawWire(this, wire);
            this.selectedFolders = [];
            this.selectedFiles = [];
            calls.push(['clearSelection']);
        },
        navigateToFolder(folderId) {
            assertRawWire(this, wire);
            calls.push(['navigateToFolder', folderId]);
        },
        columnInto(folderId) {
            assertRawWire(this, wire);
            calls.push(['columnInto', folderId]);
        },
        columnBack() {
            assertRawWire(this, wire);
            calls.push(['columnBack']);
        },
        // The upload side. uploadMultiple is what Livewire offers and what the
        // explorer always called; upload is the single-file one it has to use
        // when the temporary disk is remote, because the other throws there.
        uploadMultiple(name, files, finish, error, progress) {
            assertRawWire(this, wire);
            calls.push(['uploadMultiple', name, files.length]);
            progress({ detail: { progress: 50 } });
            finish();
        },
        upload(name, file, finish) {
            assertRawWire(this, wire);
            calls.push(['upload', name, file.name]);
            finish();
        },
        uploadFolderId() {
            assertRawWire(this, wire);
            calls.push(['uploadFolderId']);

            return Promise.resolve(7);
        },
        set(property, value) {
            assertRawWire(this, wire);
            calls.push(['set', property, value]);

            return Promise.resolve();
        },
        call(method, ...args) {
            assertRawWire(this, wire);
            calls.push(['call', method, ...args]);

            return Promise.resolve();
        },
    };

    const component = realm.context.window.FileExplorerUi({
        scopeKey: 'library',
        rootFolderId: 1,
        componentId: id,
        viewMode,
        uploadLimits,
        abilities: abilities ?? {
            browse: true, copy: true, move: true, rename: true, delete: true, deleteFolder: true,
            upload: true, mkdir: true,
        },
    });

    Object.assign(component, {
        $wire: wire,
        $watch: () => {},
        $el: container,
        // The real $root is the component element and the items container sits
        // inside it, so querySelector has to find it from there.
        $root: { querySelector: () => container },
        $refs: {},
    });

    // Handed out the way Alpine hands out x-data: everything the tests touch
    // goes through the wrapper, as it does in a browser.
    const view = reactive(component);

    view.init();

    // The selection belongs to the component now, not to a global store: that
    // is the whole point of it, and two components give two selections.
    return { component: view, sel: view.sel, calls, items, elements, container, wire, componentId: id };
}

/**
 * "type:id" for one end of the selection. Objects cross a vm boundary, so their
 * prototype is not this realm's and deepStrictEqual refuses them.
 */
export function at(end) {
    return end === null || end === undefined ? null : `${end.type}:${end.id}`;
}

/**
 * The selection as a flat, ordered list of "type:id", which is what the
 * assertions are really about.
 */
export function selected(sel) {
    return [
        ...sel.folders.map((id) => `folder:${id}`),
        ...sel.files.map((id) => `file:${id}`),
    ];
}

/**
 * The $wire calls as flat strings.
 *
 * Same reason as at() and selected(): the arrays are built inside the vm, so
 * their prototype is not this realm's and deepStrictEqual refuses them however
 * identical they look.
 */
export function wireCalls(list) {
    return list.map(([method, ...args]) => `${method}(${args.map((arg) => JSON.stringify(arg)).join(', ')})`);
}

function assertRawWire(received, wire) {
    if (received !== wire) {
        throw new Error(
            'A $wire method was invoked on a wrapper, not on the proxy itself. '
            + 'Something is holding $wire as a property of a reactive object — keep it in a closure.'
        );
    }
}
