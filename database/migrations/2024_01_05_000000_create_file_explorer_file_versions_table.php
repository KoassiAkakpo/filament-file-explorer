<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('filament-file-explorer.versions.table', 'file_explorer_file_versions');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table): void {
            $table->id();

            // What makes several media rows one file.
            //
            // Not the folder and the file name: a rename or a move would break
            // the chain, and carrying the history across both would mean two
            // more places to keep in step. Not the id of the live row either —
            // replacing a file writes a *new* media row, so that key would have
            // to be rewritten across the whole chain on every replacement, and
            // a key that is rewritten is a key that drifts. A lineage is minted
            // once and never touched again, which is why rename, move and trash
            // need to know nothing about any of this.
            //
            // A column and not a custom property, for the reason the tags are
            // tables too: this is read in SQL, and querying inside a JSON column
            // is written differently on sqlite, MySQL and Postgres.
            $table->string('lineage', 36);

            // The live row has a line here as well — that is how its lineage is
            // known at all. Unique, so a media row cannot belong to two.
            $table->unsignedBigInteger('media_id')->unique();

            // Position in the chain, 1 for the first upload. Stored rather than
            // derived from the order of the ids, because restoring a version
            // moves it back to the head and its number has to say so.
            $table->unsignedInteger('sequence');

            // Null on exactly one row of a lineage: the one that is live. Which
            // makes "is this the current file" a condition rather than a join,
            // and one that cannot disagree with the collection the row sits in.
            $table->timestamp('replaced_at')->nullable();

            $table->timestamps();

            // The inspector asks for one lineage's history, newest first.
            $table->index(['lineage', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('filament-file-explorer.versions.table', 'file_explorer_file_versions'));
    }
};
