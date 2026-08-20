<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('filament-file-explorer.folders.table', 'file_explorer_folders');

        if (Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            // Soft deletes are what the trash is built on: a trashed folder
            // disappears from every Folder::query() the explorer runs, so the
            // listing, the sidebar and the search scope need no extra filter.
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        $tableName = config('filament-file-explorer.folders.table', 'file_explorer_folders');

        if (! Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
