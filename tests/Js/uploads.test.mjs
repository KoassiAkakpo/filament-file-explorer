import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadExplorer, wireCalls } from './harness.mjs';

/**
 * The transport layer, which is where the five upload ceilings meet.
 *
 * These run the real resources/js/file-explorer.js in a vm, so what is asserted
 * is the sequence of requests a browser would actually make: which transport was
 * chosen, how many slices went out, and what was handed back to Livewire. No PHP
 * test can see any of that.
 */

/** Enough of a File: a name, a size, and slices that know their own length. */
function file(name, size, type = 'application/pdf') {
    return {
        name,
        size,
        type,
        slice(start, end) {
            return { size: Math.max(0, Math.min(end, size) - start), _range: [start, end] };
        },
    };
}

function explorer(uploadLimits = {}, options = {}) {
    return loadExplorer({ uploadLimits, ...options });
}

/** Every slice request, as "token/index". */
function slices(requests) {
    return requests
        .filter((request) => request.url.includes('/chunk/'))
        .map((request) => request.url.split('/chunk/')[1]);
}

test('sends the whole batch in one request when it fits', async () => {
    const { component, calls } = explorer({ max: 1_000_000, per_request: 1_000_000, chunked: false });

    await component.sendUploads([file('a.pdf', 10), file('b.pdf', 10)]);

    // The transport that always worked, unchanged: nothing about this feature
    // may alter an upload that was already fine.
    assert.deepEqual(wireCalls(calls), ['uploadMultiple("files", 2)']);
});

test('sends one file per request when the temporary disk is remote', async () => {
    const { component, calls } = explorer({ max: 1_000_000, per_request: null, single: true });

    await component.sendUploads([file('a.pdf', 10), file('b.pdf', 10), file('c.pdf', 10)]);

    // Livewire refuses uploadMultiple outright on a remote temporary disk — it
    // presigns one URL per upload — so calling it there did nothing at all.
    assert.deepEqual(wireCalls(calls), [
        'upload("files", "a.pdf")',
        'upload("files", "b.pdf")',
        'upload("files", "c.pdf")',
    ]);
});

test('slices when the batch total will not fit in one request, not just a single file', async () => {
    const { component, calls, requests } = explorer({
        max: 1_000_000, per_request: 100, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    await component.sendUploads([file('a.pdf', 60), file('b.pdf', 60)]);

    // post_max_size caps the request *body*, so two files of sixty bytes break a
    // hundred-byte limit even though neither one is close to it. That batch
    // failed before this existed, and it looked like a problem with the files.
    assert.equal(requests.filter((r) => r.url.includes('/begin')).length, 2);
    assert.ok(!wireCalls(calls).some((call) => call.startsWith('uploadMultiple')));
});

test('does not slice a batch that fits, even with slicing available', async () => {
    const { component, calls, requests } = explorer({
        max: 1_000_000, per_request: 1000, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    await component.sendUploads([file('a.pdf', 10)]);

    assert.deepEqual(requests, []);
    assert.deepEqual(wireCalls(calls), ['uploadMultiple("files", 1)']);
});

test('does not slice when the transport is switched off, however large the batch', async () => {
    const { component, calls, requests } = explorer({ max: 1_000_000, per_request: 10, chunked: false });

    await component.sendUploads([file('a.pdf', 5000)]);

    // Nothing to fall back to, so it takes the path it always took and fails
    // where it always failed. Better than inventing a transport the server has
    // not got.
    assert.deepEqual(requests, []);
    assert.deepEqual(wireCalls(calls), ['uploadMultiple("files", 1)']);
});

test('refuses a file over the ceiling before sending a byte of it', async () => {
    const { component, calls, requests, Alpine } = explorer({
        max: 100, per_request: 100, chunked: true, limit_label: '100 B',
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    await component.sendUploads([file('big.pdf', 500)]);

    assert.deepEqual(requests, []);
    assert.deepEqual(wireCalls(calls), []);
    assert.equal(Alpine.store('feUpload').status, 'error');
    // Named, not shrugged at. Whichever of the five ceilings used to speak first
    // answered with a 422 the page turned into "upload failed" — or the web
    // server dropped the body and nothing answered at all.
    assert.match(Alpine.store('feUpload').label, /100 B/);
});

test('refuses the batch when one file of it is over the ceiling', async () => {
    const { component, calls } = explorer({ max: 100, limit_label: '100 B' });

    await component.sendUploads([file('small.pdf', 10), file('big.pdf', 500)]);

    // All or nothing: uploading half of what was dropped, silently, is worse
    // than uploading none of it and saying why.
    assert.deepEqual(wireCalls(calls), []);
});

test('cuts each file into as many slices as it takes, in order', async () => {
    const { component, requests, answerWith } = explorer({
        max: 1_000_000, per_request: 10, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    answerWith((url) => {
        if (url.includes('/begin')) return { token: 'A'.repeat(40), chunk_bytes: 8, chunks: 3 };

        const index = Number(url.split('/').pop());

        return { received: index + 1, chunks: 3, complete: index === 2, path: 'signed:one' };
    });

    await component.sendUploads([file('a.pdf', 20)]);

    // 20 bytes in 8-byte slices is three, and they go out 0, 1, 2 — in order,
    // because the server appends to one growing file and an out-of-order slice
    // would leave a hole no length check could find.
    assert.deepEqual(slices(requests), [
        'A'.repeat(40) + '/0',
        'A'.repeat(40) + '/1',
        'A'.repeat(40) + '/2',
    ]);
});

test('hands the assembled files to Livewire in one call', async () => {
    const { component, calls, answerWith } = explorer({
        max: 1_000_000, per_request: 10, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    let file_ = 0;
    answerWith((url) => {
        if (url.includes('/begin')) return { token: String(++file_).repeat(40), chunk_bytes: 100, chunks: 1 };

        return { received: 1, chunks: 1, complete: true, path: `signed:file-${file_}` };
    });

    await component.sendUploads([file('a.pdf', 20), file('b.pdf', 20)]);

    // _finishUpload, once, with both references — the same entry point Livewire's
    // own upload endpoint hands to, which is what keeps updatedFiles() unchanged.
    assert.deepEqual(wireCalls(calls), [
        'uploadFolderId()',
        'uploadFolderId()',
        'call("_finishUpload", "files", ["signed:file-1","signed:file-2"], true)',
    ]);
});

test('asks the server where the upload would land', async () => {
    const { component, calls } = explorer({
        max: 1_000_000, per_request: 10, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    await component.sendUploads([file('a.pdf', 20)]);

    // Not the root: a drop onto a folder sets the target on the component, and
    // proving that the root sits under the root is always true and so proves
    // nothing.
    assert.ok(wireCalls(calls).some((call) => call.startsWith('uploadFolderId')));
});

test('commits a folder upload’s paths before the sliced files land', async () => {
    const { component, calls } = explorer({
        max: 1_000_000, per_request: 10, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    await component.sendUploads(
        [file('a.pdf', 20), file('b.pdf', 20)],
        ['deep/a.pdf', 'deep/b.pdf'],
    );

    const names = wireCalls(calls);

    // The server matches a relative path to a file by position, so the paths
    // have to be on the component before the files are.
    assert.ok(
        names.findIndex((call) => call.startsWith('set("uploadRelativePaths"'))
        < names.findIndex((call) => call.startsWith('call("_finishUpload"'))
    );
});

test('commits one path per file when sending them one at a time', async () => {
    const { component, calls } = explorer({ max: 1_000_000, single: true });

    await component.sendUploads(
        [file('a.pdf', 10), file('b.pdf', 10)],
        ['deep/a.pdf', 'deep/b.pdf'],
    );

    // One file per request means one path per request: the whole list would put
    // the second file's path against the first file.
    assert.deepEqual(wireCalls(calls), [
        'set("uploadRelativePaths", ["deep/a.pdf"])',
        'upload("files", "a.pdf")',
        'set("uploadRelativePaths", ["deep/b.pdf"])',
        'upload("files", "b.pdf")',
    ]);
});

test('hands Livewire nothing when a slice fails', async () => {
    const { component, calls, answerWith, Alpine } = explorer({
        max: 1_000_000, per_request: 10, chunked: true,
        begin_url: '/upload/library/begin', chunk_url: '/upload/chunk',
    });

    answerWith((url) => {
        if (url.includes('/begin')) return { token: 'A'.repeat(40), chunk_bytes: 8, chunks: 3 };

        return { status: 410 };
    });

    await component.sendUploads([file('a.pdf', 20)]);

    // A half-assembled file is not a file. Better to report the failure than to
    // hand Livewire a reference to a partial.
    assert.ok(!wireCalls(calls).some((call) => call.startsWith('call(')));
    assert.equal(Alpine.store('feUpload').status, 'error');
});

test('uploads nothing without the ability', async () => {
    const { component, calls, requests } = explorer(
        { max: 1_000_000, per_request: 10, chunked: true, begin_url: '/b', chunk_url: '/c' },
        { abilities: { upload: false, mkdir: true } },
    );

    await component.sendUploads([file('a.pdf', 20)]);

    assert.deepEqual(requests, []);
    assert.deepEqual(wireCalls(calls), []);
});

test('a folder upload still needs mkdir', async () => {
    const { component, calls } = explorer(
        { max: 1_000_000 },
        { abilities: { upload: true, mkdir: false } },
    );

    component.pickAndUploadFolder({ target: { files: [file('a.pdf', 10)], value: 'x' } });

    assert.deepEqual(wireCalls(calls), []);
});
