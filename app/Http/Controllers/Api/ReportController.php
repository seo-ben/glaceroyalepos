<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Services\AdministrativeReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    private function parseRegisterFilter(Request $request): ?int
    {
        $reg = $request->input('register');
        if ($reg === null || $reg === '' || $reg === 'all') {
            return null;
        }
        return ((string) $reg === '2') ? 2 : 1;
    }

    private function applyRegisterFilter($query, ?int $register, string $column = 'register_number')
    {
        if ($register === null) {
            return $query;
        }
        if ($register === 1) {
            return $query->where(function ($q) use ($column) {
                $q->where($column, 1)->orWhereNull($column);
            });
        }
        return $query->where($column, $register);
    }

    public function sales(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $hasRegister = \App\Support\RegisterSchema::ensureColumns();
        $registerFilter = $hasRegister ? $this->parseRegisterFilter($request) : null;
        $restaurantId = $request->user()->restaurant_id;
        $from = Carbon::parse($request->from)->setTime(6, 0, 0);
        $to_with_time = Carbon::parse($request->to)->addDay()->setTime(5, 59, 59);

        $summaryQuery = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($summaryQuery, $registerFilter);

        $summary = $summaryQuery
            ->selectRaw('COUNT(*) as orders_count, SUM(total) as revenue, AVG(total) as avg_ticket, SUM(covers) as total_covers, SUM(discount_amount) as total_discounts, SUM(vat_amount) as total_vat')->first();

        // Convertir en tableau pour pouvoir ajouter des champs personnalisés facilement
        $summaryData = $summary ? $summary->toArray() : [
            'orders_count' => 0, 'revenue' => 0, 'avg_ticket' => 0, 
            'total_covers' => 0, 'total_discounts' => 0, 'total_vat' => 0
        ];

        // Ajouter le CA des gâteaux/divers au résumé
        $cakeQuery = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereBetween('created_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($cakeQuery, $registerFilter);
        $cakeRevenue = (clone $cakeQuery)->sum('amount');
        
        $summaryData['revenue'] = (float)$summaryData['revenue'] + (float)$cakeRevenue;
        $summaryData['cake_revenue'] = (float)$cakeRevenue;
        
        // Recalculer le ticket moyen si on inclut les gâteaux/divers
        $cakeCount = (clone $cakeQuery)->distinct('cake_order_id')->count();
        
        $totalOrders = (int)$summaryData['orders_count'] + $cakeCount;
        $summaryData['avg_ticket'] = $totalOrders > 0 ? (float)$summaryData['revenue'] / $totalOrders : 0;
        $summaryData['orders_count'] = $totalOrders;

        $dateField = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m-%d', datetime(paid_at, '-6 hours'))" : 'DATE(DATE_SUB(paid_at, INTERVAL 6 HOUR))';
        $paymentDateField = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m-%d', datetime(created_at, '-6 hours'))" : 'DATE(DATE_SUB(created_at, INTERVAL 6 HOUR))';

        $byDayQuery = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($byDayQuery, $registerFilter);
        $byDay = $byDayQuery
            ->selectRaw("$dateField as date, COUNT(*) as orders, SUM(total) as revenue")
            ->groupBy('date')->orderBy('date')->get();

        // Ajouter les gâteaux par jour
        $cakeByDayQuery = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereBetween('created_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($cakeByDayQuery, $registerFilter);
        $cakeByDay = $cakeByDayQuery
            ->selectRaw("$paymentDateField as date, COUNT(DISTINCT cake_order_id) as orders, SUM(amount) as revenue")
            ->groupBy('date')
            ->get();

        // Fusionner byDay et cakeByDay
        $mergedByDay = collect($byDay)->map(function($item) use ($cakeByDay) {
            $cake = $cakeByDay->firstWhere('date', $item->date);
            if ($cake) {
                $item->orders += $cake->orders;
                $item->revenue = (float)$item->revenue + (float)$cake->revenue;
            }
            return $item;
        });

        // Ajouter les jours qui n'ont que des gâteaux
        $cakeByDay->each(function($cake) use (&$mergedByDay) {
            if (!$mergedByDay->firstWhere('date', $cake->date)) {
                $mergedByDay->push($cake);
            }
        });

        $byMethodQuery = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($byMethodQuery, $registerFilter);
        $byMethod = $byMethodQuery
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        // Ventilation comparative Caisse 1 vs Caisse 2 sur la période (toujours calculée sur le global)
        $byRegister = [];
        $totalBothRegisters = 0;
        foreach ([1, 2] as $regNum) {
            $regOrderQuery = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')
                ->whereBetween('paid_at', [$from, $to_with_time]);
            if ($hasRegister) {
                $this->applyRegisterFilter($regOrderQuery, $regNum);
            } elseif ($regNum === 2) {
                $regOrderQuery->whereRaw('1 = 0');
            }
            $regOrders = $regOrderQuery->selectRaw('COUNT(*) as orders_count, SUM(total) as revenue, SUM(covers) as total_covers')->first();

            $regCakeQuery = \App\Models\Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->whereBetween('created_at', [$from, $to_with_time]);
            if ($hasRegister) {
                $this->applyRegisterFilter($regCakeQuery, $regNum);
            } elseif ($regNum === 2) {
                $regCakeQuery->whereRaw('1 = 0');
            }
            $regCakeRev = (float) (clone $regCakeQuery)->sum('amount');
            $regCakeCnt = (int) (clone $regCakeQuery)->distinct('cake_order_id')->count();

            $regRevenue = (float) ($regOrders->revenue ?? 0) + $regCakeRev;
            $regOrdersCount = (int) ($regOrders->orders_count ?? 0) + $regCakeCnt;
            $totalBothRegisters += $regRevenue;

            $byRegister[(string) $regNum] = [
                'register'     => $regNum,
                'label'        => "Caisse {$regNum}",
                'revenue'      => round($regRevenue, 2),
                'orders_count' => $regOrdersCount,
                'avg_ticket'   => $regOrdersCount > 0 ? round($regRevenue / $regOrdersCount, 2) : 0,
                'total_covers' => (int) ($regOrders->total_covers ?? 0),
                'share'        => 0,
            ];
        }

        foreach ($byRegister as $key => $row) {
            $byRegister[$key]['share'] = $totalBothRegisters > 0
                ? round(($row['revenue'] / $totalBothRegisters) * 100, 1)
                : 0;
        }

        return response()->json([
            'summary'     => $summaryData,
            'by_day'      => $mergedByDay->sortBy('date')->values(),
            'by_method'   => $byMethod,
            'by_register' => $byRegister,
        ]);
    }

    public function topProducts(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date', 'limit' => 'integer|min:5|max:50', 'destination' => 'nullable|string']);
        $hasRegister = \App\Support\RegisterSchema::ensureColumns();
        $registerFilter = $hasRegister ? $this->parseRegisterFilter($request) : null;
        $restaurantId = $request->user()->restaurant_id;
        $from = Carbon::parse($request->from)->setTime(6, 0, 0);
        $to_with_time = Carbon::parse($request->to)->addDay()->setTime(5, 59, 59);

        $products = OrderItem::with('product:id,name,image')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', function ($q) use ($restaurantId, $from, $to_with_time, $registerFilter) {
                $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$from, $to_with_time]);
                $this->applyRegisterFilter($q, $registerFilter);
            })
            ->whereNotIn('order_items.status', ['cancelled'])
            ->when($request->destination, fn($q) => $q->where('categories.destination', $request->destination))
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as total_qty, SUM(order_items.subtotal) as total_revenue, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('total_revenue')->limit($request->limit ?? 10)->get();

        // Si pas de filtre de destination bar/pizza, on ajoute les gâteaux/divers
        if (!$request->destination || $request->destination === 'gateaux') {
            $cakesQuery = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                    $q->where('restaurant_id', $restaurantId);
                })
                ->whereBetween('created_at', [$from, $to_with_time]);
            $this->applyRegisterFilter($cakesQuery, $registerFilter);
            $cakes = $cakesQuery
                ->with('cakeOrder')
                ->get()
                ->groupBy('cake_order_id')
                ->map(function($group) {
                    $first = $group->first();
                    return (object)[
                        'product_id' => 'cake_' . $first->cake_order_id,
                        'total_qty' => 1,
                        'total_revenue' => (float)$group->sum('amount'),
                        'revenue' => (float)$group->sum('amount'),
                        'product' => (object)[
                            'name' => "DIVERS: " . ($first->cakeOrder->customer_name ?? 'Client') . " (#" . ($first->cakeOrder->order_number ?? 'N/A') . ")",
                            'image' => null
                        ]
                    ];
                })->values();

            $products = collect($products)->concat($cakes)->sortByDesc('total_revenue')->take($request->limit ?? 10)->values();
        }

        return response()->json($products);
    }

    public function byWaiter(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $hasRegister = \App\Support\RegisterSchema::ensureColumns();
        $registerFilter = $hasRegister ? $this->parseRegisterFilter($request) : null;
        $restaurantId = $request->user()->restaurant_id;
        $from = Carbon::parse($request->from)->setTime(6, 0, 0);
        $to_with_time = Carbon::parse($request->to)->addDay()->setTime(5, 59, 59);

        $waiterQuery = Order::with('waiter:id,first_name,last_name')->where('restaurant_id', $restaurantId)->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to_with_time]);
        $this->applyRegisterFilter($waiterQuery, $registerFilter);

        $data = $waiterQuery
            ->selectRaw('user_id, COUNT(*) as orders_count, SUM(total) as revenue, AVG(total) as avg_ticket')
            ->groupBy('user_id')->orderByDesc('revenue')->get();
        
        return response()->json($data->values());
    }

    public function byCategory(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $restaurantId = $request->user()->restaurant_id;
        $from = Carbon::parse($request->from)->setTime(6, 0, 0);
        $to_with_time = Carbon::parse($request->to)->addDay()->setTime(5, 59, 59);

        $data = OrderItem::with('product.category:id,name')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$from, $to_with_time]))
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('products.category_id, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->groupBy('products.category_id')->orderByDesc('revenue')->get();

        // Ajouter la catégorie gâteaux
        $cakeRevenue = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->sum('amount');

        if ($cakeRevenue > 0) {
            $data->push((object)[
                'category_id' => 'gateaux',
                'qty' => \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                        $q->where('restaurant_id', $restaurantId);
                    })->whereBetween('created_at', [$from, $to_with_time])->count(),
                'revenue' => (float)$cakeRevenue,
                'product' => (object)['category' => (object)['name' => 'PÂTISSERIE / GÂTEAUX']]
            ]);
        }

        return response()->json($data->sortByDesc('revenue')->values());
    }

    public function cashSummary(Request $request)
    {
        $request->validate(['date' => 'nullable|date', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $hasRegister = \App\Support\RegisterSchema::ensureColumns();
        $registerFilter = $hasRegister ? $this->parseRegisterFilter($request) : null;
        $fromDate = $request->from ?? $request->date ?? today()->toDateString();
        $toDate = $request->to ?? $fromDate;
        $restaurantId = $request->user()->restaurant_id;
        $start = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $end = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $sessions = \App\Models\CashSession::with('user:id,first_name,last_name')->where('restaurant_id', $restaurantId)->whereBetween('opened_at', [$start, $end])->get();
        $paymentsQuery = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$start, $end]);
        $this->applyRegisterFilter($paymentsQuery, $registerFilter);
        $payments = $paymentsQuery
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        return response()->json(['date' => $fromDate, 'sessions' => $sessions, 'payments' => $payments, 'total' => $payments->sum('total')]);
    }

    public function dashboard(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        // Business day: from 06:00 of the date to 05:59:59 next day
        $start = Carbon::today()->setTime(6, 0, 0);
        $end = (clone $start)->addDay()->setTime(5, 59, 59);

        $revenueToday = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $ordersTodayCount = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end])->count();
        $cakeOrdersTodayCount = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->whereBetween('created_at', [$start, $end])
            ->distinct('cake_order_id')
            ->count();
        
        $totalOrdersCount = $ordersTodayCount + $cakeOrdersTodayCount;
        $coversToday = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end])->sum('covers');
        
        $avgTicket = $totalOrdersCount > 0 ? $revenueToday / $totalOrdersCount : 0;

        $tablesStats = \App\Models\Table::whereHas('floor', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->selectRaw("status, COUNT(*) as count")->groupBy('status')->get()->keyBy('status');

        // Yesterday business-day window
        $yesterdayStart = (clone $start)->subDay();
        $yesterdayEnd = (clone $end)->subDay();

        $yesterdayRevenue = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->sum('amount');

        $growth = $yesterdayRevenue > 0 ? (($revenueToday - $yesterdayRevenue) / $yesterdayRevenue) * 100 : 0;

        $pendingRevenue = Order::where('restaurant_id', $restaurantId)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->sum('total');
        $activeOrders = Order::where('restaurant_id', $restaurantId)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->count();

        // NOUVEAU: Ventes par destination (Cuisine, Bar, Pizza, Gateaux) pour aujourd'hui
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end]))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');
        
        $cakeRevenueToday = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
        
        if ($cakeRevenueToday > 0) {
            $byDestination['gateaux'] = (float)$cakeRevenueToday;
        }

        // Ventes horaires pour le graphique (Inclure les paiements de gâteaux)
        $hourField = DB::getDriverName() === 'sqlite' ? 'strftime("%H", created_at)' : 'HOUR(created_at)';

        $hourlySales = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("$hourField as hour, SUM(amount) as revenue")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // Formater pour toutes les heures de service (ex: 8h à 23h)
        $chartData = [];
        // Build chart for business day hours: 06 -> 05 (24h range)
        for ($i = 6; $i <= 29; $i++) {
            $h = $i % 24;
            $key = str_pad($h, 2, '0', STR_PAD_LEFT);
            $chartData[] = [
                'hour' => $h,
                'revenue' => (float) ($hourlySales[$key]->revenue ?? $hourlySales[$h]->revenue ?? 0)
            ];
        }

        return response()->json([
            'revenue_today'   => round($revenueToday, 2),
            'pending_revenue' => round($pendingRevenue, 2),
            'orders_today'    => $totalOrdersCount,
            'active_orders'   => $activeOrders,
            'covers_today'    => $coversToday,
            'avg_ticket'      => round($avgTicket, 2),
            'growth_percent'  => round($growth, 1),
            'tables'          => $tablesStats,
            'by_destination'  => $byDestination,
            'hourly_sales'    => $chartData,
        ]);
    }

    public function departmentSales(Request $request)
    {
        $request->validate([
            'date'        => 'nullable|date',
            'destination' => 'nullable|string',
            'register'    => 'nullable|string',
        ]);

        $hasRegister = \App\Support\RegisterSchema::ensureColumns();
        $registerFilter = $hasRegister ? $this->parseRegisterFilter($request) : null;
        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;
        $start = Carbon::parse($date)->setTime(6, 0, 0);
        $end = (clone $start)->addDay()->setTime(5, 59, 59);

        $allItemsUnfiltered = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereBetween('orders.created_at', [$start, $end])
            ->whereNull('order_items.deleted_at')
            ->select('order_items.*')
            ->with(['order', 'product.category'])
            ->get();

        // Résumé par caisse (Caisse 1 vs Caisse 2) sur la journée
        $summaryByRegister = [];
        foreach ([1, 2] as $regNum) {
            $regItems = $allItemsUnfiltered->filter(function ($item) use ($regNum, $hasRegister) {
                if (!$hasRegister) {
                    return $regNum === 1;
                }
                $r = (int) ($item->order->register_number ?? 1);
                return $r === $regNum;
            });
            $summaryByRegister[(string) $regNum] = [
                'register'     => $regNum,
                'label'        => "Caisse {$regNum}",
                'revenue'      => (float) $regItems->unique('order_id')->sum(fn($item) => (float) ($item->order->total ?? 0)),
                'orders_count' => $regItems->unique('order_id')->count(),
                'items_count'  => (int) $regItems->sum('quantity'),
            ];
        }

        // Filtrer par caisse si demandé
        $allItems = $registerFilter !== null
            ? $allItemsUnfiltered->filter(fn($item) => ((int) ($item->order->register_number ?? 1)) === $registerFilter)
            : $allItemsUnfiltered;

        $items = $allItems;

        // Résumé global par destination pour les onglets (selon la caisse sélectionnée)
        $summaryByDestination = $allItems->groupBy(function ($item) {
            $dest = $item->product?->category?->destination ?? 'kitchen';
            return $dest === 'pizza' ? 'kitchen' : $dest;
        })->map(function ($group) {
            return [
                'revenue' => (float) $group->sum(function ($item) {
                    $orderSubtotal = (float) ($item->order->subtotal ?? 0);
                    $discount = (float) ($item->order->discount_amount ?? 0);
                    $rate = $orderSubtotal > 0 ? $discount / $orderSubtotal : 0;
                    return $item->subtotal * (1 - $rate);
                }),
                'count'   => $group->sum('quantity')
            ];
        });

        // Filtrer par destination via la collection
        if ($request->destination && $request->destination !== 'all') {
            $items = $items->filter(function ($item) use ($request) {
                $dest = $item->product?->category?->destination ?? 'kitchen';
                if ($dest === 'pizza') $dest = 'kitchen';
                return $dest === $request->destination;
            });
        }

        $summary = [
            'items_count'   => $items->sum('quantity'),
            'orders_count'  => $items->unique('order_id')->count(),
            'total_revenue' => (float) $items->sum(function ($item) {
                $orderSubtotal = (float) ($item->order->subtotal ?? 0);
                $discount = (float) ($item->order->discount_amount ?? 0);
                $rate = $orderSubtotal > 0 ? $discount / $orderSubtotal : 0;
                return $item->subtotal * (1 - $rate);
            })
        ];

        return response()->json([
            'date'                   => $date,
            'register'               => $registerFilter ? (string) $registerFilter : 'all',
            'items'                  => $items->values(),
            'summary'                => $summary,
            'summary_by_destination' => $summaryByDestination,
            'summary_by_register'    => $summaryByRegister,
            'total_all'              => (float) $allItems->unique('order_id')->sum(fn($item) => (float) ($item->order->total ?? 0)),
            'total_general'          => (float) $allItemsUnfiltered->unique('order_id')->sum(fn($item) => (float) ($item->order->total ?? 0)),
        ]);
    }

    /**
     * Export PDF du journal de production
     */
    public function departmentSalesPdf(Request $request)
    {
        $res = $this->departmentSales($request);
        $data = $res->getData();
        $restaurant = $request->user()->restaurant;

        $html = view('reports.department-sales', [
            'data'       => $data,
            'restaurant' => $restaurant,
            'destination' => $request->destination ?: 'all'
        ])->render();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        return $pdf->download("Production_{$request->destination}_{$data->date}.pdf");
    }

    /**
     * Analyse complète du jour (Données JSON)
     */
    public function dailyAnalysis(Request $request)
    {
        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;
        $start = Carbon::parse($date)->setTime(6, 0, 0);
        $end = (clone $start)->addDay()->setTime(5, 59, 59);

        // 1. Résumé Global (Paiements)
        $payments = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        $totalRevenue = $payments->sum('total');

        // 2. Répartition par Destination (Restaurant/Bar/Pizza/Cuisine)
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end]))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get();

        // 3. Détail des Gâteaux (encaissé aujourd'hui)
        $cakePayments = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->with('cakeOrder:id,order_number,customer_name')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // 4. Dépenses
        $expenses = \App\Models\Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // 5. Statistiques Commandes Standard
        $ordersStats = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end])
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers, SUM(vat_amount) as vat')->first();

        // 6. Top Produits
        $topProducts = OrderItem::with('product:id,name')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$start, $end]))
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('revenue')->limit(5)->get();

        return [
            'date'           => $date,
            'total_revenue'  => (float) $totalRevenue,
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_payments'  => $cakePayments,
            'expenses'       => $expenses,
            'orders_stats'   => $ordersStats,
            'top_products'   => $topProducts,
        ];
    }

    /**
     * Export PDF de l'analyse complète (Version Administrative FPDF)
     */
    public function dailyAnalysisPdf(Request $request, AdministrativeReportService $reportService)
    {
        $from = $request->from ?? $request->date ?? today()->toDateString();
        $to = $request->to ?? $from;
        $restaurant = $request->user()->restaurant;

        $pdfContent = $reportService->generateReport($from, $to, $restaurant);

        $filename = $from === $to ? "Rapport_Administratif_{$from}.pdf" : "Rapport_Administratif_{$from}_au_{$to}.pdf";

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function taxSummary(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from'
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $start = Carbon::parse($request->from)->setTime(6, 0, 0);
        $end = Carbon::parse($request->to)->addDay()->setTime(5, 59, 59);

        $totals = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(vat_amount) as vat')
            ->first();

        $gozemTotals = Order::where('restaurant_id', $restaurantId)
            ->where('type', 'gozem')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('SUM(total) as revenue, SUM(vat_amount) as vat')
            ->first();

        // On peut aussi inclure les gâteaux si nécessaire, mais souvent la TVA dépend de la facturation standard

        return response()->json([
            'from' => $request->from,
            'to' => $request->to,
            'orders_count' => (int) ($totals->count ?? 0),
            'total_revenue' => (float) ($totals->revenue ?? 0),
            'total_vat' => (float) ($totals->vat ?? 0),
            'gozem_revenue' => (float) ($gozemTotals->revenue ?? 0),
            'gozem_vat' => (float) ($gozemTotals->vat ?? 0),
        ]);
    }
}
