<?php

namespace App\Services;

use FPDF;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\CakeOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdministrativeReportService extends FPDF
{
    private $data;
    private $restaurant;

    public function generateReport($from, $to, $restaurant)
    {
        $this->restaurant = $restaurant;
        $this->data = $this->collectData($from, $to, $restaurant->id);

        $this->AddPage();
        $this->SetAutoPageBreak(true, 20);

        // Header
        $this->renderHeader();

        // 1. Resume Global
        $this->renderFinancialSummary();

        // 2. Pillars Breakdown (Cuisine, Pizza, Bar, Gateaux)
        $this->renderPillarsBreakdown();

        // 3. Product Details by Category (NEW)
        $this->renderProductDetails();

        // 4. Payments Breakdown
        $this->renderPaymentsBreakdown();

        // 5. Expenses
        $this->renderExpenses();

        // Footer Signatures
        $this->renderSignatures();

        return $this->Output('S');
    }

    /** Helper pour gerer les accents en FPDF */
    private function s($str)
    {
        if (is_null($str)) return '';
        // Utilisation de utf8_decode ou mb_convert_encoding pour une meilleure compatibilité FPDF standard
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    private function collectData($from, $to, $restaurantId)
    {
        $start = Carbon::parse($from)->setTime(6, 0, 0);
        $to_with_time = Carbon::parse($to)->addDay()->setTime(5, 59, 59);
        $from = $start;

        // 1. CHIFFRE D'AFFAIRES (Ventes RÉELLES de la période : Commandes et Gâteaux payés)
        $orderStats = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to_with_time])
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers, SUM(vat_amount) as vat, SUM(discount_amount) as total_discounts')->first();

        $restaurant_ca = (float)($orderStats->revenue ?? 0);
        $total_discounts = (float)($orderStats->total_discounts ?? 0);

        $cake_value = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->sum('amount');

        $gozem_value = Order::where('restaurant_id', $restaurantId)
            ->where('type', 'gozem')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to_with_time])
            ->sum('total');

        $total_ca = $restaurant_ca + (float)$cake_value;

        // 2. ARGENT COLLECTÉ (Liquidité entrante pendant la période)
        $payments = Payment::where(function ($q) use ($restaurantId) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
        })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        $total_collected = (float)$payments->sum('total');

        // 3. LE PONT MATHÉMATIQUE (Différences)
        // A. Paiements reçus pour les ventes de la période
        $paymentsOnPeriodOrders = Payment::where(function ($q) use ($restaurantId, $from, $to_with_time) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('paid_at', [$from, $to_with_time]))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId)); // On inclut tous les paiements de gâteaux
        })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->sum('amount');

        // Impayés de la période (Ardoises, crédits)
        // Note: Pour les gâteaux, on considère que la vente (CA) = encaissement du jour
        $unpaid_from_period = max(0, $restaurant_ca - Payment::whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('paid_at', [$from, $to_with_time]))->whereBetween('created_at', [$from, $to_with_time])->sum('amount'));

        // B. Paiements reçus pour des commandes antérieures (Acquittement de dettes)
        $past_debts_collected = Payment::where(function ($q) use ($restaurantId, $from) {
            $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('paid_at', '<', $from))
                ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId)->where('paid_at', '<', $from));
        })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->sum('amount');

        // DEPENSES
        $expenses = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to_with_time])
            ->get();

        // VENTILATION PAR SECTION
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereBetween('paid_at', [$from, $to_with_time]))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');

        // PRODUITS PAR CATEGORIE (NOUVEAU)
        $productSales = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', 'paid')
            ->whereBetween('orders.paid_at', [$from, $to_with_time])
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.name as category_name, products.name as product_name, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.name', 'products.name')
            ->orderBy('categories.name')
            ->orderByDesc('qty')
            ->get();

        // Ajouter les gâteaux au détail des produits
        $cakeDetails = \App\Models\Payment::whereHas('cakeOrder', function($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereBetween('created_at', [$from, $to_with_time])
            ->with('cakeOrder')
            ->get()
            ->map(function($p) {
                return (object)[
                    'category_name' => 'PÂTISSERIE / GÂTEAUX',
                    'product_name'  => "PAIEMENT GATEAU: " . ($p->cakeOrder->customer_name ?? 'Client') . " (#" . ($p->cakeOrder->order_number ?? 'N/A') . ")",
                    'qty'           => 1,
                    'revenue'       => $p->amount
                ];
            });

        $productSales = collect($productSales)->concat($cakeDetails);

        return [
            'from'           => $from,
            'to'             => $to,
            'total_collected' => $total_collected,
            'restaurant_ca'  => $restaurant_ca,
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_revenue'   => (float)$cake_value,
            'gozem_revenue'  => (float)$gozem_value,
            'expenses'       => $expenses,
            'order_stats'    => $orderStats,
            'total_discounts' => $total_discounts,
            'unpaid_ardoises' => (float)$unpaid_from_period,
            'past_debts_collected' => (float)$past_debts_collected,
            'product_sales'  => $productSales
        ];
    }

    private function renderHeader()
    {
        // Logo
        if ($this->restaurant->logo && file_exists(storage_path('app/public/' . $this->restaurant->logo))) {
            try {
                $this->Image(storage_path('app/public/' . $this->restaurant->logo), 90, 10, 30);
                $this->Ln(25);
            } catch (\Exception $e) {
            }
        }

        // Direction
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, $this->s(strtoupper($this->restaurant->name)), 0, 1, 'C');

        $subtitle = data_get($this->restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 7, $this->s(strtoupper($subtitle)), 0, 1, 'C');
            $this->SetTextColor(0);
        }

        $this->SetFont('Arial', '', 10);
        if ($this->restaurant->address) $this->Cell(0, 5, $this->s($this->restaurant->address), 0, 1, 'C');
        if ($this->restaurant->phone)   $this->Cell(0, 5, "Telephone: " . $this->s($this->restaurant->phone), 0, 1, 'C');

        $this->Ln(10);

        // Titre
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 14);
        
        $dateText = Carbon::parse($this->data['from'])->format('d/m/Y');
        if ($this->data['from'] !== $this->data['to']) {
            $dateText = "DU " . $dateText . " AU " . Carbon::parse($this->data['to'])->format('d/m/Y');
        } else {
            $dateText = "LE " . $dateText;
        }

        $this->Cell(0, 12, $this->s("RAPPORT D'ANALYSE ADMINISTRATIVE - " . $dateText), 1, 1, 'C', true);

        $this->Ln(10);
    }

    private function renderProductDetails()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("3. DÉTAIL DES VENTES PAR PRODUIT"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        if ($this->data['product_sales']->isEmpty()) {
            $this->SetFont('Arial', 'I', 11);
            $this->Cell(0, 10, $this->s("Aucune vente enregistrée."), 0, 1, 'L');
            $this->Ln(5);
            return;
        }

        $currentCategory = '';
        foreach ($this->data['product_sales'] as $sale) {
            if ($currentCategory !== $sale->category_name) {
                $currentCategory = $sale->category_name;
                $this->Ln(4);
                $this->SetFont('Arial', 'B', 10);
                $this->SetFillColor(245, 245, 245);
                $this->Cell(0, 8, $this->s("CATEGORIE : " . strtoupper($currentCategory)), 0, 1, 'L', true);
                
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(100, 7, "Produit", 1, 0, 'L');
                $this->Cell(30, 7, "Quantite", 1, 0, 'C');
                $this->Cell(60, 7, "Total (HT)", 1, 1, 'R');
            }

            $this->SetFont('Arial', '', 9);
            $this->Cell(100, 7, $this->s($sale->product_name), 1, 0, 'L');
            $this->Cell(30, 7, number_format($sale->qty, 1, ',', ' '), 1, 0, 'C');
            $this->Cell(60, 7, number_format($sale->revenue, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderFinancialSummary()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 10, $this->s("1. RÉSUMÉ FINANCIER GLOBAL"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 11);
        $this->Cell(120, 8, $this->s("A. CHIFFRE D'AFFAIRES (Valeur des Ventes):"), 0, 0);
        $caTotal = $this->data['restaurant_ca'] + $this->data['cake_revenue'];
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(70, 8, number_format($caTotal, 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->SetFont('Arial', '', 11);
        $this->Cell(120, 8, $this->s("   - Ventes Globales (Restau + Bar + Pizza):"), 0, 0);
        $this->Cell(70, 8, number_format($this->data['restaurant_ca'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->Cell(120, 8, $this->s("   - Ventes Pâtisserie / Gâteaux:"), 0, 0);
        $this->Cell(70, 8, number_format($this->data['cake_revenue'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        if (($this->data['gozem_revenue'] ?? 0) > 0) {
            $this->SetFont('Arial', 'I', 11);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(120, 8, $this->s("     (Dont Commandes Gozem : " . number_format($this->data['gozem_revenue'], 0, ',', ' ') . " FCFA)"), 0, 1, 'L');
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 11);
        }

        $totalVat = ($this->data['order_stats']->vat ?? 0);
        if ($totalVat > 0) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(120, 6, $this->s("     (Dont TVA Incluse à reverser : " . number_format($totalVat, 0, ',', ' ') . " FCFA)"), 0, 1, 'L');
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 11);
        }

        $this->Ln(4);

        $this->SetFont('Arial', 'B', 11);
        $this->Cell(120, 8, $this->s("B. ARGENT COLLECTÉ (Total Encaissé en caisse):"), 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(70, 8, number_format($this->data['total_collected'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        $this->SetFont('Arial', 'I', 9);
        $this->Cell(190, 5, $this->s("   *L'argent collecté diffère du CA notamment à cause des ardoises et des acomptes :*"), 0, 1, 'L');

        $this->SetFont('Arial', 'I', 11);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(120, 7, $this->s("   - Ardoises / Crédits accordés sur cette période (Restant à payer):"), 0, 0);
        $this->Cell(70, 7, number_format($this->data['unpaid_ardoises'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->Cell(120, 7, $this->s("   - Recouvrements (Paiements perçus pour commandes antérieures):"), 0, 0);
        $this->Cell(70, 7, number_format($this->data['past_debts_collected'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        
        // DÉTAIL DES ENCAISSEMENTS (Nouveau)
        $this->SetFont('Arial', '', 10);
        foreach ($this->data['payments'] as $p) {
            $methodMapping = [
                'cash' => 'Espèces', 'card' => 'Carte Bancaire', 'wave' => 'Wave',
                'orange_money' => 'Orange Money', 'momo' => 'MTN MoMo', 'moov' => 'Moov Money'
            ];
            $lbl = $methodMapping[$p->method] ?? ucfirst($p->method);
            $this->Cell(120, 6, $this->s("     > Encaissé par $lbl:"), 0, 0);
            $this->Cell(70, 6, number_format($p->total, 0, ',', ' ') . " FCFA", 0, 1, 'R');
        }
        
        $this->SetTextColor(0);

        $this->Ln(4);
        $this->Line($this->GetX() + 80, $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(4);

        $this->SetFont('Arial', '', 11);
        $this->Cell(95, 8, $this->s("Nombre de Commandes (Restaurant):"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->count ?? 0, 0, 1, 'R');

        $this->Cell(95, 8, $this->s("Total Couverts:"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->covers ?? 0, 0, 1, 'R');

        $this->Ln(8);
    }

    private function renderPillarsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("2. ANALYSE DES VENTES PAR SECTION"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(120, 10, "Section", 1, 0, 'L', true);
        $this->Cell(70, 10, $this->s("Valeur des Ventes"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);

        $pillars = [
            'kitchen' => 'Cuisine / Restaurant',
            'bar'     => 'Bar / Boissons',
            'pizza'   => 'Pizzeria',
            'gateaux' => 'Pâtisserie / Gâteaux'
        ];

        $sumOfSections = 0;
        foreach ($pillars as $key => $label) {
            $amount = 0;
            if ($key === 'gateaux') {
                $amount = $this->data['cake_revenue'];
            } else {
                $amount = $this->data['by_destination'][$key] ?? 0;
                $sumOfSections += $amount;
            }

            $this->Cell(120, 10, $this->s($label), 1, 0, 'L');
            $this->Cell(70, 10, number_format($amount, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        // Affichage des REMISES RÉELLES
        $realDiscounts = $this->data['total_discounts'];
        if ($realDiscounts > 0) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(200, 0, 0); // Rouge pour les remises
            $this->Cell(120, 10, $this->s("Total des Remises accordées"), 1, 0, 'L');
            $this->Cell(70, 10, "-" . number_format($realDiscounts, 0, ',', ' ') . " F", 1, 1, 'R');
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 11);
        }

        // Écart technique éventuel (Articles sans destination)
        $others = $this->data['restaurant_ca'] + $realDiscounts - $sumOfSections;
        if (round($others, 2) > 1) {
            $this->Cell(120, 10, $this->s("Autres (Ventes non classées)"), 1, 0, 'L');
            $this->Cell(70, 10, number_format($others, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        // Ligne de totalisation pour prouver que Section 2 = Section 1
        $this->SetFont('Arial', 'B', 11);
        $totalCA = $this->data['restaurant_ca'] + $this->data['cake_revenue'];
        $this->Cell(120, 10, $this->s("TOTAL GLOBAL DES VENTES"), 1, 0, 'R', true);
        $this->Cell(70, 10, number_format($totalCA, 0, ',', ' ') . " F", 1, 1, 'R', true);

        $this->Ln(10);
    }

    private function renderPaymentsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("4. DÉTAIL DES ENCAISSEMENTS PAR MODE"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 10, "Mode de Paiement", 1, 0, 'L', true);
        $this->Cell(50, 10, "Nb. Transactions", 1, 0, 'C', true);
        $this->Cell(60, 10, $this->s("Total Encaissé"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        foreach ($this->data['payments'] as $p) {
            $methodMapping = [
                'cash' => 'Espèces',
                'card' => 'Carte Bancaire',
                'wave' => 'Wave',
                'orange_money' => 'Orange Money',
                'momo' => 'MTN MoMo',
                'mixx' => 'Mixx Luck',
                'moov' => 'Moov Money'
            ];
            $method = $methodMapping[$p->method] ?? ucfirst(str_replace('_', ' ', $p->method));

            $this->Cell(80, 10, $this->s($method), 1, 0, 'L');
            $this->Cell(50, 10, $p->count, 1, 0, 'C');
            $this->Cell(60, 10, number_format($p->total, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderExpenses()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("5. JOURNAL DES DÉPENSES"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        if ($this->data['expenses']->isEmpty()) {
            $this->SetFont('Arial', 'I', 11);
            $this->Cell(0, 10, $this->s("Aucune dépense enregistrée pour cette date."), 0, 1, 'L');
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(130, 10, $this->s("Motif / Bénéficiaire"), 1, 0, 'L', true);
            $this->Cell(60, 10, "Montant", 1, 1, 'R', true);

            $this->SetFont('Arial', '', 10);
            foreach ($this->data['expenses'] as $e) {
                $label = $e->category . " - " . ($e->description ?? $e->beneficiary);
                $this->Cell(130, 10, $this->s($label), 1, 0, 'L');
                $this->Cell(60, 10, "- " . number_format($e->amount, 0, ',', ' ') . " F", 1, 1, 'R');
            }

            $this->SetFont('Arial', 'B', 11);
            $this->Cell(130, 10, $this->s("TOTAL DÉPENSES"), 1, 0, 'R', true);
            $this->Cell(60, 10, "- " . number_format((float)$this->data['expenses']->sum('amount'), 0, ',', ' ') . " F", 1, 1, 'R', true);
        }

        $this->Ln(20);
    }

    private function renderSignatures()
    {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(63, 10, "La Caisse", 0, 0, 'C');
        $this->Cell(63, 10, "Comptabilite", 0, 0, 'C');
        $this->Cell(63, 10, "Direction", 0, 1, 'C');

        $this->Ln(15);
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 1, 'C');
    }
}
