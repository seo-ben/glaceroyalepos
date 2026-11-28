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
        $user = $request->user();
        $isManager = $user->isManager();
        $requestedUserId = $request->query('user_id');

        // Pour un caissier : uniquement sa propre session ouverte
        if (!$isManager) {
            $session = CashSession::where('restaurant_id', $user->restaurant_id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->with('user:id,first_name,last_name')
                ->latest('opened_at')
                ->first();

            if (!$session) return response()->json(null);

            return response()->json($this->buildSessionPayload($session));
        }

        // Pour un admin / manager : toutes les sessions ouvertes + filtre optionnel par caissier
        $openSessions = CashSession::where('restaurant_id', $user->restaurant_id)
            ->whereNull('closed_at')
            ->with('user:id,first_name,last_name')
            ->latest('opened_at')
            ->get();

        $mySession = $openSessions->firstWhere('user_id', $user->id);

        $openSummaries = $openSessions->map(function ($s) {
            $payload = $this->buildSessionPayload($s);
            return array_merge($payload, ['id' => $s->id, 'user_id' => $s->user_id]);
        })->values();

        // Si un caissier précis est demandé
        if ($requestedUserId && $requestedUserId !== 'all') {
            $targetSession = $openSessions->firstWhere('user_id', (int) $requestedUserId);
            if (!$targetSession) {
                return response()->json([
                    'session'       => null,
                    'my_session'    => $mySession,
                    'open_sessions' => $openSummaries,
                    'is_aggregated' => false,
                ]);
            }

            $payload = $this->buildSessionPayload($targetSession);
            $payload['my_session'] = $mySession;
            $payload['open_sessions'] = $openSummaries;
            $payload['is_aggregated'] = false;
            return response()->json($payload);
        }

        // Vue d'ensemble (Tous les caissiers)
        if ($openSessions->isEmpty()) {
            return response()->json([
                'session'       => null,
                'my_session'    => null,
                'open_sessions' => [],
                'is_aggregated' => true,
            ]);
        }

        $aggTotals = [
            'cash'               => 0.0,
            'grand_total'        => 0.0,
            'gozem_orders_total' => 0.0,
            'cake_orders_total'  => 0.0,
        ];
        $aggOpening = 0.0;
        $aggExpenses = 0.0;
        $aggSales = 0.0;
        $aggExpected = 0.0;
        $aggOrdersCount = 0;

        foreach ($openSummaries as $item) {
            $aggOpening += (float) ($item['session']->opening_amount ?? 0);
            $aggExpenses += (float) ($item['expenses_total'] ?? 0);
            $aggSales += (float) ($item['session']->total_sales ?? 0);
            $aggExpected += (float) ($item['session']->expected_amount ?? 0);
            $aggOrdersCount += (int) ($item['orders_count'] ?? 0);
            foreach ($item['totals'] as $method => $val) {
                $aggTotals[$method] = round(($aggTotals[$method] ?? 0) + (float) $val, 2);
            }
        }

        return response()->json([
            'session'            => $openSessions->count() === 1 ? $openSummaries[0]['session'] : null,
            'my_session'         => $mySession,
            'open_sessions'      => $openSummaries,
            'is_aggregated'      => true,
            'payments_by_method' => $aggTotals,
            'totals'             => $aggTotals,
            'gozem_orders_total' => $aggTotals['gozem_orders_total'],
            'cake_orders_total'  => $aggTotals['cake_orders_total'],
            'orders_count'       => $aggOrdersCount,
            'expenses_total'     => $aggExpenses,
            'aggregated_summary' => [
                'open_count'      => $openSessions->count(),
                'opening_amount'  => $aggOpening,
                'total_sales'     => $aggSales,
                'total_expenses'  => $aggExpenses,
                'expected_amount' => $aggExpected,
            ],
        ]);
    }

    private function buildSessionPayload(CashSession $session): array
    {
        $totals = $this->computeTotals($session);
        $expensesTotal = Expense::where('cash_session_id', $session->id)->sum('amount');

        $session->total_sales = (float) $totals['grand_total'] + (float) $totals['gozem_orders_total'];
        $session->expected_amount = (float) $session->opening_amount + (float) $totals['grand_total'] - (float) $expensesTotal;
        $session->total_expenses = (float) $expensesTotal;

        return [
            'session'            => $session,
            'payments_by_method' => $totals,
            'totals'             => $totals,
            'gozem_orders_total' => $totals['gozem_orders_total'] ?? 0,
            'cake_orders_total'  => $totals['cake_orders_total'] ?? 0,
            'orders_count'       => Payment::where('cash_session_id', $session->id)->whereNotNull('order_id')->distinct('order_id')->count('order_id')
                                    + Payment::where('cash_session_id', $session->id)->whereNotNull('cake_order_id')->distinct('cake_order_id')->count('cake_order_id'),
            'expenses_total'     => $expensesTotal,
        ];
    }

    /** Ouvrir une session de caisse */
    public function open(Request $request)
    {
        $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'user_id'        => 'nullable|integer|exists:users,id',
        ]);

        $actor = $request->user();
        $targetUserId = $actor->id;

        if ($request->filled('user_id') && (int) $request->user_id !== (int) $actor->id) {
            abort_unless($actor->isManager(), 403, 'Seul un Admin ou Manager peut ouvrir la caisse d\'un autre caissier.');
            $targetUser = \App\Models\User::where('restaurant_id', $actor->restaurant_id)
                ->findOrFail((int) $request->user_id);
            $targetUserId = $targetUser->id;
        }

        $existing = CashSession::where('restaurant_id', $actor->restaurant_id)
            ->where('user_id', $targetUserId)
            ->whereNull('closed_at')
            ->exists();
        abort_if($existing, 422, $targetUserId === $actor->id
            ? 'Vous avez déjà une session de caisse ouverte.'
            : 'Ce caissier a déjà une session de caisse ouverte.');

        $session = CashSession::create([
            'restaurant_id'  => $actor->restaurant_id,
            'user_id'        => $targetUserId,
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
        if (!$request->user()->isManager()) {
            abort_if((int) $session->user_id !== (int) $request->user()->id, 403, 'Vous ne pouvez clôturer que votre propre session de caisse.');
        }

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
        $user = $request->user();

        $sessions = CashSession::with('user:id,first_name,last_name')
            ->withSum('payments', 'amount')
            ->where('restaurant_id', $user->restaurant_id)
            ->when(!$user->isManager(), fn($q) => $q->where('user_id', $user->id))
            ->when($user->isManager() && $request->filled('user_id') && $request->user_id !== 'all', fn($q) => $q->where('user_id', (int) $request->user_id))
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
