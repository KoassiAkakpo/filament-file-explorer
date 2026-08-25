<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Models\Tag;

/**
 * Descriptions and tags, for folders and files alike.
 *
 * Both are annotations on an *item*, and an item is a folder or a file — the two
 * words the explorer already discriminates with everywhere else. They live in
 * tables of their own rather than in Media Library's `custom_properties` for one
 * reason that decides it: the search has to run in SQL, and querying inside a
 * JSON column is written differently on sqlite, MySQL and Postgres. Everything
 * else in the listing is filtered and sorted in the database precisely so a
 * folder holding thousands of files stays usable, and an annotation the search
 * could not reach would be a note nobody finds again.
 *
 * Folders also have no `custom_properties` to put anything in, so that route
 * would have meant two mechanisms for one feature.
 *
 * Bound `scoped`: it memoises the tags of the rows on screen, which the listing
 * asks for once per rendered item, and a long-lived worker must not answer the
 * next request from it.
 */
final class Annotations
{
    /** The two kinds of item an annotation can hang on. */
    public const FOLDER = 'folder';

    public const FILE = 'file';

    /**
     * The palette a tag colour may name.
     *
     * A closed list of names rather than free hex, because the swatch is drawn
     * by the package's own stylesheet: an unknown value falls back to no colour
     * instead of reaching the page. Finder's seven, in its order.
     *
     * @var list<string>
     */
    public const COLORS = ['grey', 'red', 'orange', 'yellow', 'green', 'blue', 'purple'];

    /**
     * Upper bound on a description, enforced here as well as in the component:
     * this is the only writer, and a column is not a text editor.
     */
    public const MAX_DESCRIPTION = 2000;

    public const MAX_TAG_NAME = 40;

    /**
     * Tags of the items already asked about this request, keyed "type:id".
     *
     * @var array<string, list<array{id: int, name: string, color: string|null}>>
     */
    private array $tagCache = [];

    public static function enabled(): bool
    {
        return (bool) config('filament-file-explorer.annotations.enabled', true);
    }

    /**
     * Whether a word names one of the two item kinds. Item types arrive from
     * Livewire calls, so they are user input like every folder id.
     */
    public static function isItemType(?string $type): bool
    {
        return $type === self::FOLDER || $type === self::FILE;
    }

    public static function descriptionsTable(): string
    {
        return (string) config('filament-file-explorer.annotations.descriptions_table', 'file_explorer_descriptions');
    }

    public static function tagItemsTable(): string
    {
        return (string) config('filament-file-explorer.annotations.tag_items_table', 'file_explorer_tag_items');
    }

    /**
     * The palette name a colour resolves to, or null. Anything unrecognised
     * becomes null rather than an error: a colour is decoration, and a stale
     * value must not stop a tag being drawn.
     */
    public static function colour(?string $color): ?string
    {
        return $color !== null && in_array($color, self::COLORS, true) ? $color : null;
    }

    /* ---------------------------------------------------------------- reads */

    public function description(string $itemType, int $itemId): string
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return '';
        }

        $row = DB::table(self::descriptionsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->value('description');

        return (string) ($row ?? '');
    }

    /**
     * @return list<array{id: int, name: string, color: string|null}>
     */
    public function tags(string $itemType, int $itemId): array
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return [];
        }

        return $this->tagCache[$itemType.':'.$itemId] ??= $this->tagsFor($itemType, [$itemId])[$itemId] ?? [];
    }

    /**
     * The tags of many items in one query.
     *
     * The listing draws up to a full window of rows and every one of them can
     * carry tags; asking per row is how a folder of a hundred files becomes a
     * hundred queries.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<array{id: int, name: string, color: string|null}>>
     */
    public function tagsFor(string $itemType, array $itemIds): array
    {
        if (! self::enabled() || ! self::isItemType($itemType) || $itemIds === []) {
            return [];
        }

        $tags = (string) (new Tag)->getTable();

        $rows = DB::table(self::tagItemsTable().' as ti')
            ->join($tags.' as t', 't.id', '=', 'ti.tag_id')
            ->where('ti.item_type', $itemType)
            ->whereIn('ti.item_id', $itemIds)
            ->orderBy('t.name')
            ->orderBy('t.id')
            ->get(['ti.item_id', 't.id', 't.name', 't.color']);

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->item_id][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'color' => self::colour($row->color === null ? null : (string) $row->color),
            ];
        }

        foreach ($byItem as $itemId => $list) {
            $this->tagCache[$itemType.':'.$itemId] = $list;
        }

        return $byItem;
    }

    /**
     * Every tag the scope knows, with how many items carry it.
     *
     * @return list<array{id: int, name: string, color: string|null, count: int}>
     */
    public function available(string $scopeKey): array
    {
        if (! self::enabled()) {
            return [];
        }

        $tags = (string) (new Tag)->getTable();

        return DB::table($tags.' as t')
            ->leftJoin(self::tagItemsTable().' as ti', 'ti.tag_id', '=', 't.id')
            ->where('t.scope_key', $scopeKey)
            ->groupBy('t.id', 't.name', 't.color')
            ->orderBy('t.name')
            ->get(['t.id', 't.name', 't.color', DB::raw('count(ti.id) as items')])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'color' => self::colour($row->color === null ? null : (string) $row->color),
                'count' => (int) $row->items,
            ])
            ->all();
    }

    /**
     * A tag of this scope by id, or null. Tag ids arrive from the filter menu
     * and from Livewire calls, so one from another scope must not resolve.
     *
     * @return array{id: int, name: string, color: string|null}|null
     */
    public function tag(string $scopeKey, ?int $tagId): ?array
    {
        if (! self::enabled() || $tagId === null) {
            return null;
        }

        $tag = Tag::query()->where('scope_key', $scopeKey)->find($tagId);

        return $tag === null ? null : [
            'id' => (int) $tag->id,
            'name' => (string) $tag->name,
            'color' => self::colour($tag->color),
        ];
    }

    /* --------------------------------------------------------------- writes */

    public function setDescription(string $itemType, int $itemId, string $description): string
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return '';
        }

        $description = Str::limit(trim($description), self::MAX_DESCRIPTION, '');

        $table = self::descriptionsTable();

        // An empty description is no description: the row goes rather than
        // sitting there as an empty string the search would still visit.
        if ($description === '') {
            DB::table($table)->where('item_type', $itemType)->where('item_id', $itemId)->delete();

            return '';
        }

        DB::table($table)->upsert([
            [
                'item_type' => $itemType,
                'item_id' => $itemId,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['item_type', 'item_id'], ['description', 'updated_at']);

        return $description;
    }

    /**
     * Attaches a tag by name, creating the scope's word if it is new.
     *
     * A colour given here is an opinion about the *word*, not about the item, so
     * it lands on an existing tag as well as a new one — a colour has to mean
     * the same thing everywhere the tag appears or it means nothing at all.
     * Which is exactly why null is not "grey": it is the absence of an opinion,
     * and leaves a word that already has a colour alone.
     *
     * @return list<array{id: int, name: string, color: string|null}> the item's tags after the change
     */
    public function attach(string $scopeKey, string $itemType, int $itemId, string $name, ?string $color = null): array
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return [];
        }

        $name = Str::limit(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), self::MAX_TAG_NAME, '');
        $slug = Str::slug($name);

        // Slugged to nothing — an emoji, punctuation alone — has no stable
        // identity to deduplicate on, so it is not a tag.
        if ($name === '' || $slug === '') {
            return $this->tags($itemType, $itemId);
        }

        $colour = self::colour($color);

        $tag = Tag::query()->firstOrCreate(
            ['scope_key' => $scopeKey, 'slug' => $slug],
            ['name' => $name, 'color' => $colour],
        );

        // Through recolour(), so the write has one owner — and only when the
        // colour was actually asked for and actually differs, or every tagging
        // would be an UPDATE as well as an insert.
        if ($colour !== null && self::colour($tag->color) !== $colour) {
            $this->recolour($scopeKey, (int) $tag->id, $colour);
            $tag->color = $colour;
        }

        DB::table(self::tagItemsTable())->upsert([
            [
                'tag_id' => (int) $tag->id,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['tag_id', 'item_type', 'item_id'], ['updated_at']);

        return $this->forget($itemType, $itemId)->tags($itemType, $itemId);
    }

    /**
     * @return list<array{id: int, name: string, color: string|null}> the item's tags after the change
     */
    public function detach(string $itemType, int $itemId, int $tagId): array
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return [];
        }

        DB::table(self::tagItemsTable())
            ->where('tag_id', $tagId)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();

        $this->pruneTag($tagId);

        return $this->forget($itemType, $itemId)->tags($itemType, $itemId);
    }

    public function recolour(string $scopeKey, int $tagId, ?string $color): void
    {
        if (! self::enabled()) {
            return;
        }

        Tag::query()
            ->where('scope_key', $scopeKey)
            ->whereKey($tagId)
            ->update(['color' => self::colour($color)]);
    }

    /**
     * Everything hanging on an item, dropped.
     *
     * Called when the item itself is gone for good. Orphan rows would be inert
     * — ids are never reused — but a table that only grows is a table nobody
     * trusts, and a purge that leaves the tags behind makes the filter menu
     * offer words that match nothing.
     */
    public function forgetItem(string $itemType, int $itemId): void
    {
        if (! self::isItemType($itemType)) {
            return;
        }

        DB::table(self::descriptionsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();

        $tagIds = DB::table(self::tagItemsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->pluck('tag_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        DB::table(self::tagItemsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();

        foreach ($tagIds as $tagId) {
            $this->pruneTag($tagId);
        }

        $this->forget($itemType, $itemId);
    }

    /**
     * Carries an item's description and tags over to another row.
     *
     * One caller: replacing a file writes a *new* media row, so without this the
     * `replace` policy silently dropped the description and the tags of the file
     * it replaced — and once versions kept the old row alive, it dropped them
     * onto a row nobody can reach instead, which is worse. A lineage is one file,
     * and what was written about it goes on being true of it.
     *
     * The target is cleared first: the pivot is unique on (tag, type, id), so
     * re-pointing onto a row that already carried the same tag would collide. In
     * practice the target is a row created a moment ago and has nothing.
     */
    public function moveItem(string $itemType, int $fromId, int $toId): void
    {
        if (! self::isItemType($itemType) || $fromId === $toId) {
            return;
        }

        $this->forgetItem($itemType, $toId);

        DB::table(self::descriptionsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $fromId)
            ->update(['item_id' => $toId]);

        DB::table(self::tagItemsTable())
            ->where('item_type', $itemType)
            ->where('item_id', $fromId)
            ->update(['item_id' => $toId]);

        $this->forget($itemType, $fromId)->forget($itemType, $toId);
    }

    public function forget(string $itemType, int $itemId): self
    {
        unset($this->tagCache[$itemType.':'.$itemId]);

        return $this;
    }

    public function flush(): void
    {
        $this->tagCache = [];
    }

    /* -------------------------------------------------------------- queries */

    /**
     * Narrows a listing query to the items carrying one tag.
     *
     * Both kinds are narrowed, unlike the kind filter: a folder can be tagged,
     * so hiding folders here would hide exactly what the user tagged.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function applyTag(Builder $query, string $itemType, string $idColumn, ?int $tagId): Builder
    {
        if (! self::enabled() || $tagId === null || ! self::isItemType($itemType)) {
            return $query;
        }

        return $query->whereExists(
            fn (QueryBuilder $sub) => $sub->from(self::tagItemsTable())
                ->whereColumn(self::tagItemsTable().'.item_id', $idColumn)
                ->where(self::tagItemsTable().'.item_type', $itemType)
                ->where(self::tagItemsTable().'.tag_id', $tagId)
        );
    }

    /**
     * Adds "…or its description matches, or one of its tags does" to a search
     * group the caller has already opened on the name.
     *
     * The pattern and the escape character come from FolderListing: it owns
     * what a search term means, and two copies of that rule would drift.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $group
     */
    public function orWhereMatches(Builder $group, string $itemType, string $idColumn, string $pattern): void
    {
        if (! self::enabled() || ! self::isItemType($itemType)) {
            return;
        }

        $escape = FolderListing::LIKE_ESCAPE;
        $descriptions = self::descriptionsTable();
        $tagItems = self::tagItemsTable();
        $tags = (string) (new Tag)->getTable();

        $group->orWhereExists(
            fn (QueryBuilder $sub) => $sub->from($descriptions)
                ->whereColumn($descriptions.'.item_id', $idColumn)
                ->where($descriptions.'.item_type', $itemType)
                ->whereRaw($descriptions.".description LIKE ? ESCAPE '".$escape."'", [$pattern])
        );

        $group->orWhereExists(
            fn (QueryBuilder $sub) => $sub->from($tagItems)
                ->join($tags, $tags.'.id', '=', $tagItems.'.tag_id')
                ->whereColumn($tagItems.'.item_id', $idColumn)
                ->where($tagItems.'.item_type', $itemType)
                ->whereRaw($tags.".name LIKE ? ESCAPE '".$escape."'", [$pattern])
        );
    }

    /**
     * A tag nothing carries any more is dropped.
     *
     * The alternative is a vocabulary that only grows, and a filter menu
     * offering words that match nothing. It also means there is no tag
     * management screen to build: the list of tags *is* the list in use.
     */
    private function pruneTag(int $tagId): void
    {
        $stillUsed = DB::table(self::tagItemsTable())->where('tag_id', $tagId)->exists();

        if (! $stillUsed) {
            Tag::query()->whereKey($tagId)->delete();
        }
    }
}
