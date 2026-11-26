<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'table';

    protected $fillable = [
        'floor_id', 'number', 'capacity', 'status',
        'assigned_user_id', 'position_x', 'position_y',
        'width', 'height', 'shape', 'occupied_since', 'active'
    ];

    protected $casts = [
        'occupied_since' => 'datetime',
        'active'         => 'boolean'
    ];

    public function floor()        { return $this->belongsTo(Floor::class); }
    public function assignedUser() { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function reservations(){ return $this->hasMany(Reservation::class); }
    public function orders()       { return $this->hasMany(Order::class); }

    public function currentOrder()
    {
        return $this->hasOne(Order::class)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->latest('created_at');
    }

    /**
     * Retourne la valeur ENUM valide pour une table libre ('available' ou 'free').
     */
    public static function freeStatus(): string
    {
        return \Illuminate\Support\Facades\Cache::remember('tables_free_status_v1', 3600, function () {
            try {
                if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
                    $col = \Illuminate\Support\Facades\DB::selectOne("SHOW COLUMNS FROM tables LIKE 'status'");
                    $type = strtolower($col->Type ?? '');
                    if (str_contains($type, "'available'") && !str_contains($type, "'free'")) {
                        return 'available';
                    }
                }
            } catch (\Throwable $e) {
                // fallback
            }
            return 'free';
        });
    }
}
