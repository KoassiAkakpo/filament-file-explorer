---
title: Preview and deletion
description: The lightbox, and the confirmation dialog that says what a delete actually takes with it.
---

# Preview and deletion

## The lightbox

Double-clicking a file — or pressing Enter, or picking **Open** in the context menu — opens it in a lightbox. Images, video, audio, PDFs and text render inline; anything else offers a download. Left and right arrows walk through the files of the current listing.

Previewing requires the **`download`** ability rather than one of its own, because it streams the same bytes through the same media route. Gating it separately would have made "can preview but not download" a distinction the transport cannot actually enforce.

> **PDFs and text files render in a same-origin `<iframe>`.** An application that sends `X-Frame-Options: DENY` needs `SAMEORIGIN` for that part of the preview to show — or, on a CSP, `frame-ancestors 'self'`. Images, video and audio are unaffected.

## Deleting

Deleting asks first, in a dialog that:

- **names what is about to go**, item by item;
- **counts what a folder takes with it** — a recursive delete of a folder holding forty files says so before you confirm;
- **lists the items the authorizer refuses to delete.** Those are kept and the rest still goes, rather than the whole action failing on one refusal.

The dialog is informative, never authoritative. Confirming re-enters the same delete path, which stays the only place the rules are enforced — so a crafted confirmation cannot delete something the dialog was told to exclude.

With the [trash](../files/trash.md) on, all of this moves things aside instead of destroying them.

## Both dialogs live in the component

The confirmation and the lightbox are rendered **inside** the Livewire component rather than in the panel's modal stack, because the component is also embedded by [the picker](../integrating/picker.md), where no page-level modal exists.

Their state is on the server rather than in Alpine, which is what lets the confirmation use real `trans_choice` and report what a recursive delete actually takes with it. A `window.confirm` could say none of that — and none of it would be testable.

Escape is handled once, on the component root, and Tab is trapped inside whichever dialog is open.
