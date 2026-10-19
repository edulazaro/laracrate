<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A file is public or private (2.0). How a private one is served (a signed link or
 * through PHP) is decided where it is used, so 'signed' and 'stream' become 'private'.
 *
 * A 'stream' file was never given a direct link: it is marked `sensitive`, which
 * keeps it served only through PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE laracrate_files MODIFY access ENUM('public', 'signed', 'stream', 'private') NOT NULL DEFAULT 'private'");

        DB::table('laracrate_files')->where('access', 'stream')->update(['sensitive' => true]);
        DB::table('laracrate_files')->whereIn('access', ['signed', 'stream'])->update(['access' => 'private']);

        DB::statement("ALTER TABLE laracrate_files MODIFY access ENUM('public', 'private') NOT NULL DEFAULT 'private'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE laracrate_files MODIFY access ENUM('public', 'signed', 'stream', 'private') NOT NULL DEFAULT 'signed'");

        DB::table('laracrate_files')->where('access', 'private')->where('sensitive', true)->update(['access' => 'stream']);
        DB::table('laracrate_files')->where('access', 'private')->update(['access' => 'signed']);

        DB::statement("ALTER TABLE laracrate_files MODIFY access ENUM('public', 'signed', 'stream') NOT NULL DEFAULT 'signed'");
    }
};
