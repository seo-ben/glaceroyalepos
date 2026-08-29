<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\CustomerTab;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DailyReportService
 * Génère le rapport journalier HTML ultra-professionnel (format A4) et l'envoie par email au boss.
 */
class DailyReportService
{
    /**
     * Génère un vrai PDF FPDF pour le rapport de caisse
     */
    public function generateSessionPdf(CashSession $session): string
    {
        $data = $this->getReportData($session);
        $pdf = new \App\Libraries\Fpdf('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        
        // 1. En-tête
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(0, 0, 0); // Noir
        $restaurantName = $data['restaurant'] ? $data['restaurant']->name : 'RESTAURANT';
        $pdf->Cell(0, 8, utf8_decode(strtoupper($restaurantName)), 0, 1, 'C');
        
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, "RAPPORT FINANCIER DE CAISSE", 0, 1, 'C');
        $pdf->Ln(4);
        
        // 2. Info Session
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(90, 6, "SESSION #" . $session->id, 0, 0, 'L');
        $pdf->Cell(90, 6, "Date: " . ($session->opened_at ? $session->opened_at->format('d/m/Y') : date('d/m/Y')), 0, 1, 'R');
        $pdf->Cell(180, 6, "Caissier: " . utf8_decode(strtoupper($session->user ? $session->user->first_name : 'SYSTEM')), 0, 1, 'L');
        $pdf->Ln(4);
        
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(6);

        // 3. Totaux Principaux
        $pdf->SetFont('Helvetica', 'B', 12);
        
        $currentY = $pdf->GetY();
        
        // Rectangles de fond pour Recette, Dépenses
        $pdf->SetFillColor(0, 0, 0); // Noir
        $pdf->Rect(10, $currentY, 90, 20, 'F');
        $pdf->SetFillColor(240, 240, 240); // Gris très clair
        $pdf->Rect(105, $currentY, 95, 20, 'F');
        
        // Textes
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, $currentY + 3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(90, 5, "RECETTE TOTALE", 0, 1, 'C');
        $pdf->SetXY(10, $currentY + 8);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(90, 8, number_format((float)$data['totalRevenue'], 0, ',', ' ') . " FCFA", 0, 1, 'C');
        
        if ($data['totalVat'] > 0) {
            $pdf->SetXY(10, $currentY + 14);
            $pdf->SetFont('Helvetica', 'I', 7);
            $pdf->Cell(90, 5, utf8_decode("(Dont TVA : " . number_format((float)$data['totalVat'], 0, ',', ' ') . " F)"), 0, 1, 'C');
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(105, $currentY + 3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(95, 5, utf8_decode("DÉPENSES"), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0); 
        $pdf->SetXY(105, $currentY + 8);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(95, 8, "- " . number_format((float)$data['totalExpenses'], 0, ',', ' ') . " FCFA", 0, 1, 'C');

        $pdf->SetXY(15, $currentY + 25);
        $pdf->SetTextColor(0, 0, 0);

        // 4. Synthèse Trésorerie
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" SYNTHESE DES FLUX"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(90, 6, "Ouverture de caisse", 'B', 0, 'L');
        $pdf->Cell(90, 6, number_format((float)$session->opening_amount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        // Calcul dynamique si la session n'est pas encore clôturée
        $cashSales = (float)($session->cash_total > 0 ? $session->cash_total : ($data['payments']->filter(fn($p) => strtolower($p->method) === 'cash')->sum('total') ?? 0));
        $pdf->Cell(90, 6, "Ventes en especes", 'B', 0, 'L');
        $pdf->Cell(90, 6, "+" . number_format($cashSales, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(90, 6, utf8_decode("Depenses deduites"), 'B', 0, 'L');
        $pdf->Cell(90, 6, "-" . number_format((float)$data['totalExpenses'], 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $expectedAmount = (float)($session->expected_amount > 0 ? $session->expected_amount : ($session->opening_amount + $cashSales - $data['totalExpenses']));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(90, 8, "MONTANT TOTAL ATTENDU", 'B', 0, 'L');
        $pdf->Cell(90, 8, number_format($expectedAmount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(90, 6, "Verse a la Banque", 'B', 0, 'L');
        $pdf->Cell(90, 6, ($session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') : '0') . " FCFA", 'B', 1, 'R');
        
        $pdf->Cell(90, 6, "Fond de roulement restant", 'B', 0, 'L');
        $pdf->Cell(90, 6, ($session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') : '0') . " FCFA", 'B', 1, 'R');
        
        $diffColor = [0, 0, 0];
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(90, 10, utf8_decode("ECART FINAL"), 'B', 0, 'L');
        $pdf->SetTextColor($diffColor[0], $diffColor[1], $diffColor[2]);
        $pdf->Cell(90, 10, number_format((float)($session->difference ?? 0), 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);

        // 5. Modes de Paiements
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" DETAILS DES ENCAISSEMENTS"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(70, 6, "Mode", 'B', 0, 'L');
        $pdf->Cell(40, 6, "Nb", 'B', 0, 'C');
        $pdf->Cell(70, 6, "Montant", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($data['payments'] as $p) {
            $pdf->Cell(70, 6, strtoupper($p->method), 'B', 0, 'L');
            $pdf->Cell(40, 6, $p->count, 'B', 0, 'C');
            $pdf->Cell(70, 6, number_format((float)$p->total, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        }

        $gozemTotal = (float)($session->gozem_orders_total > 0 ? $session->gozem_orders_total : $data['gozem_orders_total']);
        if ($gozemTotal > 0) {
            $pdf->Ln(2);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(255, 140, 0); // Orange
            $pdf->Cell(110, 6, "DONT COMMANDES GOZEM (Total)", 'B', 0, 'L');
            $pdf->Cell(70, 6, number_format($gozemTotal, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
            $pdf->SetTextColor(0, 0, 0);
        }

        $cakeTotal = (float)($session->cake_orders_total > 0 ? $session->cake_orders_total : ($data['cake_orders_total'] ?? 0));
        if ($cakeTotal > 0) {
            $pdf->Ln(2);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(0, 128, 0); // Vert
            $pdf->Cell(110, 6, utf8_decode("DONT COMMANDES GÂTEAUX (Total)"), 'B', 0, 'L');
            $pdf->Cell(70, 6, number_format($cakeTotal, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->Ln(8);

        // 5.5 Performances des secteurs (Catégories) et Crédits
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" PERFORMANCES DES SECTEURS ET CRÉDITS"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(130, 6, utf8_decode("Secteur / Catégorie"), 'B', 0, 'L');
        $pdf->Cell(50, 6, "Recette", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($data['categoryStats'] as $catStat) {
            if ($pdf->GetY() > 270) $pdf->AddPage();
            $pdf->Cell(130, 6, utf8_decode("VENTES - " . strtoupper($catStat->category_name)), 'B', 0, 'L');
            $pdf->SetTextColor(0, 0, 0); 
            $pdf->Cell(50, 6, number_format((float)$catStat->total_amount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
            $pdf->SetTextColor(0, 0, 0); // Reset
        }
        
        if ($pdf->GetY() > 270) $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(130, 6, utf8_decode("TOTAL DES ARDOISES (CRÉDITS)"), 'B', 0, 'L');
        $pdf->Cell(50, 6, number_format((float)$data['totalCredits'], 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        $pdf->SetTextColor(0, 0, 0); // Reset
        
        $pdf->Ln(5);
        
        // 6. QR Code
        $qrPath = public_path('img/website_qr.png');
        if (file_exists($qrPath)) {
            $pdf->Image($qrPath, 90, $pdf->GetY(), 30, 30);
            $pdf->Ln(32);
        }

        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, utf8_decode("Document généré le " . date('d/m/Y à H:i') . " par Omega POS"), 0, 1, 'C');

        return $pdf->Output('S');
    }

    /**
     * Envoie le rapport par email au boss (Email de luxe)
     */
    public function getReportData(CashSession $session): array
    {
        // 1. Chiffre d'affaires basé sur les PAIEMENTS de cette session (C'est le plus juste)
        $paymentsQuery = \App\Models\Payment::where('cash_session_id', $session->id);
        
        $totalRevenue = (float)$paymentsQuery->sum('amount');
        
        // Exclure les paiements Gozem du revenu "physique" si nécessaire, mais ici on garde tout pour la synthèse
        $payments = $paymentsQuery->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        // 2. Dépenses
        $totalExpenses = Expense::where('cash_session_id', $session->id)->sum('amount');
        $restaurant = $session->restaurant;

        // 3. Ardoises / Crédits (Commandes liées à un client mais payées en "tab")
        // On cherche les commandes payées durant cette session via la méthode 'tab'
        $tabDetails = \App\Models\Order::whereHas('payments', function($q) use ($session) {
                $q->where('cash_session_id', $session->id)->where('method', 'tab');
            })
            ->with(['customerTabs'])
            ->get();
        $newCredits = $tabDetails->sum('total');

        // 4. Statistiques produits (incluant les gâteaux)
        $productStats = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereIn('orders.id', function($query) use ($session) {
                $query->select('order_id')->from('payments')->where('cash_session_id', $session->id)->whereNotNull('order_id');
            })
            ->whereNull('order_items.deleted_at')
            ->select('products.name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as total_qty'), \Illuminate\Support\Facades\DB::raw('SUM(order_items.subtotal) as total_amount'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_amount')
            ->get();

        // Ajouter les gâteaux aux stats produits
        $cakeStats = \App\Models\CakeOrder::where('cash_session_id', $session->id)
            ->get()
            ->map(function($cake) {
                return (object)[
                    'name' => "GATEAU: " . $cake->customer_name . " (#" . $cake->order_number . ")",
                    'total_qty' => 1,
                    'total_amount' => $cake->total
                ];
            });
        
        $productStats = collect($productStats)->concat($cakeStats)->sortByDesc('total_amount');

        $categoryStats = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('orders.id', function($query) use ($session) {
                $query->select('order_id')->from('payments')->where('cash_session_id', $session->id)->whereNotNull('order_id');
            })
            ->whereNull('order_items.deleted_at')
            ->select('categories.name as category_name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as total_qty'), \Illuminate\Support\Facades\DB::raw('SUM(order_items.subtotal) as total_amount'))
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_amount')
            ->get();

        // Ajouter les gâteaux aux stats catégories
        $cakeCategoryTotal = \App\Models\Payment::where('cash_session_id', $session->id)
            ->whereNotNull('cake_order_id')
            ->sum('amount');

        if ($cakeCategoryTotal > 0) {
            $categoryStats = collect($categoryStats)->concat([
                (object)[
                    'category_name' => 'PATISSERIE / GATEAUX',
                    'total_qty' => \App\Models\Payment::where('cash_session_id', $session->id)->whereNotNull('cake_order_id')->count(),
                    'total_amount' => $cakeCategoryTotal
                ]
            ])->sortByDesc('total_amount');
        }

        $totalVat = (float)\App\Models\Order::whereIn('id', function($q) use ($session) {
            $q->select('order_id')->from('payments')->where('cash_session_id', $session->id)->whereNotNull('order_id');
        })->sum('vat_amount');

        $logs = \App\Models\ActivityLog::where('restaurant_id', $session->restaurant_id)
            ->where(function($q) use ($session) {
                $q->where(function($sq) use ($session) {
                    $sq->where('subject_type', CashSession::class)
                       ->where('subject_id', $session->id);
                })->orWhere(function($sq) use ($session) {
                    $sq->where('module', 'cash')
                      ->whereBetween('created_at', [$session->opened_at, $session->closed_at ?? now()]);
                });
            })->with('user')->orderBy('created_at', 'asc')->limit(100)->get();

        $pdfUrl = rtrim(config('app.url'), '/') . "/api/cash-sessions/{$session->id}/report";

        $gozemOrdersTotal = (float)\App\Models\Order::where('restaurant_id', $session->restaurant_id)
            ->where('type', 'gozem')
            ->whereIn('id', function($q) use ($session) {
                $q->select('order_id')->from('payments')->where('cash_session_id', $session->id)->whereNotNull('order_id');
            })
            ->sum('total');

        $cakeOrdersTotal = (float)\App\Models\Payment::where('cash_session_id', $session->id)
            ->whereNotNull('cake_order_id')
            ->sum('amount');

        return [
            'session'            => $session,
            'restaurant'         => $restaurant,
            'totalRevenue'       => $totalRevenue,
            'payments'           => $payments,
            'totalExpenses'      => $totalExpenses,
            'totalCredits'       => $newCredits,
            'productStats'       => $productStats,
            'categoryStats'      => $categoryStats,
            'totalVat'           => $totalVat,
            'gozem_orders_total' => $gozemOrdersTotal,
            'cake_orders_total'  => $cakeOrdersTotal,
            'logs' => $logs,
            'pdfUrl' => $pdfUrl,
        ];
    }

    /**
     * Envoie le rapport de caisse par email
     *
     * @param CashSession $session
     * @param string $toEmail
     * @return bool
     */
    public function sendReportByEmail(CashSession $session, string $toEmail): bool
    {
        Log::info("Tentative d'envoi du rapport de caisse à : " . $toEmail);

        try {
            $data = $this->getReportData($session);
            $restaurant = $session->restaurant;

            $subject = "Rapport de Caisse — {$restaurant->name} — " . ($session->opened_at?->format('d/m/Y') ?? date('d/m/Y'));

            Mail::send('reports.cash_session', $data, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });

            $session->update([
                'report_sent_at' => now(),
                'report_email'   => $toEmail,
            ]);

            Log::info("Rapport envoyé avec succès à : " . $toEmail);
            return true;
        } catch (\Exception $e) {
            Log::error('Erreur SMTP lors de l\'envoi du rapport : ' . $e->getMessage());
            return false;
        }
    }


}
