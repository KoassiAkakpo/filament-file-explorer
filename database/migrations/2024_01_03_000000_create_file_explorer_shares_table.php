<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('filament-file-explorer.share.table', 'file_explorer_shares');

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();

            // The link itself. Long and random rather than derived from the
            // media, so nothing about it can be guessed from another link.
            $table->string('token', 64)->unique();

            $table->unsignedBigInteger('media_id')->index();

            // The decision, recorded. A public request has no session and no
            // authenticated user, so there is nothing to ask at that point: the
            // ability was checked when the link was made, and the root recorded
            // here is what containment runs against on every request after.
            // Deriving the root from the media instead would only ever prove
            // that the media sits under its own ancestor.
            $table->string('scope_key')->index();
            $table->unsignedBigInteger('root_folder_id');

            $table->string('created_by_type')->nullable();
            $table->string('created_by_id')->nullable();

            // Null means no expiry, which config decides whether to allow.
            $table->timestamp('expires_at')->nullable()->index();

            // Revoking keeps the row: "this link was shared and then stopped"
            // is worth more than a row that silently disappears.
            $table->timestamp('revoked_at')->nullable();

            $table->unsignedBigInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('filament-file-explorer.share.table', 'file_explorer_shares'));
    }
};
