<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Table;
use App\Models\Product;
use App\Models\Delivery;
use App\Events\OrderCreated;
use App\Events\OrderReady;
use App\Jobs\ProcessStockDeduction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\Carbon;

class OrderController extends Controller
{
    // -------------------------------------------------------------------------
    // Liste des commandes
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        $includeCakes = $request->has('include_cakes') || $request->type === 'cakes';

        $query = Order::with([
            'table', 'waiter', 'cashier:id,first_name,last_name', 'items.product.category',
            'cancellations'       => fn($q) => $q->where('status', 'pending'),
            'items.cancellations' => fn($q) => $q->where('status', 'pending'),
        ])->where('restaurant_id', $user->restaurant_id);

        if (!$user->isManager()) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('cashier_id', $user->id);
            });
        } elseif ($request->filled('user_id') && $request->user_id !== 'all') {
            $uid = (int) $request->user_id;
            $query->where(function ($q) use ($uid) {
                $q->where('user_id', $uid)->orWhere('cashier_id', $uid);
            });
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $term = '%' . strtolower(trim($request->search)) . '%';
                $q->whereRaw('LOWER(order_number) LIKE ?',   [$term])
                  ->orWhereRaw('LOWER(customer_name) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(server_name) LIKE ?',   [$term]);
            });
        } else {
            $date = $request->date ?: now()->format('Y-m-d');
            // Business day window: 06:00 -> 05:59:59 next day for the given date
            $start = Carbon::parse($date)->setTime(6, 0, 0);
            $end = (clone $start)->addDay()->setTime(5, 59, 59);

            $query->whereBetween('created_at', [$start, $end])
                ->when($request->status,   fn($q) => $q->where('status',   $request->status))
                ->when($request->table_id, fn($q) => $q->where('table_id', $request->table_id))
                ->when(
                    $request->type && $request->type !== 'cakes',
                    fn($q) => $q->where('type', $request->type)
                );
        }

        $orders = $query->latest()->paginate($request->per_page ?? 30);

        if ($includeCakes) {
            $cakesQuery = \App\Models\CakeOrder::where('restaurant_id', $user->restaurant_id);
            if (!$user->isManager()) {
                $cakesQuery->where('user_id', $user->id);
            } elseif ($request->filled('user_id') && $request->user_id !== 'all') {
                $cakesQuery->where('user_id', (int) $request->user_id);
            }

            if ($request->filled('search')) {
                $cakesQuery->where(function ($q) use ($request) {
                    $term = '%' . strtolower(trim($request->search)) . '%';
                    $q->whereRaw('LOWER(order_number) LIKE ?',    [$term])
                      ->orWhereRaw('LOWER(customer_name) LIKE ?', [$term])
                      ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$term]);
                });
            } else {
                $date = $request->date ?: now()->format('Y-m-d');
                $start = Carbon::parse($date)->setTime(6, 0, 0);
                $end = (clone $start)->addDay()->setTime(5, 59, 59);
                $cakesQuery->whereBetween('created_at', [$start, $end])
                    ->when($request->status, fn($q) => $q->where('status', $request->status));
            }

            $cakes = $cakesQuery->latest()->get()->map(function ($cake) {
                $cake->is_cake = true;
                $cake->type    = 'cakes';
                return $cake;
            });

            $items = collect($orders->items())->concat($cakes)->sortByDesc('created_at')->values();

            return response()->json([
                'data'         => $items,
                'total'        => $orders->total() + $cakes->count(),
                'per_page'     => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
            ]);
        }

        return response()->json($orders);
    }

    // -------------------------------------------------------------------------
    // Compteurs actifs (Gozem / Takeaway)
    // -------------------------------------------------------------------------

    public function activeCounts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $activeStatuses = ['open', 'sent_to_kitchen', 'partially_served', 'served'];

        $start = Carbon::today()->setTime(6, 0, 0);
        $end = (clone $start)->addDay()->setTime(5, 59, 59);

        $gozemCount = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('type', 'gozem')
            ->whereIn('status', $activeStatuses)
            ->count();

        $takeawayCount = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('type', 'takeaway')
            ->whereIn('status', $activeStatuses)
            ->count();

        return response()->json([
            'gozem'    => $gozemCount,
            'takeaway' => $takeawayCount,
        ]);
    }

    // -------------------------------------------------------------------------
    // Commandes actives en attente (takeaway / gozem / delivery)
    // -------------------------------------------------------------------------

    public function activePending(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $start = Carbon::today()->setTime(6, 0, 0);
        $end = (clone $start)->addDay()->setTime(5, 59, 59);

        $orders = Order::with(['items.product.category', 'waiter'])
            ->where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', ['takeaway', 'gozem', 'delivery'])
            ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])
            ->latest()
            ->get();

        return response()->json($orders);
    }

    // -------------------------------------------------------------------------
    // Commande courante par table
    // -------------------------------------------------------------------------

    public function currentByTable(Request $request, int $tableId)
    {
        if (!\App\Models\Table::where('id', $tableId)->exists()) {
            $ip = $request->ip();
            \App\Models\BlockedIp::firstOrCreate(
                ['ip_address' => $ip],
                ['reason' => "Auto-ban : Scan de table inexistante (ID: $tableId)"]
            );
            \Illuminate\Support\Facades\Cache::put('blocked_ip_' . $ip, true, 3600);
            abort(403, "Table inexistante. Votre IP a été bloquée par sécurité.");
        }

        $order = Order::with([
            'items.product.category',
            'items.modifiers.modifier',
            'items.cancellations' => fn($q) => $q->where('status', 'pending'),
            'waiter:id,first_name,last_name',
            'logs'                => fn($q) => $q->latest()->limit(20),
            'cancellations'       => fn($q) => $q->where('status', 'pending'),
        ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('table_id', $tableId)
            ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])
            ->latest()
            ->firstOrFail();

        return response()->json([
            'order'       => $order,
            'amount_paid' => $order->amountPaid(),
            'amount_due'  => $order->amountDue(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Détail d'une commande
    // -------------------------------------------------------------------------

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        return response()->json(
            $order->load([
                'items.product.category', 'items.modifiers.modifier',
                'payments', 'waiter', 'cashier', 'table',
                'logs', 'delivery', 'cancellations',
            ])
        );
    }

    // -------------------------------------------------------------------------
    // Créer une commande — corrigé : generateNumber() thread-safe + retry
    // -------------------------------------------------------------------------

    public function store(Request $request)
    {
        $request->validate([
            'table_id'               => 'nullable|exists:tables,id',
            'type'                   => 'required|in:dine_in,takeaway,delivery,gozem',
            'covers'                 => 'integer|min:1',
            'notes'                  => 'nullable|string',
            'customer_name'          => 'nullable|string|max:150',
            'customer_phone'         => 'nullable|string|max:20',
            'server_name'            => 'nullable|string|max:150',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'nullable|exists:products,id',
            'items.*.custom_name'    => 'nullable|string|max:200',
            'items.*.unit_price'     => 'nullable|numeric|min:0',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.notes'          => 'nullable|string',
            'items.*.course'         => 'integer|min:1',
            'items.*.modifier_ids'   => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
        ]);

        $maxAttempts = 3;
        $attempt     = 0;

        while ($attempt < $maxAttempts) {
            try {
                $order = DB::transaction(function () use ($request) {

                    // generateNumber() avec lockForUpdate() DANS la transaction
                    $orderNumber = Order::generateNumber($request->user()->restaurant_id);

                    $order = Order::create([
                        'restaurant_id'  => $request->user()->restaurant_id,
                        'table_id'       => $request->table_id,
                        'user_id'        => $request->user()->id,
                        'order_number'   => $orderNumber,
                        'type'           => $request->type,
                        'covers'         => $request->covers ?? 1,
                        'notes'          => $request->notes,
                        'customer_name'  => $request->customer_name,
                        'customer_phone' => $request->customer_phone,
                        'server_name'    => $request->server_name,
                        'status'         => 'open',
                    ]);

                    foreach ($request->items as $itemData) {
                        $productId = $itemData['product_id'] ?? null;
                        $unitPrice = $itemData['unit_price'] ?? 0;

                        if ($productId) {
                            $product   = Product::findOrFail($productId);
                            $unitPrice = $product->price;
                        }

                        $item = $order->items()->create([
                            'product_id'  => $productId,
                            'quantity'    => $itemData['quantity'],
                            'unit_price'  => $unitPrice,
                            'subtotal'    => $unitPrice * $itemData['quantity'],
                            'notes'       => $itemData['notes'] ?? $itemData['custom_name'] ?? null,
                            'course'      => $itemData['course'] ?? 1,
                            'status'      => 'pending',
                            'is_takeaway' => $itemData['is_takeaway'] ?? false,
                        ]);

                        if (!empty($itemData['modifier_ids'])) {
                            foreach ($itemData['modifier_ids'] as $modifierId) {
                                $modifier = \App\Models\Modifier::find($modifierId);
                                $item->modifiers()->create([
                                    'modifier_id' => $modifierId,
                                    'extra_price' => $modifier?->extra_price ?? 0,
                                ]);
                            }
                        }
                    }

                    $order->recalculate();

                    if ($request->table_id) {
                        Table::where('id', $request->table_id)->update([
                            'status'           => 'occupied',
                            'occupied_since'   => now(),
                            'assigned_user_id' => $request->user()->id,
                        ]);
                    }

                    if (in_array($request->type, ['gozem', 'delivery'])) {
                        $order->delivery()->create([
                            'restaurant_id'  => $order->restaurant_id,
                            'customer_name'  => $order->customer_name ?? 'Client Gozem',
                            'customer_phone' => $order->customer_phone ?? '0000',
                            'address'        => 'Via Gozem App',
                            'status'         => 'pending',
                        ]);
                    }

                    $order->logActivity(
                        'order_created',
                        "Commande {$order->order_number} créée par {$request->user()->full_name}"
                    );

                    return $order;
                });

                // Transaction réussie — on retourne la réponse
                return response()->json($order->load('items.product.category'), 201);

            } catch (UniqueConstraintViolationException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    return response()->json([
                        'message' => 'Impossible de générer un numéro de commande unique. Veuillez réessayer.',
                    ], 500);
                }
                // Pause exponentielle avant retry : 100ms, 200ms
                usleep(100000 * $attempt);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Modifier une commande (type, couverts, notes, serveur)
    // -------------------------------------------------------------------------

    public function update(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'type'        => 'nullable|in:dine_in,takeaway,delivery,gozem',
            'covers'      => 'nullable|integer|min:1',
            'notes'       => 'nullable|string',
            'server_name' => 'nullable|string|max:150',
        ]);

        $oldType = $order->type;
        $order->update($request->only(['type', 'covers', 'notes', 'server_name']));

        // Si on passe de dine_in à un autre type, on libère la table si elle est vide
        if ($oldType === 'dine_in' && $order->type !== 'dine_in' && $order->table_id) {
            $otherOpen = Order::where('table_id', $order->table_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served'])
                ->exists();

            if (!$otherOpen && $order->table_id) {
                Table::where('id', $order->table_id)->update([
                    'status'           => Table::freeStatus(),
                    'occupied_since'   => null,
                    'assigned_user_id' => null,
                ]);
            }
        }

        if ($oldType !== $order->type) {
            $order->recalculate();
            $order->logActivity('type_updated', "Type de commande changé : {$oldType} → {$order->type}");
        }

        return response()->json($order->fresh());
    }

    // -------------------------------------------------------------------------
    // Envoyer en cuisine
    // -------------------------------------------------------------------------

    public function sendToKitchen(Request $request, Order $order)
    {
        /** @var \App\Services\TicketPrintService $ticketService */
        $ticketService = app(\App\Services\TicketPrintService::class);
        $this->authorizeOrder($request, $order);

        $request->validate([
            'item_ids'   => 'nullable|array',
            'item_ids.*' => 'exists:order_items,id',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande déjà finalisée.');

        $itemsQuery = $order->items()->where('status', 'pending');
        if ($request->item_ids) {
            $itemsQuery->whereIn('id', $request->item_ids);
        }

        $items       = $itemsQuery->get();
        $sentItemIds = $items->pluck('id')->toArray();

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'Tous les articles sont déjà en cuisine.',
                'tickets' => [],
            ]);
        }

        DB::transaction(function () use ($order, $items, $sentItemIds) {
            $items->each->update(['status' => 'preparing', 'sent_at' => now()]);

            $order->update([
                'status'             => 'sent_to_kitchen',
                'sent_to_kitchen_at' => $order->sent_to_kitchen_at ?? now(),
            ]);

            $order->logActivity(
                'sent_to_kitchen',
                count($items) . " article(s) envoyé(s) en cuisine",
                ['item_ids' => $sentItemIds]
            );
        });

        broadcast(new OrderCreated($order->load('items.product.category', 'table')))->toOthers();

        $tickets      = [];
        $destinations = ['kitchen', 'bar', 'pizza'];

        foreach ($destinations as $dest) {
            $html = $ticketService->kitchenTicketHtml($order, $dest, $sentItemIds);
            if ($html) {
                $tickets[] = ['destination' => $dest, 'html' => $html];
            }
        }

        return response()->json([
            'message' => 'Commande envoyée en cuisine.',
            'tickets' => $tickets,
        ]);
    }

    // -------------------------------------------------------------------------
    // Modifier les articles d'une commande
    // -------------------------------------------------------------------------

    public function updateItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.notes'    => 'nullable|string|max:300',
            'admin_pin'        => 'nullable|string',
        ]);

        abort_if(
            in_array($order->status, ['paid', 'cancelled']),
            422,
            'Impossible de modifier une commande finalisée.'
        );

        $modifiedItemIds = [];

        DB::transaction(function () use ($request, $order, &$modifiedItemIds) {
            foreach ($request->items as $data) {
                $item           = $order->items()->findOrFail($data['id']);
                $isServedOrPreparing = in_array($item->status, ['done', 'preparing', 'served']);

                if ($isServedOrPreparing && $data['quantity'] < $item->quantity) {
                    $user = $request->user();
                    $user->loadMissing('role');

                    if (!$user->isManager() && !$user->hasRole('admin')) {
                        $adminPin = $request->input('admin_pin');
                        if (!$adminPin) {
                            abort(403, "Cet article est déjà en cuisine. L'autorisation du propriétaire (Code PIN Admin) est obligatoire pour décrémenter ou supprimer.");
                        }

                        $boss = User::where('restaurant_id', $user->restaurant_id)
                            ->where('active', true)
                            ->whereHas('role', fn($q) => $q->where('name', 'admin'))
                            ->get()
                            ->first(fn($u) => \Illuminate\Support\Facades\Hash::check($adminPin, $u->pin));

                        abort_if(!$boss, 403, 'Code PIN Administrateur incorrect.');

                        $order->logActivity(
                            'item_reduction_authorized',
                            "Réduction de {$item->product?->name} ({$item->quantity} -> {$data['quantity']}) autorisée par {$boss->full_name} via code PIN Admin"
                        );
                    }
                }

                if ($data['quantity'] === 0) {
                    $itemName = $item->product?->name ?? $item->notes ?? 'Article';
                    $order->logActivity('item_removed', "{$itemName} supprimé de la commande");
                    $item->delete();
                } else {
                    $oldQty    = $item->quantity;
                    $wasServed = in_array($item->status, ['done', 'preparing', 'served']);

                    if (isset($data['quantity']) && $data['quantity'] > $oldQty && $wasServed) {
                        // Augmentation d'un article déjà en cuisine → on crée un delta
                        $delta   = $data['quantity'] - $oldQty;
                        $newItem = $order->items()->create([
                            'product_id'  => $item->product_id,
                            'quantity'    => $delta,
                            'unit_price'  => $item->unit_price,
                            'subtotal'    => $item->unit_price * $delta,
                            'notes'       => $data['notes'] ?? $item->notes,
                            'course'      => $item->course,
                            'status'      => 'pending',
                            'is_takeaway' => $data['is_takeaway'] ?? $item->is_takeaway,
                        ]);
                        $modifiedItemIds[] = $newItem->id;

                        $itemName = $item->product?->name ?? $item->notes ?? 'Article';
                        $order->logActivity('item_updated', "{$itemName} : +{$delta} supplémentaire(s) à envoyer en cuisine");
                    } else {
                        $updateData = [];
                        if (isset($data['quantity'])) {
                            $updateData['quantity'] = $data['quantity'];
                            $updateData['subtotal'] = $item->unit_price * $data['quantity'];
                        }
                        if (isset($data['notes']))       $updateData['notes']       = $data['notes'];
                        if (isset($data['is_takeaway'])) $updateData['is_takeaway'] = $data['is_takeaway'];
                        if (isset($data['course']))      $updateData['course']      = $data['course'];

                        if (!empty($updateData)) {
                            $item->update($updateData);
                            $itemName = $item->product?->name ?? $item->notes ?? 'Article';
                            $order->logActivity('item_updated', "{$itemName} mis à jour");
                        }
                    }
                }
            }

            $order->recalculate();
        });

        return response()->json([
            'order'              => $order->fresh(['items.product.category', 'items.modifiers']),
            'modified_item_ids'  => $modifiedItemIds,
        ]);
    }

    // -------------------------------------------------------------------------
    // Ajouter des articles à une commande
    // -------------------------------------------------------------------------

    public function addItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'                  => 'nullable|array',
            'items.*.product_id'     => 'nullable|exists:products,id',
            'items.*.custom_name'    => 'nullable|string|max:200',
            'items.*.unit_price'     => 'nullable|numeric|min:0',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.notes'          => 'nullable|string',
            'items.*.course'         => 'integer|min:1',
            'items.*.modifier_ids'   => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
            'notes'                  => 'nullable|string',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande finalisée.');

        DB::transaction(function () use ($request, $order) {
            if ($request->items && is_array($request->items)) {
                foreach ($request->items as $itemData) {
                    $productId = $itemData['product_id'] ?? null;
                    $unitPrice = $itemData['unit_price'] ?? 0;

                    if ($productId) {
                        $product   = Product::findOrFail($productId);
                        $unitPrice = $product->price;
                    }

                    $item = $order->items()->create([
                        'product_id'  => $productId,
                        'quantity'    => $itemData['quantity'],
                        'unit_price'  => $unitPrice,
                        'subtotal'    => $unitPrice * $itemData['quantity'],
                        'notes'       => $itemData['notes'] ?? $itemData['custom_name'] ?? null,
                        'course'      => $itemData['course'] ?? 1,
                        'status'      => 'pending',
                        'is_takeaway' => $itemData['is_takeaway'] ?? false,
                    ]);

                    if (!empty($itemData['modifier_ids'])) {
                        foreach ($itemData['modifier_ids'] as $modifierId) {
                            $modifier = \App\Models\Modifier::find($modifierId);
                            $item->modifiers()->create([
                                'modifier_id' => $modifierId,
                                'extra_price' => $modifier?->extra_price ?? 0,
                            ]);
                        }
                    }
                }
            }

            if ($request->has('notes')) {
                $order->update(['notes' => $request->notes]);
            }

            $order->recalculate();
            $order->logActivity('items_added', count($request->items ?? []) . " article(s) ajouté(s)");
        });

        return response()->json($order->fresh('items.product.category'));
    }

    // -------------------------------------------------------------------------
    // Appliquer une remise
    // -------------------------------------------------------------------------

    public function applyDiscount(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'type'   => 'required|in:percent,fixed',
            'value'  => 'required|numeric|min:0',
            'reason' => 'required|string',
            'pin'    => 'nullable|string|min:4|max:6',
        ]);

        $user          = $request->user();
        $authorizerName = null;

        if ($request->pin) {
            $manager = User::where('restaurant_id', $user->restaurant_id)
                ->where('active', true)
                ->whereHas('role', fn($q) => $q->whereIn('name', ['admin', 'manager']))
                ->get()
                ->first(fn($u) => Hash::check($request->pin, $u->pin));

            if (!$manager) {
                return response()->json(['message' => 'PIN manager incorrect.'], 403);
            }
            $authorizerName = $manager->full_name;
        } else {
            abort_unless($user->isManager(), 403, 'PIN requis pour appliquer une remise.');
            $authorizerName = $user->full_name;
        }

        $discount = $request->type === 'percent'
            ? round($order->subtotal * $request->value / 100, 2)
            : min($request->value, $order->subtotal);

        $order->update([
            'discount_type'            => $request->type,
            'discount_value'           => $request->value,
            'discount_amount'          => $discount,
            'discount_reason'          => $request->reason,
            'discount_authorizer_name' => $authorizerName,
        ]);
        $order->recalculate();

        $order->logActivity(
            'discount_applied',
            "Remise de {$discount} FCFA appliquée. Autorisée par : {$authorizerName} ({$request->reason})"
        );

        return response()->json($order->fresh());
    }

    // -------------------------------------------------------------------------
    // Mettre à jour le statut
    // -------------------------------------------------------------------------

    public function updateStatus(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'status' => 'required|in:open,sent_to_kitchen,partially_served,served,paid,cancelled,cancelling',
            'reason' => 'required_if:status,cancelled|string|min:5',
        ], [
            'reason.required_if' => "Un motif d'annulation est obligatoire.",
        ]);

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        if (in_array($request->status, ['paid', 'served'])) {
            $order->items()
                ->whereNotIn('status', ['cancelled', 'served'])
                ->update(['status' => 'served', 'served_at' => now()]);
        }

        if ($request->status === 'cancelled') {
            $order->logActivity(
                'order_cancelled',
                "Commande {$order->order_number} annulée. Motif: {$request->reason}"
            );

            if ($order->table_id) {
                $otherOpen = Order::where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served'])
                    ->exists();

                if (!$otherOpen) {
                    Table::where('id', $order->table_id)->update([
                        'status'           => Table::freeStatus(),
                        'occupied_since'   => null,
                        'assigned_user_id' => null,
                    ]);
                }
            }
        } else {
            $order->logActivity('status_updated', "Statut {$oldStatus} → {$request->status}");
        }

        return response()->json($order);
    }

    // -------------------------------------------------------------------------
    // Transférer une commande vers une autre table
    // -------------------------------------------------------------------------

    public function transfer(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'target_table_id' => 'required|exists:tables,id',
        ]);

        $oldTableId    = $order->table_id;
        $targetTableId = $request->target_table_id;

        if ($oldTableId == $targetTableId) {
            return response()->json(['message' => 'Même table sélectionnée.'], 422);
        }

        DB::transaction(function () use ($order, $oldTableId, $targetTableId, $request) {
            if ($oldTableId) {
                $otherOrders = Order::where('table_id', $oldTableId)
                    ->where('id', '!=', $order->id)
                    ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served'])
                    ->exists();

                if (!$otherOrders) {
                    Table::where('id', $oldTableId)->update([
                        'status'           => 'available',
                        'occupied_since'   => null,
                        'assigned_user_id' => null,
                    ]);
                }
            }

            Table::where('id', $targetTableId)->update([
                'status'           => 'occupied',
                'occupied_since'   => now(),
                'assigned_user_id' => $request->user()->id,
            ]);

            $order->update(['table_id' => $targetTableId]);

            $order->logActivity(
                'order_transferred',
                "Commande déplacée de Table {$oldTableId} vers Table {$targetTableId}"
            );
        });

        return response()->json(['message' => 'Commande transférée avec succès.']);
    }

    // -------------------------------------------------------------------------
    // Réouvrir une commande payée ou annulée
    // -------------------------------------------------------------------------

    public function reopen(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $user = $request->user();
        $user->loadMissing('role');

        if (!$user->isManager()) {
            abort(403, 'Permission refusée : Seul le Boss peut réouvrir une commande finalisée.');
        }

        if (!in_array($order->status, ['paid', 'cancelled'])) {
            abort(422, "Impossible de réouvrir cette commande car elle n'est pas finalisée (Statut actuel : {$order->status}).");
        }

        $payment = $order->payments()->first();
        if ($payment && $payment->cash_session_id) {
            $session = \App\Models\CashSession::find($payment->cash_session_id);
            if ($session && $session->closed_at) {
                abort(422, "Impossible de réouvrir cette commande car elle appartient à une session de caisse déjà clôturée (#{$session->id}).");
            }
        }

        $oldStatus = $order->status;
        $totalPaid = $order->amountPaid();

        DB::transaction(function () use ($order, $oldStatus, $totalPaid, $user) {
            $order->payments()->delete();

            $order->update([
                'status'     => 'open',
                'paid_at'    => null,
                'cashier_id' => null,
            ]);

            if ($order->table_id) {
                $table = Table::find($order->table_id);
                if ($table && in_array(strtolower($table->status), ['available', 'free'])) {
                    $table->update([
                        'status'           => 'occupied',
                        'occupied_since'   => now(),
                        'assigned_user_id' => $user->id,
                    ]);
                }
            }

            $order->logActivity(
                'order_reopened',
                "Commande payée réouverte pour modifications par l'Admin {$user->full_name}. Règlements précédents annulés (Total : {$totalPaid} FCFA). Statut précédent : {$oldStatus}",
                [
                    'reopened_by'               => $user->id,
                    'previous_status'           => $oldStatus,
                    'cancelled_payments_total'  => $totalPaid,
                ]
            );
        });

        return response()->json([
            'message' => 'La commande a été réouverte avec succès.',
            'order'   => $order->fresh(['items.product.category', 'payments', 'table']),
        ]);
    }

    // -------------------------------------------------------------------------
    // Autorisation
    // -------------------------------------------------------------------------

    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($order->restaurant_id != $request->user()->restaurant_id) {
            abort(403, "Accès refusé : Cette commande n'appartient pas à votre établissement (Ord:{$order->restaurant_id}, Usr:{$request->user()->restaurant_id})");
        }
    }
}