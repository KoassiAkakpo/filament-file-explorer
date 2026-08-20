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
    const source = readFileSync(resolve(here, '../../resources/js/file-explorer.js'), 'utf8');

    const stores = {};
    const Alpine = {
        store(name, value) {
            if (value !== undefined) stores[name] = value;

            return stores[name];
        },
    };

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

    const calls = [];
    const wire = {
        selectedFolders: [],
        selectedFiles: [],
        setSelection(folders, files) {
            calls.push(['setSelection', folders, files]);
        },
        clearSelection() {
            calls.push(['clearSelection']);
        },
    };

    const context = {
        window: {},
        document: { querySelector: () => container, hidden: false, addEventListener() {} },
        Alpine,
        setInterval: () => 0,
        clearInterval: () => {},
        setTimeout: (fn) => { calls.push(['setTimeout']); return 0; },
        clearTimeout: () => {},
        console,
    };
    context.globalThis = context;

    vm.createContext(context);
    vm.runInContext(source, context);

    const component = context.window.FileExplorerUi({
        scopeKey: 'library',
        rootFolderId: 1,
        abilities: { browse: true, copy: true, move: true, rename: true, delete: true, deleteFolder: true },
    });

    Object.assign(component, {
        $wire: wire,
        $watch: () => {},
        $el: container,
        $root: container,
        $refs: {},
    });

    component.init();

    return { component, sel: Alpine.store('feSel'), calls, items, elements };
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
