<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
     public function up(): void
    {
        DB::statement("ALTER TABLE tables MODIFY status ENUM('available', 'occupied', 'reserved') NOT NULL DEFAULT 'available'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tables MODIFY status ENUM('open','sent_to_kitchen','partially_served','served','paid','cancelled') NOT NULL DEFAULT 'open'");
    }
};
