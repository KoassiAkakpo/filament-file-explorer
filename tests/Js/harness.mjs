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
export function loadExplorer({ items = [], columns = 4 } = {}) {
    const realm = createRealm();
    const first = createExplorer(realm, { items, columns });

    return {
        ...first,
        /**
         * A second explorer on the same page, sharing the realm and therefore
         * the Alpine stores — which is the situation the selection used to get
         * wrong.
         */
        spawn: (options = {}) => createExplorer(realm, { columns, ...options }),
        /**
         * The same explorer, initialised again.
         *
         * This is what a Livewire round trip does: the root x-data attribute is
         * rewritten and Alpine runs the initialiser afresh. The selection has to
         * survive it, or every keystroke starts from nothing.
         */
        reinit: (options = {}) => createExplorer(realm, {
            columns,
            items,
            componentId: first.componentId,
            // Same component, so the same $wire and the same record of calls.
            reuse: { wire: first.wire, calls: first.calls },
            ...options,
        }),
        // The stores really are shared between the two, which is what makes
        // the isolation worth asserting.
        Alpine: realm.Alpine,
    };
}

function createRealm() {
    const source = readFileSync(resolve(here, '../../resources/js/file-explorer.js'), 'utf8');

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
        window: {
            addEventListener() {},
            removeEventListener() {},
            dispatchEvent() {},
        },
        document: { querySelector: () => null, hidden: false, addEventListener() {} },
        Alpine,
        setInterval: () => 0,
        clearInterval: () => {},
        setTimeout: (fn) => fn === undefined ? 0 : 0,
        clearTimeout: () => {},
        console,
    };
    context.globalThis = context;

    vm.createContext(context);
    vm.runInContext(source, context);

    return { context, Alpine, spawned: 0 };
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

function createExplorer(realm, { items = [], columns = 4, componentId = null, reuse = null } = {}) {
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
    };

    const component = realm.context.window.FileExplorerUi({
        scopeKey: 'library',
        rootFolderId: 1,
        componentId: id,
        abilities: { browse: true, copy: true, move: true, rename: true, delete: true, deleteFolder: true },
    });

    Object.assign(component, {
        $wire: wire,
        $watch: () => {},
        $el: container,
        $root: container,
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
