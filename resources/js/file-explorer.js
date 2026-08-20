function qfCtxFlyout(openDelay = 160, closeDelay = 100) {
            return {
                open: false,
                _timer: null,
                show() {
                    clearTimeout(this._timer);
                    this._timer = setTimeout(() => { this.open = true; }, openDelay);
                },
                hide() {
                    clearTimeout(this._timer);
                    this._timer = setTimeout(() => { this.open = false; }, closeDelay);
                },
            };
        }

        // Keep Livewire $wire outside Alpine reactive stores — wrapping the proxy breaks method calls.
        let feWireRef = null;

        function setFeWire(wire) {
            if (wire) {
                feWireRef = wire;
            }
        }

        function callFeWire(method, ...args) {
            const wire = feWireRef;
            if (!wire) return;

            const direct = wire[method];
            if (typeof direct === 'function') {
                return direct.apply(wire, args);
            }

            if (typeof wire.$call === 'function') {
                return wire.$call(method, ...args);
            }

            if (typeof wire.call === 'function') {
                return wire.call(method, ...args);
            }
        }

        function registerQfSelStore() {
            if (typeof Alpine === 'undefined') return;
            if (Alpine.store('feDrag')?.pointerDown) {
                return;
            }
            Alpine.store('feSel', {
                folders: [],
                files: [],
                marqueeFolders: [],
                marqueeFiles: [],
                // Last item touched: where a shift-range starts from.
                anchor: null,
                _syncTimer: null,
                setWire(wire) {
                    setFeWire(wire);
                },
                replace(folders, files) {
                    this.folders = (folders || []).map(Number);
                    this.files = (files || []).map(Number);
                },
                // replace() is also used to mirror server state, so selecting
                // from the UI goes through this instead: it syncs back.
                select(folders, files) {
                    this.replace(folders, files);
                    this.queueSync();
                },
                setAnchor(type, id) {
                    this.anchor = { type, id: Number(id) };
                },
                /**
                 * Items in the order they are laid out, read from the DOM so the
                 * grid and the row views need no separate handling. Scoped to the
                 * clicked explorer, because a page can hold more than one.
                 */
                orderedItems(scope) {
                    const container = scope?.closest?.('[data-fe-items]')
                        || (scope?.matches?.('[data-fe-items]') ? scope : null)
                        || scope?.querySelector?.('[data-fe-items]')
                        || document.querySelector('[data-fe-items]');

                    if (!container) return [];

                    return [...container.querySelectorAll('[data-fe-type][data-id]')].map((el) => ({
                        type: el.getAttribute('data-fe-type'),
                        id: Number(el.getAttribute('data-id')),
                        el,
                    }));
                },
                click(type, id, event, scope) {
                    if (event?.shiftKey) {
                        this.selectRange(type, id, scope);
                        return;
                    }

                    this.toggle(type, id, event?.ctrlKey || event?.metaKey);
                },
                selectRange(type, id, scope) {
                    const items = this.orderedItems(scope);
                    const anchor = this.anchor || { type, id: Number(id) };
                    const from = items.findIndex((item) => item.type === anchor.type && item.id === anchor.id);
                    const to = items.findIndex((item) => item.type === type && item.id === Number(id));

                    if (from === -1 || to === -1) {
                        this.toggle(type, id, false);
                        return;
                    }

                    const range = items.slice(Math.min(from, to), Math.max(from, to) + 1);

                    // The anchor stays put: dragging the shift selection back and
                    // forth grows and shrinks the same range.
                    this.select(
                        range.filter((item) => item.type === 'folder').map((item) => item.id),
                        range.filter((item) => item.type === 'file').map((item) => item.id),
                    );
                },
                selectAll(scope) {
                    const items = this.orderedItems(scope);

                    this.select(
                        items.filter((item) => item.type === 'folder').map((item) => item.id),
                        items.filter((item) => item.type === 'file').map((item) => item.id),
                    );
                },
                setMarquee(folders, files) {
                    this.marqueeFolders = (folders || []).map(Number);
                    this.marqueeFiles = (files || []).map(Number);
                },
                clearMarquee() {
                    this.marqueeFolders = [];
                    this.marqueeFiles = [];
                },
                hasFolder(id) {
                    return this.folders.includes(Number(id));
                },
                hasFile(id) {
                    return this.files.includes(Number(id));
                },
                inMarqueeFolder(id) {
                    return this.marqueeFolders.includes(Number(id));
                },
                inMarqueeFile(id) {
                    return this.marqueeFiles.includes(Number(id));
                },
                count() {
                    return this.folders.length + this.files.length;
                },
                toggle(type, id, multi) {
                    id = Number(id);
                    this.setAnchor(type, id);
                    let folders = [...this.folders];
                    let files = [...this.files];

                    if (!multi) {
                        folders = type === 'folder' ? [id] : [];
                        files = type === 'file' ? [id] : [];
                    } else if (type === 'folder') {
                        folders = folders.includes(id)
                            ? folders.filter((x) => x !== id)
                            : [...folders, id];
                    } else {
                        files = files.includes(id)
                            ? files.filter((x) => x !== id)
                            : [...files, id];
                    }

                    this.folders = folders;
                    this.files = files;
                    this.queueSync();
                },
                clear(opts = {}) {
                    const sync = opts.sync !== false;
                    this.folders = [];
                    this.files = [];
                    this.anchor = null;
                    this.clearMarquee();
                    if (this._syncTimer) clearTimeout(this._syncTimer);
                    this._syncTimer = null;
                    if (sync) callFeWire('clearSelection');
                },
                flushSync() {
                    if (this._syncTimer) clearTimeout(this._syncTimer);
                    this._syncTimer = null;
                    callFeWire('setSelection', [...this.folders], [...this.files]);
                },
                queueSync() {
                    if (this._syncTimer) clearTimeout(this._syncTimer);
                    this._syncTimer = setTimeout(() => {
                        this._syncTimer = null;
                        callFeWire('setSelection', [...this.folders], [...this.files]);
                    }, 40);
                },
            });

            Alpine.store('feDrag', {
                active: false,
                moved: false,
                suppressClick: false,
                folders: [],
                files: [],
                label: '',
                dropTargetId: null,
                ghost: null,
                startX: 0,
                startY: 0,
                _onMove: null,
                _onUp: null,
                abilities: {},

                prepareSelection(type, id) {
                    const sel = Alpine.store('feSel');
                    id = Number(id);
                    if (type === 'folder' && !sel.hasFolder(id)) {
                        sel.replace([id], []);
                    } else if (type === 'file' && !sel.hasFile(id)) {
                        sel.replace([], [id]);
                    }
                    this.folders = [...sel.folders];
                    this.files = [...sel.files];
                },

                pointerDown(event, type, id, label, wire) {
                    if (event.button !== 0) return;
                    if (event.target.closest('input, textarea, button, a, .fe-rename-input')) return;
                    // Keep default so double-click still works; stop bubble so marquee does not start
                    event.stopPropagation();

                    setFeWire(wire);
                    this.label = label || 'item';
                    this.prepareSelection(type, id);
                    this.startX = event.clientX;
                    this.startY = event.clientY;
                    this.active = false;
                    this.moved = false;
                    this.dropTargetId = null;

                    this._onMove = (e) => this.pointerMove(e);
                    this._onUp = (e) => this.pointerUp(e);
                    window.addEventListener('pointermove', this._onMove, true);
                    window.addEventListener('pointerup', this._onUp, true);
                    window.addEventListener('pointercancel', this._onUp, true);
                },

                pointerMove(event) {
                    const canMove = !!(this.abilities.move || this.abilities.copy);
                    if (!canMove) return;

                    const dx = event.clientX - this.startX;
                    const dy = event.clientY - this.startY;

                    if (!this.active && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) {
                        this.active = true;
                        this.moved = true;
                        this.suppressClick = true;
                        this.showGhost();
                        document.body.classList.add('fe-is-dragging');
                        window.dispatchEvent(new CustomEvent('fe-item-drag-start'));
                    }

                    if (!this.active) return;

                    event.preventDefault();
                    this.moveGhost(event.clientX, event.clientY);
                    this.updateDropTarget(event.clientX, event.clientY);
                },

                pointerUp(event) {
                    window.removeEventListener('pointermove', this._onMove, true);
                    window.removeEventListener('pointerup', this._onUp, true);
                    window.removeEventListener('pointercancel', this._onUp, true);
                    this._onMove = null;
                    this._onUp = null;

                    if (this.active && this.dropTargetId !== null) {
                        const targetId = Number(this.dropTargetId);
                        const folders = this.folders.filter((id) => id !== targetId);
                        const files = [...this.files];

                        if (folders.length || files.length) {
                            const copy = event.altKey || event.ctrlKey;
                            if (copy && this.abilities.copy) {
                                callFeWire('copyItemsToFolder', targetId, folders, files);
                                Alpine.store('feSel').clear({ sync: false });
                            } else if (!copy && this.abilities.move) {
                                callFeWire('moveItemsToFolder', targetId, folders, files);
                                Alpine.store('feSel').clear({ sync: false });
                            }
                        }
                    }

                    this.cleanup();
                },

                showGhost() {
                    this.hideGhost();
                    const count = this.folders.length + this.files.length;
                    const ghost = document.createElement('div');
                    ghost.className = 'fe-drag-ghost';
                    ghost.textContent = count > 1 ? (count + ' items') : this.label;
                    document.body.appendChild(ghost);
                    this.ghost = ghost;
                    this.moveGhost(this.startX, this.startY);
                },

                moveGhost(x, y) {
                    if (!this.ghost) return;
                    this.ghost.style.left = (x + 12) + 'px';
                    this.ghost.style.top = (y + 12) + 'px';
                },

                hideGhost() {
                    if (this.ghost) {
                        this.ghost.remove();
                        this.ghost = null;
                    }
                },

                updateDropTarget(x, y) {
                    this.ghost && (this.ghost.style.pointerEvents = 'none');
                    const el = document.elementFromPoint(x, y);
                    const drop = el?.closest?.('[data-fe-drop-folder]');
                    const id = drop ? Number(drop.getAttribute('data-fe-drop-folder')) : null;

                    // Do not allow drop onto a selected dragged folder only-item
                    if (id !== null && this.folders.includes(id) && this.files.length === 0 && this.folders.length === 1) {
                        this.dropTargetId = null;
                        return;
                    }

                    this.dropTargetId = Number.isFinite(id) ? id : null;
                },

                cleanup() {
                    this.hideGhost();
                    document.body.classList.remove('fe-is-dragging');
                    this.active = false;
                    this.dropTargetId = null;
                    this.folders = [];
                    this.files = [];
                    window.dispatchEvent(new CustomEvent('fe-item-drag-end'));
                },

                consumeClickSuppression() {
                    if (!this.suppressClick) return false;
                    this.suppressClick = false;
                    return true;
                },

                dropFilesOnFolder(event, targetFolderId, wire) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!this.abilities.upload) return;
                    if (!event.dataTransfer?.files?.length) return;
                    setFeWire(wire);
                    callFeWire('prepareUploadToFolder', targetFolderId);
                    window.dispatchEvent(new CustomEvent('fe-upload-files', {
                        detail: { files: event.dataTransfer.files },
                    }));
                },
            });
        }

        function registerQfUploadStore() {
            if (Alpine.store('feUpload')) return;
            Alpine.store('feUpload', {
                visible: false,
                progress: 0,
                status: 'idle',
                translations: {},
                label: 'Uploading…',
                hideTimer: null,
                t(key, fallback) {
                    return this.translations?.upload?.[key] || fallback;
                },
                start() {
                    if (this.hideTimer) clearTimeout(this.hideTimer);
                    this.visible = true;
                    this.progress = 0;
                    this.status = 'uploading';
                    this.label = this.t('uploading', 'Uploading…');
                    this.hideTimer = null;
                },
                progressTo(p) {
                    this.progress = p;
                },
                finish() {
                    this.status = 'done';
                    this.progress = 100;
                    this.label = this.t('complete', 'Upload complete');
                    this.scheduleHide(900);
                },
                error(label = null) {
                    this.status = 'error';
                    this.progress = 100;
                    this.label = label || this.t('failed', 'Upload failed');
                    this.scheduleHide(2200);
                },
                cancel() {
                    this.status = 'cancelled';
                    this.progress = 100;
                    this.label = this.t('cancelled', 'Upload cancelled');
                    this.scheduleHide(1800);
                },
                settled() {
                    if (this.status === 'uploading') {
                        this.finish();
                        return;
                    }
                    if (this.visible) {
                        this.scheduleHide(400);
                    }
                },
                scheduleHide(ms) {
                    if (this.hideTimer) clearTimeout(this.hideTimer);
                    this.hideTimer = setTimeout(() => this.hide(), ms);
                },
                hide() {
                    if (this.hideTimer) clearTimeout(this.hideTimer);
                    this.hideTimer = null;
                    this.visible = false;
                    this.status = 'idle';
                    this.progress = 0;
                    this.label = 'Uploading…';
                },
            });
        }

        function registerQfUiStore() {
            if (Alpine.store('feUi')) {
                return;
            }
            Alpine.store('feUi', {
                sidebarOpen: true,
                sideExpanded: {},
                isOpen(id, fallback) {
                    const v = this.sideExpanded[id];
                    return v === undefined ? !!fallback : !!v;
                },
                toggle(id, fallback) {
                    this.sideExpanded = {
                        ...this.sideExpanded,
                        [id]: !this.isOpen(id, fallback),
                    };
                },
            });
        }

        document.addEventListener('alpine:init', () => {
            registerQfSelStore();
            registerQfUiStore();
            registerQfUploadStore();
        });
        if (window.Alpine) {
            registerQfSelStore();
            registerQfUiStore();
            registerQfUploadStore();
        }

        function FileExplorerUi(config) {
            return {
                uploading: false,
                progress: 0,
                dropingFile: false,
                isDrawing: false,
                startX: 0,
                startY: 0,
                drawnArea: null,
                hoveredElements: new Set(),
                wasDrawing: false,
                isDraggingItems: false,
                rootFolderId: config.rootFolderId,
                scopeKey: config.scopeKey,
                abilities: config.abilities || {},
                mediaUrlBase: config.mediaUrlBase || '/file-explorer/media',
                refreshInterval: Number(config.refreshInterval || 0),
                translations: config.translations || {},
                ctx: { open: false, type: 'empty', id: null, name: '', x: 0, y: 0, canDelete: true, deleteHint: '' },

                init() {
                    this.startAutoRefresh();
                    registerQfUiStore();
                    registerQfSelStore();
                    registerQfUploadStore();
                    Alpine.store('feSel').setWire(this.$wire);
                    Alpine.store('feDrag').abilities = this.abilities;
                    Alpine.store('feUpload').translations = this.translations;
                    Alpine.store('feSel').replace(
                        config.selectedFolders || [],
                        config.selectedFiles || []
                    );
                    this.$watch('$wire.selectedFolders', (v) => {
                        const local = Alpine.store('feSel').folders.join(',');
                        const server = (v || []).map(Number).join(',');
                        const localFiles = Alpine.store('feSel').files.join(',');
                        const serverFiles = (this.$wire.selectedFiles || []).map(Number).join(',');
                        if (local === server && localFiles === serverFiles) {
                            return;
                        }
                        if (((v || []).length + (this.$wire.selectedFiles || []).length) === 0) {
                            Alpine.store('feSel').replace([], []);
                            return;
                        }
                        if (Alpine.store('feSel')._syncTimer) {
                            return;
                        }
                        Alpine.store('feSel').replace(v, this.$wire.selectedFiles);
                    });
                },

                onUploadStart() {
                    Alpine.store('feUpload').start();
                    this.uploading = true;
                },
                onUploadProgress(p) {
                    Alpine.store('feUpload').progressTo(p);
                    this.progress = p;
                },
                onUploadFinish() {
                    this.uploading = false;
                    Alpine.store('feUpload').finish();
                },
                onUploadError() {
                    this.uploading = false;
                    Alpine.store('feUpload').error();
                },
                onUploadCancel() {
                    this.uploading = false;
                    Alpine.store('feUpload').cancel();
                },
                onUploadSettled() {
                    this.uploading = false;
                    Alpine.store('feUpload').settled();
                },
                confirmDeleteSelected() {
                    const folders = [...Alpine.store('feSel').folders];
                    const files = [...Alpine.store('feSel').files];

                    if (!folders.length && !files.length) return;

                    if (Alpine.store('feSel')._syncTimer) clearTimeout(Alpine.store('feSel')._syncTimer);
                    Alpine.store('feSel')._syncTimer = null;
                    Alpine.store('feSel').clearMarquee();

                    // The dialog is built server-side: it knows what a recursive
                    // delete takes with it and which items are refused.
                    this.$wire.requestDelete(folders, files);
                },
                openFile(id) {
                    // The preview streams the same bytes as a download, so
                    // without that ability there is nothing to show.
                    if (!this.abilities.download) return;

                    this.$wire.preview(Number(id));
                },
                /**
                 * Keeps Tab inside an open dialog.
                 */
                trapTab(event, dialog) {
                    const focusable = [...dialog.querySelectorAll('button:not([disabled]), a[href], video, audio, iframe, [tabindex]:not([tabindex="-1"])')];

                    if (!focusable.length) return;

                    const current = focusable.indexOf(document.activeElement);
                    const last = focusable.length - 1;
                    const next = event.shiftKey
                        ? (current <= 0 ? last : current - 1)
                        : (current === last || current === -1 ? 0 : current + 1);

                    focusable[next].focus();
                },
                async openContext(detail) {
                    this.positionMenu(detail.x, detail.y);
                    this.ctx = {
                        open: true,
                        type: detail.type,
                        id: detail.id,
                        name: detail.name || '',
                        x: this.ctx.x,
                        y: this.ctx.y,
                        canDelete: true,
                        deleteHint: '',
                    };
                    if (detail.type === 'file' || detail.type === 'folder') {
                        try {
                            const state = await this.$wire.getDeleteState(detail.type, detail.id);
                            this.ctx.canDelete = !!state.allowed;
                            this.ctx.deleteHint = state.hint || state.reason || '';
                        } catch (e) {
                            this.ctx.canDelete = false;
                            this.ctx.deleteHint = this.translations?.js?.delete_not_allowed || 'Delete not allowed';
                        }
                    }
                },
                openEmptyContext(event) {
                    if (event.target.closest('.folder, .file, [data-fe-type]')) return;
                    this.positionMenu(event.clientX, event.clientY);
                    this.ctx = { open: true, type: 'empty', id: null, name: '', x: this.ctx.x, y: this.ctx.y, canDelete: false, deleteHint: '' };
                },
                async toolbarRename() {
                    const sel = Alpine.store('feSel');
                    await this.$wire.setSelection([...sel.folders], [...sel.files]);
                    if (sel.folders.length === 1 && sel.files.length === 0) {
                        await this.$wire.startRename('folder', sel.folders[0]);
                    } else if (sel.files.length === 1 && sel.folders.length === 0) {
                        await this.$wire.startRename('file', sel.files[0]);
                    }
                },
                async toolbarCopy() {
                    const sel = Alpine.store('feSel');
                    await this.$wire.setSelection([...sel.folders], [...sel.files]);
                    await this.$wire.copySelection();
                },
                async toolbarCut() {
                    const sel = Alpine.store('feSel');
                    await this.$wire.setSelection([...sel.folders], [...sel.files]);
                    await this.$wire.cutSelection();
                },

                /**
                 * Bound on the items container rather than the window: shortcuts
                 * must not fire for a second explorer on the page, nor while the
                 * user is typing in the search or rename field.
                 */
                onKeydown(event) {
                    if (event.target?.closest?.('input, textarea, select, [contenteditable="true"]')) return;

                    const sel = Alpine.store('feSel');
                    const mod = event.ctrlKey || event.metaKey;
                    const key = event.key || '';

                    if (mod && (key === 'a' || key === 'A')) {
                        event.preventDefault();
                        sel.selectAll(this.$el);
                        return;
                    }

                    if (mod && (key === 'c' || key === 'C')) {
                        if (!this.abilities.copy || !sel.count()) return;
                        event.preventDefault();
                        this.toolbarCopy();
                        return;
                    }

                    if (mod && (key === 'x' || key === 'X')) {
                        if (!this.abilities.move || !sel.count()) return;
                        event.preventDefault();
                        this.toolbarCut();
                        return;
                    }

                    if (mod && (key === 'v' || key === 'V')) {
                        if (!this.abilities.move && !this.abilities.copy) return;
                        event.preventDefault();
                        this.$wire.pasteClipboard();
                        return;
                    }

                    if (key === 'F2') {
                        if (!this.abilities.rename || sel.count() !== 1) return;
                        event.preventDefault();
                        this.toolbarRename();
                        return;
                    }

                    if (key === 'Delete' || key === 'Backspace') {
                        if (!this.abilities.delete && !this.abilities.deleteFolder) return;
                        if (!sel.count()) return;
                        event.preventDefault();
                        this.confirmDeleteSelected();
                        return;
                    }

                    if (key === 'Enter') {
                        if (sel.count() !== 1) return;
                        event.preventDefault();
                        this.openSelection();
                        return;
                    }

                    if (key.startsWith('Arrow')) {
                        event.preventDefault();
                        this.moveSelection(key, event.shiftKey);
                    }
                },
                openSelection() {
                    const sel = Alpine.store('feSel');

                    if (sel.folders.length === 1) {
                        this.$wire.navigateToFolder(sel.folders[0]);
                        return;
                    }

                    if (sel.files.length === 1) {
                        this.openFile(sel.files[0]);
                    }
                },
                moveSelection(key, extend) {
                    const sel = Alpine.store('feSel');
                    const items = sel.orderedItems(this.$el);

                    if (!items.length) return;

                    const current = sel.anchor
                        ? items.findIndex((item) => item.type === sel.anchor.type && item.id === sel.anchor.id)
                        : -1;

                    let target;

                    if (current === -1) {
                        target = (key === 'ArrowUp' || key === 'ArrowLeft') ? items.length - 1 : 0;
                    } else if (key === 'ArrowRight') {
                        target = current + 1;
                    } else if (key === 'ArrowLeft') {
                        target = current - 1;
                    } else {
                        target = this.indexInAdjacentRow(items, current, key === 'ArrowDown' ? 1 : -1);
                    }

                    if (target === null || target < 0 || target >= items.length) return;

                    const item = items[target];

                    if (extend) {
                        sel.selectRange(item.type, item.id, this.$el);
                    } else {
                        sel.toggle(item.type, item.id, false);
                    }

                    item.el.focus?.({ preventScroll: true });
                    item.el.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
                },
                /**
                 * Rows are read back from the layout, so vertical movement works
                 * in the grid without knowing how many columns fit.
                 */
                indexInAdjacentRow(items, current, direction) {
                    const from = items[current].el;
                    const candidates = items.filter((item) => (direction > 0
                        ? item.el.offsetTop > from.offsetTop
                        : item.el.offsetTop < from.offsetTop));

                    if (!candidates.length) return null;

                    const tops = candidates.map((item) => item.el.offsetTop);
                    const rowTop = direction > 0 ? Math.min(...tops) : Math.max(...tops);
                    const row = candidates.filter((item) => item.el.offsetTop === rowTop);

                    let best = row[0];

                    row.forEach((item) => {
                        if (Math.abs(item.el.offsetLeft - from.offsetLeft) < Math.abs(best.el.offsetLeft - from.offsetLeft)) {
                            best = item;
                        }
                    });

                    return items.indexOf(best);
                },
                async toolbarInfo() {
                    const sel = Alpine.store('feSel');
                    await this.$wire.setSelection([...sel.folders], [...sel.files]);
                    if (sel.folders.length === 1 && sel.files.length === 0) {
                        await this.$wire.showInfo('folder', sel.folders[0]);
                    } else if (sel.files.length === 1 && sel.folders.length === 0) {
                        await this.$wire.showInfo('file', sel.files[0]);
                    } else {
                        await this.$wire.showInfo();
                    }
                },
                toolbarDownload() {
                    const sel = Alpine.store('feSel');
                    const total = sel.folders.length + sel.files.length;

                    if (!total) return;

                    if (total === 1) {
                        window.location.href = sel.folders.length
                            ? this.folderZipUrl(sel.folders[0])
                            : this.fileUrl(sel.files[0], true);

                        return;
                    }

                    // Anything past the first item used to be dropped silently.
                    window.location.href = this.selectionZipUrl(sel.folders, sel.files);
                },

                fileUrl(id, download) {
                    const base = `${this.mediaUrlBase}/${this.scopeKey}/files/${id}`;
                    return download ? `${base}?download=1` : base;
                },
                mediaZipUrl(id) {
                    return `${this.mediaUrlBase}/${this.scopeKey}/files/${id}/zip`;
                },
                selectionZipUrl(folders, files) {
                    const params = new URLSearchParams();

                    if (folders.length) params.set('folders', folders.join(','));
                    if (files.length) params.set('files', files.join(','));

                    return `${this.mediaUrlBase}/${this.scopeKey}/selection/zip?${params.toString()}`;
                },
                folderZipUrl(id) {
                    return `${this.mediaUrlBase}/${this.scopeKey}/folders/${id}/zip`;
                },
                positionMenu(x, y) {
                    const pad = 8;
                    const w = 240;
                    const h = 360;
                    this.ctx.x = Math.min(x, window.innerWidth - w - pad);
                    this.ctx.y = Math.min(y, window.innerHeight - h - pad);
                    if (this.ctx.x < pad) this.ctx.x = pad;
                    if (this.ctx.y < pad) this.ctx.y = pad;
                },
                closeContext() {
                    this.ctx.open = false;
                },
                run(fn) {
                    this.closeContext();
                    fn();
                },

                localPoint(clientX, clientY) {
                    const container = document.getElementById('folder-container');
                    const rect = container.getBoundingClientRect();
                    return {
                        x: clientX - rect.left + container.scrollLeft,
                        y: clientY - rect.top + container.scrollTop,
                    };
                },
                bindMarqueeListeners() {
                    this._onMarqueeMove = (event) => this.draw(event);
                    this._onMarqueeUp = (event) => this.stopDrawing(event);
                    window.addEventListener('mousemove', this._onMarqueeMove, true);
                    window.addEventListener('mouseup', this._onMarqueeUp, true);
                },
                /**
                 * Driven here rather than with wire:poll so it can stand down
                 * while the user is doing something a re-render would ruin: a
                 * morphed DOM cancels a drag in progress, and a hidden tab has
                 * nobody watching it.
                 */
                startAutoRefresh() {
                    if (!this.refreshInterval) return;

                    this._refreshTimer = setInterval(() => {
                        if (document.hidden) return;
                        if (this.isDraggingItems || Alpine.store('feDrag')?.active || Alpine.store('feDrag')?.pointerDown) return;
                        if (this.isDrawing || this.ctx.open) return;
                        if (this.uploading) return;

                        this.$wire.refreshExplorer();
                    }, this.refreshInterval * 1000);
                },
                destroy() {
                    if (this._refreshTimer) clearInterval(this._refreshTimer);
                },
                unbindMarqueeListeners() {
                    if (this._onMarqueeMove) window.removeEventListener('mousemove', this._onMarqueeMove, true);
                    if (this._onMarqueeUp) window.removeEventListener('mouseup', this._onMarqueeUp, true);
                    this._onMarqueeMove = null;
                    this._onMarqueeUp = null;
                },
                initiateDrawing(event) {
                    if (this.isDraggingItems || Alpine.store('feDrag')?.active) return;
                    if (event.button !== 0) return;
                    if (event.target.closest('.folder, .file, input, button, a, .fe-caption, .fe-rename-input, .fe-side-node')) return;

                    // Threshold: wait for move before clearing selection / showing box
                    const startPt = this.localPoint(event.clientX, event.clientY);
                    this.startX = startPt.x;
                    this.startY = startPt.y;
                    this._marqueePending = true;
                    this.isDrawing = false;
                    this.drawnArea = null;

                    const onMove = (e) => {
                        if (!this._marqueePending && !this.isDrawing) return;
                        const pt = this.localPoint(e.clientX, e.clientY);
                        const dx = Math.abs(pt.x - this.startX);
                        const dy = Math.abs(pt.y - this.startY);
                        if (!this.isDrawing && (dx > 4 || dy > 4)) {
                            this._marqueePending = false;
                            this.isDrawing = true;
                            Alpine.store('feSel').clear({ sync: false });
                            this.drawnArea = { left: this.startX, top: this.startY, width: 0, height: 0 };
                        }
                        if (this.isDrawing) {
                            e.preventDefault();
                            this.draw(e);
                        }
                    };
                    const onUp = () => {
                        window.removeEventListener('mousemove', onMove, true);
                        window.removeEventListener('mouseup', onUp, true);
                        this._marqueePending = false;
                        this.stopDrawing();
                    };
                    window.addEventListener('mousemove', onMove, true);
                    window.addEventListener('mouseup', onUp, true);
                    this._onMarqueeMove = onMove;
                    this._onMarqueeUp = onUp;
                },
                draw(event) {
                    if (!this.isDrawing) return;
                    const pt = this.localPoint(event.clientX, event.clientY);
                    const width = pt.x - this.startX;
                    const height = pt.y - this.startY;
                    this.drawnArea = {
                        width: Math.abs(width),
                        height: Math.abs(height),
                        left: width < 0 ? pt.x : this.startX,
                        top: height < 0 ? pt.y : this.startY,
                    };
                    this.updateHoveredElements();
                },
                stopDrawing() {
                    this.unbindMarqueeListeners();
                    if (!this.isDrawing) {
                        this.drawnArea = null;
                        return;
                    }
                    const meaningful = this.drawnArea && (this.drawnArea.width > 3 || this.drawnArea.height > 3);
                    if (meaningful) {
                        this.wasDrawing = true;
                        this.selectElementsWithinDrawnArea();
                        const sel = Alpine.store('feSel');
                        if (!sel.folders.length && !sel.files.length) {
                            sel.clear({ sync: true });
                        }
                    }
                    Alpine.store('feSel').clearMarquee();
                    this.isDrawing = false;
                    this.drawnArea = null;
                },
                updateHoveredElements() {
                    const container = document.getElementById('folder-container');
                    if (!container || !this.drawnArea) return;
                    const drawnRect = {
                        left: this.drawnArea.left,
                        top: this.drawnArea.top,
                        right: this.drawnArea.left + this.drawnArea.width,
                        bottom: this.drawnArea.top + this.drawnArea.height,
                    };
                    const folders = [];
                    const files = [];
                    const crect = container.getBoundingClientRect();
                    container.querySelectorAll('.folder, .file').forEach((element) => {
                        const rect = element.getBoundingClientRect();
                        const elementRect = {
                            left: rect.left - crect.left + container.scrollLeft,
                            top: rect.top - crect.top + container.scrollTop,
                            right: rect.right - crect.left + container.scrollLeft,
                            bottom: rect.bottom - crect.top + container.scrollTop,
                        };
                        if (this.isElementWithinDrawnArea(drawnRect, elementRect)) {
                            const id = parseInt(element.getAttribute('data-id'), 10);
                            if (element.classList.contains('folder')) folders.push(id);
                            else files.push(id);
                        }
                    });
                    Alpine.store('feSel').setMarquee(folders, files);
                },
                selectElementsWithinDrawnArea() {
                    const folders = [...Alpine.store('feSel').marqueeFolders];
                    const files = [...Alpine.store('feSel').marqueeFiles];
                    if (folders.length > 0 || files.length > 0) {
                        Alpine.store('feSel').replace(folders, files);
                        if (Alpine.store('feSel')._syncTimer) clearTimeout(Alpine.store('feSel')._syncTimer);
                        Alpine.store('feSel')._syncTimer = null;
                        this.$wire.setSelection(folders, files);
                    }
                },
                isElementWithinDrawnArea(drawnRect, elementRect) {
                    const margin = 2;
                    return !(drawnRect.left > elementRect.right + margin ||
                             drawnRect.right < elementRect.left - margin ||
                             drawnRect.top > elementRect.bottom + margin ||
                             drawnRect.bottom < elementRect.top - margin);
                },

                handleContainerClick(event) {
                    if (!this.wasDrawing && event.target === event.currentTarget) {
                        Alpine.store('feSel').clear();
                    }
                    this.wasDrawing = false;
                },
                handleFileDrop(e) {
                    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        this.uploadDroppedFiles(e.dataTransfer.files);
                    }
                },
                onItemDragStart() {
                    this.isDraggingItems = true;
                    this._marqueePending = false;
                    this.isDrawing = false;
                    this.unbindMarqueeListeners();
                    this.drawnArea = null;
                    Alpine.store('feSel').clearMarquee();
                },
                uploadDroppedFiles(files) {
                    if (!this.abilities.upload) return;
                    if (!files || !files.length) return;
                    const filtered = [...files].filter(file => this.isAllowedFile(file));
                    if (!filtered.length) {
                        Alpine.store('feUpload').error(this.translations?.validation?.invalid_format || 'Invalid file format');
                        return;
                    }
                    this.onUploadStart();
                    this.$wire.uploadMultiple(
                        'files',
                        filtered,
                        () => { this.onUploadFinish(); },
                        () => { this.onUploadError(); },
                        (event) => { this.onUploadProgress(event.detail.progress); }
                    );
                },
                pickAndUploadFiles(event) {
                    const input = event.target;
                    const files = input?.files;
                    if (!files || !files.length) return;
                    this.uploadDroppedFiles(files);
                    input.value = '';
                },
                /**
                 * A folder upload keeps its structure: each file carries the path
                 * the browser saw, and the server recreates the folders from it.
                 */
                async pickAndUploadFolder(event) {
                    const input = event.target;
                    const picked = [...(input?.files || [])];
                    input.value = '';

                    if (!picked.length) return;
                    if (!this.abilities.upload || !this.abilities.mkdir) return;

                    const files = picked.filter((file) => this.isAllowedFile(file));

                    if (!files.length) {
                        Alpine.store('feUpload').error(this.translations?.validation?.invalid_format || 'Invalid file format');

                        return;
                    }

                    // Committed before the upload starts, so the paths are on the
                    // component when the files land — and in the same order.
                    await this.$wire.set('uploadRelativePaths', files.map((file) => file.webkitRelativePath || file.name));

                    this.onUploadStart();
                    this.$wire.uploadMultiple(
                        'files',
                        files,
                        () => { this.onUploadFinish(); },
                        () => { this.onUploadError(); },
                        (event) => { this.onUploadProgress(event.detail.progress); }
                    );
                },
                isAllowedFile(file) {
                    const accept = (document.getElementById('fileInput')?.accept || '')
                        .split(',')
                        .map(s => s.trim().toLowerCase())
                        .filter(Boolean);
                    if (!accept.length) return true;
                    const name = (file.name || '').toLowerCase();
                    const type = (file.type || '').toLowerCase();
                    const extOk = accept.some(a => a.startsWith('.') && name.endsWith(a));
                    if (extOk) return true;
                    return accept.some(a => {
                        if (a.startsWith('.')) return false;
                        if (a.endsWith('/*')) return type.startsWith(a.replace('/*', '/'));
                        return type !== '' && type === a;
                    });
                },
            };
        }

        document.addEventListener('livewire:initialized', () => {
            Livewire.on('new-folder-created', function () {
                const checkExist = setInterval(function() {
                    let input = document.getElementById('new-folder-name');
                    if (input) {
                        input.focus();
                        input.select();
                        clearInterval(checkExist);
                    }
                }, 100);
            });

            Livewire.on('focus-rename-input', function () {
                const checkExist = setInterval(function() {
                    let input = document.getElementById('rename-input');
                    if (input) {
                        input.focus();
                        input.select();
                        clearInterval(checkExist);
                    }
                }, 50);
            });
        });

        window.qfCtxFlyout = qfCtxFlyout;
        window.FileExplorerUi = FileExplorerUi;