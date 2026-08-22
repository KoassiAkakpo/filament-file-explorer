<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = $this->table('descriptions_table', 'file_explorer_descriptions');
        $tags = $this->table('tags_table', 'file_explorer_tags');
        $tagItems = $this->table('tag_items_table', 'file_explorer_tag_items');

        if (! Schema::hasTable($descriptions)) {
            Schema::create($descriptions, function (Blueprint $table): void {
                $table->id();

                // 'folder' or 'file', never a morph class. The folder model is
                // swappable and media rows belong to Spatie's own model, so a
                // class name here would either break the day someone swaps the
                // model or need the same getMorphClass() care media rows need.
                // The explorer already discriminates its two item kinds with
                // exactly these two words, from data-fe-type down to the
                // clipboard, so this is the vocabulary the package speaks.
                $table->string('item_type', 16);
                $table->unsignedBigInteger('item_id');

                $table->text('description')->nullable();

                $table->timestamps();

                // One description per item, so writing is an upsert and reading
                // can never find two answers.
                $table->unique(['item_type', 'item_id']);
            });
        }

        if (! Schema::hasTable($tags)) {
            Schema::create($tags, function (Blueprint $table): void {
                $table->id();

                // Tags belong to a scope, like every other piece of state in
                // the package: the session keys, the clipboard, the quota and
                // the share rows are all namespaced by it. A vocabulary shared
                // across scopes would leak one tenant's words into another's
                // filter menu.
                $table->string('scope_key');

                $table->string('name');

                // What uniqueness is decided on: "Urgent" and "urgent" are the
                // same tag, and the slug is what says so on every driver
                // whatever its default collation.
                $table->string('slug');

                // A palette key ('blue', 'red', …), never a hex: the swatch is
                // drawn by the package's own CSS, so an unknown value falls
                // back to no colour instead of injecting a style.
                $table->string('color', 16)->nullable();

                $table->timestamps();

                $table->unique(['scope_key', 'slug']);
            });
        }

        if (! Schema::hasTable($tagItems)) {
            Schema::create($tagItems, function (Blueprint $table) use ($tags): void {
                $table->id();

                $table->foreignId('tag_id')->constrained($tags)->cascadeOnDelete();

                $table->string('item_type', 16);
                $table->unsignedBigInteger('item_id');

                $table->timestamps();

                $table->unique(['tag_id', 'item_type', 'item_id']);

                // The listing asks "which tags does this item carry" once per
                // rendered row, and the filter asks the reverse once per query.
                $table->index(['item_type', 'item_id']);
            });
        }
    }

    public function down(): void
    {
        // The pivot first: it holds the foreign key.
        Schema::dropIfExists($this->table('tag_items_table', 'file_explorer_tag_items'));
        Schema::dropIfExists($this->table('tags_table', 'file_explorer_tags'));
        Schema::dropIfExists($this->table('descriptions_table', 'file_explorer_descriptions'));
    }

    private function table(string $key, string $default): string
    {
        return (string) config('filament-file-explorer.annotations.'.$key, $default);
    }
};
