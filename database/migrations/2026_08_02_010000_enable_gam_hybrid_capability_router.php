<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gam_connections')->whereIn('driver', ['REST', 'SOAP'])->update(['driver' => 'HYBRID']);
    }

    public function down(): void
    {
        DB::table('gam_connections')->where('driver', 'HYBRID')->update(['driver' => 'REST']);
    }
};
