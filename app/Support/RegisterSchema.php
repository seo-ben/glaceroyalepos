<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class RegisterSchema
{
    private static bool $checked = false;

    /**
     * Garantit que la colonne register_number existe sur orders et payments
     * (utile lors d'un déploiement FTP sur LWS sans accès SSH pour lancer migrate).
     */
    public static function ensureColumns(): bool
    {
        if (self::$checked) {
            return true;
        }

        if (Cache::get('schema_has_register_number_v1') === true) {
            self::$checked = true;
            return true;
        }

        try {
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

            Cache::forever('schema_has_register_number_v1', true);
            self::$checked = true;
            return true;
        } catch (\Throwable $e) {
            return Schema::hasColumn('orders', 'register_number')
                && Schema::hasColumn('payments', 'register_number');
        }
    }

    public static function currentRegisterNumber(): int
    {
        $raw = request()?->header('X-Register-Number') ?? request()?->input('register_number');
        return ((string) $raw === '2') ? 2 : 1;
    }
}
