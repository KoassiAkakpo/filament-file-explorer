<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feIlRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feIlComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feIlRootId(),
    ]);
}

it('gives the surplus height to the browsing area, not to the breadcrumb', function (): void {
    // The inspector is a sibling of the whole main column, so opening it can
    // make the flex row taller than its 560px floor. With nothing growing in
    // the column that surplus landed under the breadcrumb, which then read as a
    // breadcrumb bar three times its height.
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');

    $browser = (string) preg_replace('/\s+/', ' ', (string) strstr($css, '.fe-browser {'));

    expect($browser)->toStartWith('.fe-browser { display: flex; flex: 1 1 auto; flex-direction: column;');
});

it('keeps the breadcrumb at its own height whatever is beside it', function (): void {
    $media = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => feIlRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => 'report.pdf',
        'file_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    $html = feIlComponent()->call('showInfo', 'file', $media->id)->assertOk()->html();

    // shrink-0 on the nav and flex-1 on the items container: the breadcrumb
    // never gives up its height, and the scroll area is the browser's own. The
    // 0 floor that lets it shrink into that space lives in the stylesheet, not
    // in a utility — see the scroll-chain test below.
    expect($html)->toContain('<nav class="flex shrink-0 select-none items-center')
        ->toContain('relative flex-1 select-none overflow-y-auto');
});

it('scrolls its items inside itself rather than growing the page', function (): void {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');

    // The items container has always carried overflow-y, and it was inert:
    // nothing bounded the height, so a thousand files meant a thousand files of
    // page. The max-height is what makes that overflow mean something.
    expect($css)->toContain('--fe-max-h:')
        ->toContain('.fe-body {
  max-height: var(--fe-max-h);
}')
        // And .fe-browser has to be able to shrink into it. A pixel floor here
        // is a floor the scrolling child cannot get under.
        ->toContain('  /* 0, not 500px: a floor here is a floor the scrolling child cannot get under,
     and the row\'s own min-height already gives an empty explorer its presence. */
  min-height: 0;');
});

it('lets the bound reach the scrolling child through every flex item', function (): void {
    $media = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => feIlRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => 'report.pdf',
        'file_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    $html = feIlComponent()->call('showInfo', 'file', $media->id)->assertOk()->html();

    // Every link is addressable from the stylesheet, which is where the floor
    // and the refusal to shrink live: a Tailwind utility only exists once the
    // host app rebuilds its theme, so a chain built from utilities would work
    // here and break there.
    expect($html)
        ->toContain('class="fe-body flex min-h-[560px]"')
        ->toContain('class="fe-sidebar')
        ->toContain('<div class="fe-main flex min-w-0 flex-1 flex-col">')
        ->toContain('class="fe-inspector')
        ->toContain('relative flex-1 select-none overflow-y-auto');

    $css = (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');

    // A flex item's min-height is auto — "at least my content" — so one link
    // without a 0 floor grows past the bound, and flex takes the difference out
    // of whatever can shrink instead. That was the toolbar: it has a height but
    // no refusal to shrink, so a folder with enough items to scroll squashed it
    // and one without did not. Both halves of the rule, or neither works.
    expect($css)->toContain('.fe-main,
.fe-sidebar,
.fe-inspector,
.fe-trash-list,
[data-fe-items] {
  min-height: 0;
}')
        ->toContain('.fe-head,
.fe-toolbar,
.fe-inspector-head,
.fe-list-head {
  flex-shrink: 0;
}');
});

it('scrolls the trash list on its own terms', function (): void {
    // The trash replaces the items container, so it needs its own scroll or a
    // full trash overflows the bound the folder view respects.
    expect(feIlComponent()->call('toggleTrash')->assertOk()->html())
        ->toContain('<div class="fe-trash-list flex-1 overflow-y-auto p-2">');
});
