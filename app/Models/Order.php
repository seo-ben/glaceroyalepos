<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'order';

    protected $fillable = [
        'restaurant_id', 'table_id', 'user_id', 'cashier_id', 'register_number',
        'order_number', 'type', 'status', 'covers',
        'customer_name', 'customer_phone', 'server_name',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'discount_authorizer_name',
        'vat_rate', 'vat_amount', 'total', 'notes',
        'sent_to_kitchen_at', 'served_at', 'paid_at', 'loyalty_token',
    ];

    protected $casts = [
        'register_number'    => 'integer',
        'subtotal'           => 'decimal:2',
        'discount_value'     => 'decimal:2',
        'discount_amount'    => 'decimal:2',
        'vat_rate'           => 'decimal:2',
        'vat_amount'         => 'decimal:2',
        'total'              => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'served_at'          => 'datetime',
        'paid_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (\App\Support\RegisterSchema::ensureColumns() && empty($order->register_number)) {
                $order->register_number = \App\Support\RegisterSchema::currentRegisterNumber();
            }
        });
    }

    /**
     * Empêcher la conversion de fuseau horaire lors de la sérialisation JSON
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function getCreatedAtAttribute($value)
    {
        return $value;
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function restaurant()    { return $this->belongsTo(Restaurant::class); }
    public function table()         { return $this->belongsTo(Table::class); }
    public function waiter()        { return $this->belongsTo(User::class, 'user_id'); }
    public function cashier()       { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items()         { return $this->hasMany(OrderItem::class); }
    public function payments()      { return $this->hasMany(Payment::class); }
    public function logs()          { return $this->hasMany(OrderLog::class)->latest(); }
    public function delivery()      { return $this->hasOne(Delivery::class); }
    public function cancellations() { return $this->morphMany(Cancellation::class, 'cancellable'); }
    public function customerTabs()  { return $this->belongsToMany(CustomerTab::class, 'customer_tab_orders'); }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeOpen($q)  { return $q->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served']); }
    public function scopeToday($q) { return $q->whereDate('created_at', today()); }
    public function scopePaid($q)  { return $q->where('status', 'paid'); }

    // -------------------------------------------------------------------------
    // Calcul des totaux (Mode TTC)
    // -------------------------------------------------------------------------

    public function recalculate(): void
    {
        $subtotal = $this->items()
            ->whereNotIn('status', ['cancelled'])
            ->with('modifiers')
            ->get()
            ->sum(function ($item) {
                $modifiersPrice = $item->modifiers->sum('extra_price');
                return ($item->unit_price + $modifiersPrice) * $item->quantity;
            });

        $vatRate      = $this->vat_rate ?: ($this->restaurant->settings['default_vat_rate'] ?? 18);
        $discountAmount = 0;
        $discountReason = '';

        // 1. Promotion globale (cumulative) — basée sur les catégories sélectionnées (sauf Gozem)
        $promoActive        = (bool)  ($this->restaurant->settings['global_promotion_active']     ?? false);
        $promoRate          = (float) ($this->restaurant->settings['global_promotion_percentage'] ?? 0);
        $eligibleCategories = $this->restaurant->settings['global_promotion_categories'] ?? ['kitchen', 'pizza'];

        if (is_string($eligibleCategories)) {
            $eligibleCategories = json_decode($eligibleCategories, true) ?: ['kitchen', 'pizza'];
        }

        // Rétrocompatibilité : 'cuisine' → 'kitchen'
        $eligibleCategories = array_map(
            fn($c) => $c === 'cuisine' ? 'kitchen' : $c,
            (array) $eligibleCategories
        );

        if ($promoActive && $promoRate > 0 && $this->type !== 'gozem') {
            $eligibleSubtotal = $this->items()
                ->whereNotIn('status', ['cancelled'])
                ->whereHas('product.category', fn($q) => $q
                    ->whereIn('destination', $eligibleCategories)
                    ->where('is_promo_exempt', false)
                )
                ->with('modifiers')
                ->get()
                ->sum(function ($item) {
                    $modifiersPrice = $item->modifiers->sum('extra_price');
                    return ($item->unit_price + $modifiersPrice) * $item->quantity;
                });

            if ($eligibleSubtotal > 0) {
                $discountAmount += round($eligibleSubtotal * $promoRate / 100, 2);
                $discountReason  = "PROMO {$promoRate}%";
            }
        }

        // 2. Remise manuelle
        if ($this->discount_type && $this->discount_value > 0) {
            $manualDiscount = $this->discount_type === 'percent'
                ? round($subtotal * $this->discount_value / 100, 2)
                : min($this->discount_value, $subtotal);

            $discountAmount += $manualDiscount;

            $storedReason = $this->discount_reason ?? '';
            $manualReason = trim(preg_replace('/\bPROMO\s+\d+%\b/i', '', $storedReason));
            $manualReason = trim(preg_replace('/\s*\(\d+%\)/i', '', $manualReason));
            $manualReason = trim(preg_replace('/^\s*\+\s*|\s*\+\s*$/', '', $manualReason));
            $manualReason = trim(preg_replace('/\s+/', ' ', $manualReason));

            if (empty($manualReason) || strtolower($manualReason) === 'remise') {
                $manualReason = 'REMISE';
            }

            if ($this->discount_type === 'percent') {
                $manualReason .= ' (' . number_format((float) $this->discount_value, 0) . '%)';
            }

            $discountReason = $discountReason ? "{$discountReason} + {$manualReason}" : $manualReason;
        }

        // 3. Remise automatique Gozem
        if ($this->type === 'gozem') {
            $gozemRate = (float) ($this->restaurant->settings['gozem_discount_rate'] ?? 0);
            if ($gozemRate > 0) {
                $discountAmount += round($subtotal * $gozemRate / 100, 2);
                $discountReason  = $discountReason ? "{$discountReason} + GOZEM" : 'GOZEM';
            }
        }

        // Mode TTC : Total = Sous-total - Remise
        $total     = max(0, (float) $subtotal - (float) $discountAmount);
        $vatAmount = $vatRate > 0
            ? (float) round($total - ($total / (1 + ($vatRate / 100))), 2)
            : 0;

        $this->update([
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_reason' => $discountReason,
            'vat_rate'        => $vatRate,
            'vat_amount'      => $vatAmount,
            'total'           => $total,
        ]);
    }

    // -------------------------------------------------------------------------
    // Paiements
    // -------------------------------------------------------------------------

    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function amountDue(): float
    {
        return max(0, $this->total - $this->amountPaid());
    }

    // -------------------------------------------------------------------------
    // Génération du numéro de commande — thread-safe (lockForUpdate)
    // DOIT être appelé à l'intérieur d'un DB::transaction()
    // -------------------------------------------------------------------------

    public static function generateNumber(int $restaurantId): string
    {
        $today  = now()->format('Ymd');
        $prefix = "ORD-{$today}-";

        $lastOrder = static::withTrashed()
            ->where('restaurant_id', $restaurantId)
            ->where('order_number', 'like', "{$prefix}%")
            ->lockForUpdate()                      // ← bloque les lectures concurrentes
            ->orderBy('order_number', 'desc')
            ->first();

        $nextNum = $lastOrder
            ? ((int) substr($lastOrder->order_number, -4)) + 1
            : 1;

        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }

    // -------------------------------------------------------------------------
    // Logs d'activité
    // -------------------------------------------------------------------------

    public function logActivity(string $action, string $message, array $meta = [], ?int $userId = null): OrderLog
    {
        $userId = $userId ?? \Illuminate\Support\Facades\Auth::id();

        $orderLog = $this->logs()->create([
            'user_id' => $userId,
            'action'  => $action,
            'message' => $message,
            'meta'    => $meta,
        ]);

        \App\Models\ActivityLog::create([
            'restaurant_id' => $this->restaurant_id,
            'user_id'       => $userId,
            'action'        => $action,
            'module'        => 'pos_order',
            'subject_type'  => self::class,
            'subject_id'    => $this->id,
            'description'   => $message,
            'ip_address'    => \Illuminate\Support\Facades\Request::ip() ?? '127.0.0.1',
        ]);

        return $orderLog;
    }
}