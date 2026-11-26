<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'register_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedTinyInteger('register_number')->default(1)->index();
            });
        }

        if (!Schema::hasColumn('payments', 'register_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedTinyInteger('register_number')->default(1)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'register_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('register_number');
            });
        }

        if (Schema::hasColumn('payments', 'register_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('register_number');
            });
        }
    }
};
