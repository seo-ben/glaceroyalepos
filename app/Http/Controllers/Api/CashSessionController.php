<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Expense;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashSessionController extends Controller
{
    public function __construct(protected DailyReportService $reportService) {}

    /** Session courante avec tous les totaux */
    public function current(Request $request)
    {
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->with('user:id,first_name,last_name')
            ->latest()
            ->first();

        if (!$session) return response()->json(null);

        $totals = $this->computeTotals($session);
        $expensesTotal = Expense::where('cash_session_id', $session->id)->sum('amount');

        // Ventes Vécues = Somme de TOUT (Physique + Gozem)
        $session->total_sales = (float)$totals['grand_total'] + (float)$totals['gozem_orders_total'];
        
        // Total Attendu = Opening + Uniquement le Physique (grand_total) - Dépenses
        $session->expected_amount = (float)$session->opening_amount + (float)$totals['grand_total'] - (float)$expensesTotal;
        
        $session->total_expenses = (float)$expensesTotal;

        return response()->json([
            'session'            => $session,
            'payments_by_method' => $totals,
            'totals'             => $totals,
            'gozem_orders_total' => $totals['gozem_orders_total'] ?? 0,
            'cake_orders_total'  => $totals['cake_orders_total'] ?? 0,
            'orders_count'       => Payment::where('cash_session_id', $session->id)->distinct('order_id')->count() + Payment::where('cash_session_id', $session->id)->distinct('cake_order_id')->count(),
            'expenses_total'     => $expensesTotal,
        ]);
    }

    /** Ouvrir une session de caisse */
    public function open(Request $request)
    {
        $existing = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->exists();
        abort_if($existing, 422, 'Une session de caisse est déjà ouverte.');

        $request->validate(['opening_amount' => 'required|numeric|min:0']);

        $session = CashSession::create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'user_id'        => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at'      => now(),
        ]);

        $session->logActivity('cash_session_opened', "Session caisse ouverte avec {$request->opening_amount} FCFA");

        return response()->json($session, 201);
    }

    /** Fermer la session + totaux par méthode + email optionnel */
    public function close(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if((bool) $session->closed_at, 422, 'Session déjà fermée.');
        abort_unless($request->user()->hasRole(['admin', 'manager', 'cashier']), 403, 'Permission requise pour fermer la caisse.');

        $request->validate([
            'amount_to_bank'   => 'required|numeric|min:0',
            'remaining_amount' => 'required|numeric|min:0',
            'notes'            => 'nullable|string',
            'send_report_to'   => 'nullable|email',
        ]);

        $closingAmount = (float)$request->amount_to_bank + (float)$request->remaining_amount;

        $totals   = $this->computeTotals($session);
        $cashIn   = $totals['grand_total'] ?? 0;
        $expenses = Expense::where('cash_session_id', $session->id)->sum('amount');
        $expected = $session->opening_amount + $cashIn - $expenses;
        $diff     = $closingAmount - $expected;

        DB::transaction(function () use ($session, $request, $totals, $expected, $diff, $expenses, $closingAmount) {
            $session->update([
                'closing_amount'      => $closingAmount,
                'amount_to_bank'      => $request->amount_to_bank,
                'remaining_amount'    => $request->remaining_amount,
                'expected_amount'     => $expected,
                'difference'          => $diff,
                'closing_notes'       => $request->notes,
                'closed_at'           => now(),
                'cash_total'          => $totals['cash'] ?? 0,
                'card_total'          => $totals['card'] ?? 0,
                'wave_total'          => $totals['wave'] ?? 0,
                'orange_money_total'  => $totals['orange_money'] ?? 0,
                'momo_total'          => $totals['momo'] ?? 0,
                'other_total'         => $totals['other'] ?? 0,
                'gozem_orders_total'  => $totals['gozem_orders_total'] ?? 0,
                'cake_orders_total'   => $totals['cake_orders_total'] ?? 0,
                'total_expenses'      => $expenses,
            ]);
        });

        $session->logActivity(
            'cash_session_closed',
            "Session fermée. Attendu: {$expected} | Compté: {$request->closing_amount} | Écart: {$diff}"
        );

        // Envoi email rapport (Uniquement si les montants sont renseignés)
        $emailSent = false;
        $emailTo   = $request->send_report_to;
        if ($emailTo && !is_null($session->amount_to_bank) && !is_null($session->remaining_amount)) {
            $emailSent = $this->reportService->sendReportByEmail($session, $emailTo);
        }

        return response()->json([
            'session'    => $session->fresh(),
            'totals'     => $totals,
            'email_sent' => $emailSent,
            'email_to'   => $emailTo,
        ]);
    }

    /** Envoyer le rapport d'une session fermée par email */
    public function sendReport(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasRole(['admin', 'manager', 'cashier']), 403, 'Permission requise.');

        $request->validate([
            'email' => 'required|email',
            'amount_to_bank' => 'nullable|numeric',
            'remaining_amount' => 'nullable|numeric'
        ]);

        // Mise à jour de la session avec les montants saisis avant envoi du rapport
        if ($request->has('amount_to_bank') || $request->has('remaining_amount')) {
            $session->update([
                'amount_to_bank' => $request->amount_to_bank ?? $session->amount_to_bank,
                'remaining_amount' => $request->remaining_amount ?? $session->remaining_amount,
            ]);

            // Recalculer la différence (écart) s'il y a lieu
            if ($session->expected_amount > 0) {
                $totalCounted = (float)$session->amount_to_bank + (float)$session->remaining_amount;
                $session->update(['difference' => $totalCounted - $session->expected_amount]);
            }
        }

        $sent = $this->reportService->sendReportByEmail($session, $request->email);

        return response()->json([
            'message'  => $sent ? 'Rapport envoyé avec succès.' : 'Échec de l\'envoi (vérifiez votre configuration SMTP).',
            'sent'     => $sent,
            'session'  => $session->fresh()
        ]);
    }

    public function reportPreview(Request $request, CashSession $session)
    {
        $pdfContent = $this->reportService->generateSessionPdf($session);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="Rapport-Session-' . $session->id . '.pdf"');
    }

    public function reportHtml(Request $request, CashSession $session)
    {
        $session->load(['user', 'restaurant']);
        $html = app(\App\Services\TicketPrintService::class)->sessionReportHtml($session);
        return response()->json(['html' => $html]);
    }

    /** Historique des sessions */
    public function index(Request $request)
    {
        $sessions = CashSession::with('user:id,first_name,last_name')
            ->withSum('payments', 'amount')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('opened_at', $request->date))
            ->latest('opened_at')
            ->paginate(20);

        // Pour les sessions encore ouvertes, calculer dynamiquement, et pour l'historique utiliser le sum des paiements
        $sessions->getCollection()->transform(function ($session) {
            // Toujours utiliser la vraie somme des paiements pour les Ventes Net (correspond au Dashboard)
            $session->total_sales = (float)($session->payments_sum_amount ?? 0);
            
            if (!$session->closed_at) {
                $totals = $this->computeTotals($session);
                $expensesTotal = Expense::where('cash_session_id', $session->id)->sum('amount');
                $session->total_expenses = (float)$expensesTotal;
                $session->expected_amount = (float)$session->opening_amount + (float)$totals['grand_total'] - (float)$expensesTotal;
            }
            return $session;
        });

        return response()->json($sessions);
    }

    private function computeTotals(CashSession $session): array
    {
        // 1. Récupérer tous les paiements de la session
        $allPayments = Payment::where('cash_session_id', $session->id)->get();

        // 2. Identifier les commandes Gozem pour les exclure du "physique" (argent non présent en caisse)
        $gozemOrderIds = \App\Models\Order::where('restaurant_id', $session->restaurant_id)
            ->where('type', 'gozem')
            ->pluck('id')
            ->toArray();

        // Séparation des paiements
        $gozemPayments = $allPayments->filter(fn($p) => in_array($p->order_id, $gozemOrderIds));
        $physicalPayments = $allPayments->filter(fn($p) => !in_array($p->order_id, $gozemOrderIds));

        // 3. Totaux par méthode pour le physique (ce qui est réellement en caisse)
        $totals = [];
        foreach ($physicalPayments->groupBy('method') as $method => $group) {
            $totals[$method] = round($group->sum('amount'), 2);
        }

        if (!isset($totals['cash'])) {
            $totals['cash'] = 0;
        }

        // Le grand_total représente ce qui doit être physiquement en caisse (incluant CB/Mobile Money encaissés par le resto)
        $totals['grand_total'] = array_sum($totals);

        // 4. Totaux informatifs pour le frontend
        $totals['gozem_orders_total'] = (float)round($gozemPayments->sum('amount'), 2);
        $totals['cake_orders_total'] = (float)round($allPayments->whereNotNull('cake_order_id')->sum('amount'), 2);

        return $totals;
    }
}
