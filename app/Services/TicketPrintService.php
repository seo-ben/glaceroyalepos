<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemNote;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\CakeOrder;
use App\Models\Expense;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class TicketPrintService
{
    public function __construct(protected OrderRoutingService $routing) {}

    /**
     * Construit les données formatées pour un reçu (Noir & Blanc)
     */
    public function buildReceiptData(Order $order, $restaurant, array $config): array
    {
        $currencySymbol = $config['currency_symbol'] ?? 'FCFA';
        $currencyPosition = $config['currency_position'] ?? 'after';
        $formatAmount = fn($amount) => $currencyPosition === 'before'
            ? "{$currencySymbol} " . number_format($amount, 0, '.', ' ')
            : number_format($amount, 0, '.', ' ') . " {$currencySymbol}";

        $groupedLines = [];
        foreach ($order->items as $item) {
            $modifiers = $item->modifiers->map(fn($m) => [
                'id' => $m->modifier_id,
                'name' => $m->modifier?->name ?? 'Option',
                'extra_price' => (float) $m->extra_price,
                'extra_fmt' => (float) $m->extra_price > 0 ? '+' . number_format($m->extra_price, 0) : ''
            ])->sortBy('id')->values()->toArray();

            $modKey = collect($modifiers)->map(fn($m) => $m['id'] . ':' . $m['extra_price'])->implode('|');
            $groupKey = ($item->product_id ?: 'custom_' . $item->notes) . '_' . (float)$item->unit_price . '_' . $modKey . '_' . ($item->is_takeaway ? '1' : '0');

            $lineTotal = ($item->unit_price * $item->quantity) + collect($modifiers)->sum('extra_price') * $item->quantity;

            if (isset($groupedLines[$groupKey])) {
                $groupedLines[$groupKey]['quantity'] += $item->quantity;
                $groupedLines[$groupKey]['total'] += $lineTotal;
                $groupedLines[$groupKey]['total_fmt'] = $formatAmount($groupedLines[$groupKey]['total']);
            } else {
                $groupedLines[$groupKey] = [
                    'name' => $item->product?->name ?? $item->notes ?? 'ARTICLE LIBRE',
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'unit_fmt' => $formatAmount($item->unit_price),
                    'total' => $lineTotal,
                    'total_fmt' => $formatAmount($lineTotal),
                    'notes' => $item->notes,
                    'modifiers' => $modifiers,
                    'is_takeaway' => (bool) $item->is_takeaway,
                ];
            }
        }
        $lines = array_values($groupedLines);

        $paymentLines = $order->payments->map(fn($p) => [
            'method' => $this->methodLabel($p->method), 
            'amount' => $p->amount, 
            'amount_fmt' => $formatAmount($p->amount), 
            'reference' => $p->reference, 
            'amount_given' => $p->amount_given, 
            'change_given' => $p->change_given, 
            'change_fmt' => $p->change_given ? $formatAmount($p->change_given) : null
        ])->toArray();

        $isGozem = ($order->type === 'gozem');
        $displaySubtotal = $order->subtotal;
        $displayDiscount = $order->discount_amount;

        if ($isGozem) {
            $displaySubtotal = $order->total;
            $displayDiscount = 0;
        }

        $discountLabel = $order->discount_reason ?: "REMISE";
        if ($order->discount_authorizer_name) {
            $discountLabel .= " (Par: {$order->discount_authorizer_name})";
        }

        $totalTtc = (float) $order->total;
        $vatAmount = (float) ($order->vat_amount ?? 0);
        $amountHt = max(0, $totalTtc - $vatAmount);

        return [
            'restaurant' => [
                'name' => $restaurant->name, 
                'logo' => $restaurant->logo ? asset('storage/' . $restaurant->logo) : null, 
                'logo_raw' => $restaurant->logo,
                'address' => $restaurant->address, 
                'phone' => $restaurant->phone, 
                'email' => $restaurant->email, 
                'vat_number' => $restaurant->vat_number,
                'receipt_subtitle' => data_get($restaurant->settings, 'receipt_subtitle')
            ],
            'order' => [
                'id' => $order->id, 
                'number' => $order->order_number, 
                'date' => \Carbon\Carbon::parse($order->created_at)->format('d/m/Y'), 
                'time' => \Carbon\Carbon::parse($order->created_at)->format('H:i'), 
                'paid_at' => $order->paid_at?->format('d/m/Y H:i'), 
                'table_number' => $order->table?->number, 
                'covers' => $order->covers, 
                'type' => $order->type, 
                'type_label' => $this->typeLabel($order->type), 
                'waiter' => $order->server_name ?: $order->waiter?->full_name, 
                'cashier' => $order->cashier?->full_name,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'notes' => $order->notes
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal_ht' => $amountHt,
                'subtotal_ht_fmt' => $formatAmount($amountHt),
                'subtotal' => $displaySubtotal, 
                'subtotal_fmt' => $formatAmount($displaySubtotal), 
                'discount' => $displayDiscount, 
                'discount_fmt' => $displayDiscount > 0 ? '-' . $formatAmount($displayDiscount) : null, 
                'discount_reason' => $discountLabel, 
                'vat_rate' => $config['default_vat_rate'] ?? 18, 
                'vat_amount' => $vatAmount, 
                'vat_fmt' => $formatAmount($vatAmount), 
                'total' => $totalTtc, 
                'total_fmt' => $formatAmount($totalTtc), 
                'amount_paid' => $order->amountPaid(), 
                'amount_paid_fmt' => $formatAmount($order->amountPaid()), 
                'change' => $order->payments->sum('change_given'), 
                'change_fmt' => $formatAmount($order->payments->sum('change_given'))
            ],
            'payments' => $paymentLines,
            'footer' => [
                'message' => $config['receipt_footer'] ?? 'Merci de votre visite !', 
                'website' => $config['receipt_website'] ?? null, 
                'show_logo' => $config['receipt_logo'] ?? true, 
                'width' => $config['receipt_width'] ?? '80mm'
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Ticket de cuisine filtré par destination - NOIR & BLANC
     */
    public function kitchenTicketHtml(Order $order, string $destination = 'kitchen', ?array $itemIds = null): string
    {
        $order->loadMissing(['items.product.category', 'table', 'waiter']);
        $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
        if ($itemIds) { $filteredItems = $filteredItems->whereIn('id', $itemIds); }

        $allGroups = $this->routing->groupByDestination(collect($filteredItems));
        $items = $allGroups[$destination] ?? collect();
        if ($items->isEmpty()) return '';

        $restaurant = $order->restaurant;
        $typeMap = [
            'dine_in'  => 'SUR PLACE',
            'takeaway' => 'EMPORTER',
            'delivery' => 'LIVRAISON',
            'gozem'    => 'GOZEM'
        ];
        $tableLabel = ($order->table instanceof \App\Models\Table) 
            ? "TABLE " . $order->table->number 
            : ($typeMap[$order->type] ?? strtoupper($order->type));
        $date = now()->format('d/m H:i');

        return "
        <div style='font-family: \"DejaVu Sans\", sans-serif; width: 100%; font-size: 13px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 2px;'>
                " . strtoupper($restaurant->name) . " - " . strtoupper($this->routing->destinationLabel($destination)) . "
            </div>
            <div style='text-align: center; font-size: 11px;'>{$order->order_number} | {$date}</div>
            <div style='text-align: center; font-size: 12px; font-weight: bold; margin-top: 2px;'>SERVEUR: " . strtoupper($order->server_name ?: $order->waiter?->full_name ?: 'N/A') . "</div>

            <div style='border: 2px solid #000; text-align: center; font-size: 24px; font-weight: bold; margin: 3px 0;'>
                {$tableLabel}
            </div>

            " . ($order->notes ? "<div style='border: 2px dashed #000; padding: 5px; text-align: center; font-size: 14px; font-weight: bold; margin: 5px 0;'>COMMENTAIRE: {$order->notes}</div>" : "") . "

            <table style='width: 100%; border-collapse: collapse;'>
                " . $items->groupBy(function($item) {
                    $modKey = $item->modifiers->pluck('modifier_id')->sort()->implode('-');
                    return ($item->product_id ?: 'custom_' . $item->notes) . '_' . ($item->notes ?? '') . '_' . $modKey . '_' . ($item->is_takeaway ? '1' : '0');
                })->map(function($grouped) {
                    $first = $grouped->first();
                    $totalQty = $grouped->sum('quantity');
                    $modHtml = ($first->modifiers && $first->modifiers->count() > 0)
                        ? "<div style='font-size: 11px; font-weight: bold; margin-left: 10px; color: #333;'>" . 
                            $first->modifiers->map(fn($m) => "• " . strtoupper($m->modifier?->name ?? 'OPTION'))->implode('<br>') . 
                          "</div>"
                        : "";
                    $notesHtml = $first->notes ? "<div style='font-size: 10px; font-weight: bold; font-style: italic; color: #666; margin-top: 2px;'>* {$first->notes}</div>" : "";
                    $takeawayHtml = $first->is_takeaway ? "<span style='color: #000; background: #eee; padding: 0 4px; margin-left: 5px; border: 1px solid #000;'>[A EMPORTER]</span>" : "";

                    return "
                    <tr style='border-bottom: 1px dashed #000;'>
                        <td style='padding: 6px 0;'>
                            <div style='display: flex; justify-content: space-between; align-items: baseline;'>
                                <span style='font-size: 14px; font-weight: bold;'>
                                    " . strtoupper($first->product?->name ?? $first->notes ?? 'ARTICLE LIBRE') . "
                                    {$takeawayHtml}
                                </span>
                                <span style='flex-grow: 1; border-bottom: 1px dotted #aaa; margin: 0 5px; height: 10px;'></span>
                                <span style='font-size: 16px; font-weight: 900;'>{$totalQty}</span>
                            </div>
                            {$modHtml}
                            {$notesHtml}
                        </td>
                    </tr>";
                })->implode('') . "
            </table>
            <div style='margin-top: 5px; text-align: center; font-size: 10px; font-weight: bold;'>*** FIN ***</div>
        </div>";
    }

    /**
     * Reçu de dépense - NOIR & BLANC
     */
    public function expenseReceiptHtml($expense): string
    {
        $restaurant = $expense->restaurant;
        $date = \Carbon\Carbon::parse($expense->created_at)->format('d/m/y H:i');
        $id = str_pad($expense->id, 4, '0', STR_PAD_LEFT);

        return "
        <div style='font-family: \"DejaVu Sans\", sans-serif; width: 100%; font-size: 11px; color: #000; line-height: 1.1;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px dashed #000; padding-bottom: 2px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; font-weight: bold; margin: 3px 0;'>BON DE DEPENSE</div>
            
            <div style='font-size: 10px; margin-bottom: 3px;'>
                <b>DATE:</b> {$date} | <b>AGENT:</b> " . strtoupper($expense->user->first_name) . "
            </div>

            <div style='margin-bottom: 3px;'>
                <b>MOTIF:</b> " . strtoupper($expense->description) . "<br>
                <b>POUR:</b> " . strtoupper($expense->beneficiary ?: 'N/A') . "
            </div>

            <div style='text-align: center; border: 1px solid #000; padding: 3px; font-size: 16px; font-weight: bold;'>
                " . number_format($expense->amount, 0, ',', ' ') . " F
            </div>

            <div style='margin-top: 10px; border-top: 1px dashed #000; padding-top: 2px; text-align: center; font-size: 9px; font-weight: bold;'>
                SIGNATURE BENEFICIAIRE<br><br>
                ...........................
            </div>
        </div>";
    }

    /**
     * Rapport de clôture - NOIR & BLANC
     */
    public function sessionReportHtml($session): string
    {
        $restaurant = $session->restaurant;
        $open = $session->opened_at->format('d/m H:i');
        $close = $session->closed_at ? $session->closed_at->format('d/m H:i') : 'ACTIF';

        $html = "
        <div style='font-family: \"DejaVu Sans\", sans-serif; width: 100%; font-size: 10px; color: #000; line-height: 1.1;'>
            <div style='text-align: center; font-weight: bold; font-size: 12px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; border-bottom: 1px solid #000; padding-bottom: 1px; margin-bottom: 3px; font-weight: bold;'>CLOTURE</div>
            
            <div style='margin-bottom: 3px;'>
                <b>AGENT:</b> " . strtoupper($session->user->first_name) . "<br>
                <b>PERIODE:</b> {$open} au {$close}
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 1px;'>FLUX CASH</div>
            <table style='width: 100%; border-collapse: collapse;'>
                <tr><td>OUVERTURE:</td><td style='text-align: right;'>" . number_format($session->opening_amount, 0, ',', ' ') . "</td></tr>
                <tr><td>VENTES (+):</td><td style='text-align: right;'>" . number_format($session->cash_total, 0, ',', ' ') . "</td></tr>
                <tr><td>DEPENSES (-):</td><td style='text-align: right;'>" . number_format($session->total_expenses, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; border-top: 1px solid #000;'><td>THEORIQUE:</td><td style='text-align: right;'>" . number_format($session->expected_amount, 0, ',', ' ') . "</td></tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 4px; margin-bottom: 1px;'>REEL</div>
            <table style='width: 100%;'>
                <tr><td>BANQUE:</td><td style='text-align: right;'>" . number_format($session->amount_to_bank, 0, ',', ' ') . "</td></tr>
                <tr><td>CAISSE:</td><td style='text-align: right;'>" . number_format($session->remaining_amount, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; font-size: 11px; border-top: 1px solid #000;'>
                    <td>ECART:</td>
                    <td style='text-align: right;'>" . ($session->difference > 0 ? '+' : '') . number_format($session->difference, 0, ',', ' ') . "</td>
                </tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 4px; margin-bottom: 1px;'>AUTRES MODES</div>
            <table style='width: 100%; border-collapse: collapse;'>";
        
        foreach (['card' => 'CARTE', 'wave' => 'WAVE', 'moov' => 'MOOV', 'mixx' => 'MIXX', 'orange_money' => 'ORANGE', 'momo' => 'MOMO'] as $key => $label) {
            $val = $session->{$key . '_total'} ?? 0;
            if ($val > 0) {
                $html .= "<tr><td>{$label}:</td><td style='text-align: right;'>" . number_format($val, 0, ',', ' ') . "</td></tr>";
            }
        }

        $gozemVal = $session->gozem_orders_total ?? 0;
        if ($gozemVal > 0) {
            $html .= "<tr style='border-top: 1px dotted #000;'><td>DONT GOZEM:</td><td style='text-align: right;'>" . number_format($gozemVal, 0, ',', ' ') . "</td></tr>";
        }
        
        $cakeVal = $session->cake_orders_total ?? 0;
        if ($cakeVal > 0) {
            $html .= "<tr style='border-top: 1px dotted #000;'><td>DONT DIVERS:</td><td style='text-align: right;'>" . number_format($cakeVal, 0, ',', ' ') . "</td></tr>";
        }
        
        $html .= "</table>";

        $html .= "<div style='margin-top: 5px; border-top: 1px dashed #000; text-align: center; font-weight: bold; font-size: 8px;'>
                VISA RESPONSABLE • " . now()->format('d/m/y H:i') . "
            </div>
        </div>";

        return $html;
    }

    /**
     * Commande de gâteau - NOIR & BLANC
     */
    public function cakeOrderHtml($cakeOrder): string
    {
        $restaurant = $cakeOrder->restaurant;
        $deliveryDate = \Carbon\Carbon::parse($cakeOrder->delivery_date)->format('d/m/y');
        $deliveryTime = $cakeOrder->delivery_time ? " " . substr($cakeOrder->delivery_time, 0, 5) : '';

        $html = "
        <div style='font-family: \"DejaVu Sans\", sans-serif; width: 100%; font-size: 10px; color: #000; line-height: 1.1; background: #fff;'>
            <div style='text-align: center;'>
                <div style='font-size: 13px; font-weight: bold;'>" . strtoupper($restaurant->name) . "</div>
                <div style='font-weight: bold; border-top: 1px dashed #000; border-bottom: 1px dashed #000; margin: 3px 0; padding: 2px 0;'>
                    DIVERS #{$cakeOrder->order_number}
                </div>
            </div>
            
            <div style='margin: 4px 0; font-weight: bold;'>
                CLIENT: " . strtoupper($cakeOrder->customer_name) . "<br>
                TEL: {$cakeOrder->customer_phone} | LE: {$deliveryDate}{$deliveryTime}
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 3px;'>ARTICLES</div>";

        foreach ($cakeOrder->items as $item) {
            $html .= "
                <div style='font-weight: bold; margin-bottom: 2px;'>
                    <div style='display:flex; justify-content:space-between;'>
                        <span>x{$item['qty']} " . strtoupper($item['name']) . "</span>
                        <span>" . number_format($item['qty'] * $item['unit_price'], 0, ',', ' ') . "</span>
                    </div>";
            if (!empty($item['notes'])) {
                $html .= "<div style='font-size:10px; font-style:italic; padding-left: 10px;'>- " . $item['notes'] . "</div>";
            }
            $html .= "</div>";
        }

        $html .= "
            <div style='border-top: 1px dashed #000; margin-top: 5px; padding-top: 5px;'>
                <table style='width: 100%; font-weight: bold;'>
                    <tr><td>TOTAL:</td><td style='text-align: right;'>" . number_format($cakeOrder->total, 0, ',', ' ') . "</td></tr>
                    <tr><td>AVANCE:</td><td style='text-align: right;'>" . number_format($cakeOrder->advance_paid, 0, ',', ' ') . "</td></tr>
                    <tr style='font-size: 13px;'>
                        <td style='border: 1px solid #000; padding: 2px;'>RESTE:</td>
                        <td style='text-align: right; border: 1px solid #000; padding: 2px;'>" . number_format($cakeOrder->remaining_amount, 0, ',', ' ') . " FCFA</td>
                    </tr>
                </table>
            </div>

            <div style='text-align: center; margin-top: 15px;'>
                <div style='font-weight: bold; margin-bottom: 5px;'>Merci de votre confiance !</div>
                <div style='font-size: 9px; margin-top: 5px; font-weight: bold;'>" . now()->format('d/m/Y H:i') . "</div>
            </div>
        </div>";

        return $html;
    }

    /**
     * Facture A4 sécurisée (Correction erreur de chargement)
     */
    public function invoiceA4Html(Order $order): string
    {
        $restaurant = $order->restaurant;
        $customer = $order->customer_name ?: 'Client de Passage';
        $date = \Carbon\Carbon::parse($order->created_at)->format('d/m/Y');
        $time = \Carbon\Carbon::parse($order->created_at)->format('H:i');
        $typeLabel = $this->typeLabel($order->type);
        $waiter = $order->server_name ?: ($order->waiter?->first_name ?? 'N/A');

        $logoPath = public_path('img/logo.png');
        $logoHtml = '';
        if (file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoHtml = "<img src='data:image/png;base64,{$logoData}' alt='Logo' style='max-height: 60px; margin-bottom: 10px;' /><br>";
        }

        $html = "
        <div style='font-family: \"DejaVu Sans\", sans-serif; background: #fff; color: #000; padding: 20px 30px; min-height: 260mm; line-height: 1.2;'>

            <!-- EN-TÊTE -->
            <table style='width: 100%; margin-bottom: 15px;'>
                <tr>
                    <td style='vertical-align: top; width: 60%;'>
                        {$logoHtml}
                        <h1 style='margin: 0 0 4px 0; font-size: 18px; letter-spacing: 1px;'>" . strtoupper($restaurant->name) . "</h1>
                        <p style='margin: 4px 0; font-size: 13px;'>{$restaurant->address}</p>
                        <p style='margin: 4px 0; font-size: 13px;'>Tél : {$restaurant->phone}</p>
                        <p style='margin: 4px 0; font-size: 13px; font-weight: bold;'>IFU : 1001580865</p>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <h2 style='margin: 0 0 8px 0; font-size: 20px; letter-spacing: 2px;'>FACTURE</h2>
                        <p style='margin: 4px 0; font-size: 13px;'>N° : {$order->order_number}</p>
                        <p style='margin: 4px 0; font-size: 13px;'>Date : {$date} à {$time}</p>
                        <p style='margin: 4px 0; font-size: 13px;'>Type : {$typeLabel}</p>
                        <p style='margin: 4px 0; font-size: 13px;'>Serv : {$waiter}</p>
                    </td>
                </tr>
            </table>

            <div style='border-top: 2px solid #000; margin-bottom: 15px;'></div>

            <!-- CLIENT -->
            <div style='margin-bottom: 15px; font-size: 12px;'>
                <strong>DOIT À :</strong><br>
                <span style='font-size: 14px;'>" . strtoupper($customer) . "</span>" . ($order->customer_phone ? " — {$order->customer_phone}" : '') . "
            </div>

            <!-- TABLEAU DES ARTICLES -->
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 13px;'>
                <thead>
                    <tr style='background: #f0f0f0;'>
                        <th style='padding: 6px 8px; text-align: left; border: 1px solid #000;'>DÉSIGNATION</th>
                        <th style='padding: 6px 8px; text-align: center; border: 1px solid #000; width: 80px;'>QTÉ</th>
                        <th style='padding: 6px 8px; text-align: right; border: 1px solid #000; width: 120px;'>P.U</th>
                        <th style='padding: 6px 8px; text-align: right; border: 1px solid #000; width: 130px;'>TOTAL</th>
                    </tr>
                </thead>
                <tbody>";

        foreach ($order->items as $item) {
            if ($item->status === 'cancelled') continue;
            $lineTotal = $item->unit_price * $item->quantity;
            $html .= "
                    <tr>
                        <td style='padding: 4px 8px; border: 1px solid #000;'>" . strtoupper($item->product?->name ?? $item->notes ?? 'ARTICLE LIBRE') . "</td>
                        <td style='padding: 4px 8px; text-align: center; border: 1px solid #000;'>{$item->quantity}</td>
                        <td style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price, 0, ',', ' ') . "</td>
                        <td style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>" . number_format($lineTotal, 0, ',', ' ') . "</td>
                    </tr>";
        }

        $totalTtc = (float)($order->total ?? 0);
        $vatAmount = (float)($order->vat_amount ?? 0);
        $amountHt = max(0, $totalTtc - $vatAmount);

        $html .= "
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan='3' style='padding: 4px 8px; text-align: right; border: 1px solid #000;'><strong>MONTANT TOTAL HT</strong></td>
                        <td style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>" . number_format($amountHt, 0, ',', ' ') . "</td>
                    </tr>
                    " . (($order->discount_amount > 0 && $order->type !== 'gozem') ? "
                    <tr>
                        <td colspan='3' style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>
                            <strong>" . ($order->discount_reason ? $order->discount_reason : "REMISE") . 
                            ($order->discount_authorizer_name ? " - Par: {$order->discount_authorizer_name}" : "") . "</strong>
                        </td>
                        <td style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>- " . number_format((float)$order->discount_amount, 0, ',', ' ') . "</td>
                    </tr>" : "") . "
                    <tr>
                        <td colspan='3' style='padding: 4px 8px; text-align: right; border: 1px solid #000;'><strong>TVA COLLECTÉE (18%)</strong></td>
                        <td style='padding: 4px 8px; text-align: right; border: 1px solid #000;'>" . number_format($vatAmount, 0, ',', ' ') . "</td>
                    </tr>
                    <tr style='background: #f0f0f0;'>
                        <td colspan='3' style='padding: 6px 8px; text-align: right; border: 2px solid #000; font-size: 14px;'><strong>TOTAL NET TTC</strong></td>
                        <td style='padding: 6px 8px; text-align: right; border: 2px solid #000; font-size: 14px;'><strong>" . number_format($totalTtc, 0, ',', ' ') . " FCFA</strong></td>
                    </tr>
                </tfoot>
            </table>

            <!-- MODE DE PAIEMENT -->
            " . ($order->payments->count() > 0 ? "
            <div style='margin-bottom: 20px; font-size: 13px; font-weight: bold; padding: 10px 12px; border: 1px dashed #000;'>
                MODE DE PAIEMENT : " . $order->payments->map(fn($p) => strtoupper($this->methodLabel($p->method)))->implode(', ') . "
            </div>" : "") . "

            <!-- MONTANT DONNÉ / RENDU -->
            " . ($order->payments->sum('amount_given') > 0 ? "
            <table style='width: 300px; margin-left: auto; border-collapse: collapse; margin-bottom: 25px; font-size: 13px;'>
                <tr>
                    <td style='padding: 8px 12px; text-align: right; border: 1px solid #000;'>MONTANT DONNÉ</td>
                    <td style='padding: 8px 12px; text-align: right; border: 1px solid #000; width: 120px;'>" . number_format($order->payments->sum('amount_given'), 0, ',', ' ') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 12px; text-align: right; border: 1px solid #000;'>MONTANT RENDU</td>
                    <td style='padding: 8px 12px; text-align: right; border: 1px solid #000; width: 120px; font-weight: bold;'>" . number_format($order->payments->sum('change_given'), 0, ',', ' ') . "</td>
                </tr>
            </table>" : "") . "

            <!-- SIGNATURE -->
            <div style='margin-top: 60px;'>
                <div style='text-align: right; font-size: 13px;'>
                    <p style='margin: 0 0 40px 0;'>La Direction</p>
                    <p style='margin: 0;'>_________________________</p>
                </div>
                <div style='text-align: center; margin-top: 40px; font-size: 11px; color: #555; border-top: 1px solid #999; padding-top: 10px;'>
                    Merci pour votre confiance ! Certifié par CI GUSTA POS.
                </div>
            </div>
        </div>";

        return $html;
    }

    public function generateInvoiceA4Pdf(Order $order): string
    {
        $content = $this->invoiceA4Html($order);
        $html = "
        <html>
        <head>
        <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/>
        <style>body { font-family: 'DejaVu Sans', sans-serif; color: #000; margin: 0; padding: 20px; } .wrapper { width: 180mm; margin: 0 auto; }
        </style>
        </head>
        <body>
            <div class='wrapper'>
                {$content}
            </div>
        </body>
        </html>";
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    public function generateReceiptPdf(Order $order): string
    {
        $restaurant = $order->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceiptData($order, $restaurant, $config);
        
        $html = view('receipts.ticket', compact('receipt', 'restaurant', 'config'))->render();
        return Pdf::loadHTML($html)->setPaper([0, 0, 226.77, 800])->output();
    }

    /**
     * Génère le HTML A4 professionnel pour une commande de gâteau (Bon de commande si acompte, Facture Normalisée si solde régle)
     */
    public function cakeOrderA4Html(\App\Models\CakeOrder $cakeOrder, bool $forceInvoice = false): string
    {
        $restaurant = $cakeOrder->restaurant;
        $totalVal = (float)$cakeOrder->total;
        $advanceVal = (float)$cakeOrder->advance_paid;
        $remainingVal = (float)$cakeOrder->remaining_amount;

        $isPaid = $cakeOrder->is_paid || $remainingVal <= 0 || $forceInvoice;

        $total = number_format($totalVal, 0, ',', ' ');
        $advance = number_format($advanceVal, 0, ',', ' ');
        $remaining = number_format($remainingVal, 0, ',', ' ');
        $vatVal = round($totalVal * 0.18 / 1.18);
        $amountHt = max(0, $totalVal - $vatVal);
        $amountHtFmt = number_format($amountHt, 0, ',', ' ');
        $vat = number_format($vatVal, 0, ',', ' ');
        $date = \Carbon\Carbon::parse($cakeOrder->delivery_date)->locale('fr')->isoFormat('LL');
        $time = $cakeOrder->delivery_time ? substr($cakeOrder->delivery_time, 0, 5) : 'N/A';
        $ifu = $restaurant->vat_number ?? '1001580865';

        $logoPath = public_path('img/logo.png');
        $logoHtml = '';
        if (file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoHtml = "<img src='data:image/png;base64,{$logoData}' alt='Logo' style='max-height: 50px; margin-bottom: 5px;' /><br>";
        }

        // --- SI LA COMMANDE EST PAYÉE : GENERER FACTURE A4 ---
        if ($isPaid) {
            $paidDate = $cakeOrder->paid_at ? \Carbon\Carbon::parse($cakeOrder->paid_at)->format('d/m/Y à H:i') : date('d/m/Y à H:i');
            $paymentMethodLabel = strtoupper($cakeOrder->payment_method ?? 'ESPÈCES');

            return "
            <div style='font-family: \"DejaVu Sans\", sans-serif; background: #fff; color: #000; padding: 20px 30px; min-height: 260mm; line-height: 1.2; border: 1px solid #000;'>
                <!-- EN-TÊTE -->
                <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px;'>
                    <tr>
                        <td style='vertical-align: top; width: 60%;'>
                            {$logoHtml}
                            <div style='font-size: 18px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;'>{$restaurant->name}</div>
                            <div style='font-size: 11px; margin-top: 2px;'>{$restaurant->address}</div>
                            <div style='font-size: 11px;'>Tél : {$restaurant->phone}</div>
                            <div style='font-size: 11px; font-weight: bold; margin-top: 2px;'>IFU : {$ifu}</div>
                        </td>
                        <td style='text-align: right; vertical-align: top;'>
                            <div style='border: 2px solid #000; padding: 6px 14px; font-weight: bold; font-size: 14px; display: inline-block; background: #f0f0f0; margin-bottom: 8px;'>FACTURE</div>
                            <div style='font-size: 12px; font-weight: bold;'>N° FACTURE : {$cakeOrder->order_number}</div>
                            <div style='font-size: 11px; margin-top: 2px;'>Payée le : {$paidDate}</div>
                            <div style='font-size: 11px;'>Livraison prévue : {$date} à {$time}</div>
                        </td>
                    </tr>
                </table>

                <!-- CLIENT -->
                <div style='margin-bottom: 15px; font-size: 12px; border: 1px solid #000; padding: 10px;'>
                    <strong style='font-size: 10px; text-transform: uppercase;'>DOIT À (CLIENT) :</strong><br>
                    <span style='font-size: 14px; font-weight: bold;'>" . strtoupper($cakeOrder->customer_name) . "</span>" . ($cakeOrder->customer_phone ? " — Tél : {$cakeOrder->customer_phone}" : '') . "
                </div>

                <!-- TABLEAU DES ARTICLES -->
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px;'>
                    <thead>
                        <tr style='background: #f0f0f0;'>
                            <th style='padding: 8px; text-align: left; border: 1px solid #000; text-transform: uppercase;'>DÉSIGNATION</th>
                            <th style='padding: 8px; text-align: center; border: 1px solid #000; width: 70px; text-transform: uppercase;'>QTÉ</th>
                            <th style='padding: 8px; text-align: right; border: 1px solid #000; width: 110px; text-transform: uppercase;'>P.U</th>
                            <th style='padding: 8px; text-align: right; border: 1px solid #000; width: 120px; text-transform: uppercase;'>TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style='padding: 10px 8px; border: 1px solid #000; font-weight: bold;'>
                                COMMANDE DIVERS
                                " . ($cakeOrder->notes ? "<div style='font-size: 10px; font-weight: normal; font-style: italic; margin-top: 4px; color: #333;'>Personnalisation : " . nl2br($cakeOrder->notes) . "</div>" : "") . "
                            </td>
                            <td style='padding: 10px 8px; text-align: center; border: 1px solid #000;'>1</td>
                            <td style='padding: 10px 8px; text-align: right; border: 1px solid #000;'>{$total}</td>
                            <td style='padding: 10px 8px; text-align: right; border: 1px solid #000; font-weight: bold;'>{$total}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan='3' style='padding: 6px 8px; text-align: right; border: 1px solid #000;'><strong>MONTANT TOTAL HT</strong></td>
                            <td style='padding: 6px 8px; text-align: right; border: 1px solid #000;'>{$amountHtFmt} FCFA</td>
                        </tr>
                        <tr>
                            <td colspan='3' style='padding: 6px 8px; text-align: right; border: 1px solid #000;'><strong>TVA (18%)</strong></td>
                            <td style='padding: 6px 8px; text-align: right; border: 1px solid #000;'>{$vat} FCFA</td>
                        </tr>
                        <tr style='background: #f0f0f0;'>
                            <td colspan='3' style='padding: 8px; text-align: right; border: 2px solid #000; font-size: 14px;'><strong>TOTAL NET TTC</strong></td>
                            <td style='padding: 8px; text-align: right; border: 2px solid #000; font-size: 14px;'><strong>{$total} FCFA</strong></td>
                        </tr>
                    </tfoot>
                </table>
                </table>

                <!-- RÈGLEMENT & PAIEMENT -->
                <div style='margin-bottom: 20px; font-size: 12px; padding: 10px 12px; border: 1px dashed #000; background: #fafafa;'>
                    <strong>MODE DE PAIEMENT :</strong> {$paymentMethodLabel}<br>
                    <strong>STATUT DU PAIEMENT :</strong> INTEGRALEMENT PAYÉ (Reste à payer : 0 FCFA)
                </div>

                <!-- SIGNATURES & PIED DE PAGE -->
                <div style='margin-top: 40px; width: 100%;'>
                    <table style='width: 100%;'>
                        <tr>
                            <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 9px; font-weight: bold;'>Signature Client</td>
                            <td style='width: 20%; text-align: center;'></td>
                            <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 9px; font-weight: bold;'>Cachet & Direction</td>
                        </tr>
                    </table>
                </div>

                <div style='margin-top: 30px; text-align: center; font-size: 8px; font-weight: bold; border-top: 1px solid #000; padding-top: 8px;'>
                    Facture certifiée par Omega POS • Émise le " . now()->format('d/m/Y H:i') . "
                </div>
            </div>";
        }

        // --- SI NON PAYÉ / ACOMPTE : BON DE COMMANDE ---
        return "
        <div style='font-family: \"DejaVu Sans\", sans-serif; color: #000; padding: 15px; border: 1px solid #000; position: relative;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div style='font-size: 18px; font-weight: bold; text-transform: uppercase;'>{$restaurant->name}</div>
                        <div style='font-size: 9px; font-weight: bold; margin-top: 2px;'>
                            {$restaurant->address}<br>
                            Tél: {$restaurant->phone}<br>
                            IFU: {$ifu}
                        </div>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <div style='border: 2px solid #000; padding: 5px 10px; font-weight: bold; font-size: 11px; display: inline-block;'>BON DE COMMANDE</div>
                    </td>
                </tr>
            </table>

            <div style='font-size: 14px; font-weight: bold; margin-bottom: 2px;'>CONFIRMATION DE COMMANDE DIVERS</div>
            <div style='font-size: 10px; font-weight: bold; margin-bottom: 15px;'>Réf: {$cakeOrder->order_number} | Emis le " . date('d/m/Y à H:i') . "</div>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 15px;'>
                <tr>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Client</div>
                        <div style='font-size: 11px; font-weight: bold;'>{$cakeOrder->customer_name}</div>
                        <div style='font-size: 10px; font-weight: bold;'>Tél: {$cakeOrder->customer_phone}</div>
                    </td>
                    <td style='width: 4%;'></td>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Livraison Prévue</div>
                        <div style='font-size: 11px; font-weight: bold;'>{$date}</div>
                        <div style='font-size: 10px; font-weight: bold;'>à {$time}</div>
                    </td>
                </tr>
            </table>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 10px;'>
                <thead>
                    <tr>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: left; font-size: 9px; text-transform: uppercase;'>Description</th>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: center; font-size: 9px; text-transform: uppercase; width: 60px;'>Qté</th>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: right; font-size: 9px; text-transform: uppercase; width: 100px;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; font-weight: bold;'>Commande Divers</td>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; text-align: center;'>1</td>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; text-align: right; font-weight: bold;'>{$total} FCFA</td>
                    </tr>
                </tbody>
            </table>

            <div style='border: 1px solid #000; padding: 8px; margin-bottom: 15px;'>
                <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;'>Détails & Personnalisation</div>
                <div style='font-size: 10px; font-weight: bold;'>" . nl2br($cakeOrder->notes ?: 'Gâteau personnalisé.') . "</div>
            </div>

            <table style='width: 250px; margin-left: auto; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right; padding-right: 15px;'>Sous-total</td>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right;'>{$total} FCFA</td>
                </tr>
                <tr>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right; padding-right: 15px;'>Acompte</td>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right;'>- {$advance} FCFA</td>
                </tr>
                <tr style='background: #000; color: #fff;'>
                    <td style='padding: 8px 15px; font-size: 12px; font-weight: bold; text-align: right;'>RESTE À PAYER</td>
                    <td style='padding: 8px 10px; font-size: 12px; font-weight: bold; text-align: right;'>{$remaining} FCFA</td>
                </tr>
            </table>

            <div style='margin-top: 20px; width: 100%;'>
                <table style='width: 100%;'>
                    <tr>
                        <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 8px; font-weight: bold;'>Signature Client</td>
                        <td style='width: 20%; text-align: center;'></td>
                        <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 8px; font-weight: bold;'>Cachet Établissement</td>
                    </tr>
                </table>
            </div>

            <div style='margin-top: 10px; text-align: center; font-size: 7px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px;'>
                Document certifié par Omega POS • " . now()->format('d/m/y H:i') . "
            </div>
        </div>";
    }

    /**
     * Génère un PDF A4 avec 2 copies pour une commande de gâteau
     */
    public function generateCakeOrderA4Pdf(\App\Models\CakeOrder $cakeOrder): string
    {
        $content = $this->cakeOrderA4Html($cakeOrder);
        $html = "<html><head><style>
            @page { margin: 10mm; }
            body { margin: 0; padding: 0; background: #fff; }
            .copy-wrapper { width: 100%; }
        </style></head><body>
            <div class='copy-wrapper'>{$content}</div>
        </body></html>";
        
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * Génère le HTML A4 professionnel pour un reçu de dépense
     */
    public function expenseA4Html(Expense $expense): string
    {
        $restaurant = $expense->restaurant;
        $id = str_pad($expense->id, 6, '0', STR_PAD_LEFT);
        $date = \Carbon\Carbon::parse($expense->created_at)->format('d/m/Y à H:i');
        $amount = number_format((float)$expense->amount, 0, ',', ' ');

        return "
        <div style='font-family: \"DejaVu Sans\", sans-serif; color: #000; padding: 15px; border: 1px solid #000; position: relative;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div style='font-size: 18px; font-weight: bold; text-transform: uppercase;'>{$restaurant->name}</div>
                        <div style='font-size: 9px; font-weight: bold; margin-top: 2px;'>
                            {$restaurant->address}<br>
                            Tél: {$restaurant->phone}
                        </div>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <div style='border: 2px solid #000; padding: 5px 10px; font-weight: bold; font-size: 11px; display: inline-block;'>PIÈCE COMPTABLE</div>
                    </td>
                </tr>
            </table>

            <div style='font-size: 14px; font-weight: bold; margin-bottom: 2px;'>JUSTIFICATIF DE SORTIE DE CAISSE</div>
            <div style='font-size: 10px; font-weight: bold; margin-bottom: 15px;'>Réf: EXP-{$id} | Émis le {$date}</div>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 15px;'>
                <tr>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Bénéficiaire</div>
                        <div style='font-size: 11px; font-weight: bold;'>" . ($expense->beneficiary ?: 'Non spécifié') . "</div>
                    </td>
                    <td style='width: 4%;'></td>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Agent / Coursier</div>
                        <div style='font-size: 11px; font-weight: bold;'>" . ($expense->agent_name ?: 'Non spécifié') . "</div>
                    </td>
                </tr>
            </table>

            <div style='border: 1px solid #000; padding: 10px; margin-bottom: 15px;'>
                <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Motif de la dépense</div>
                <div style='font-size: 12px; font-weight: bold;'>" . strtoupper($expense->description) . "</div>
                <div style='font-size: 9px; color: #333; margin-top: 4px;'>Catégorie: " . strtoupper($expense->category) . "</div>
            </div>

            <div style='background: #000; color: #fff; padding: 15px; text-align: center; margin-bottom: 20px;'>
                <div style='font-size: 10px; text-transform: uppercase; margin-bottom: 5px;'>Montant Total Décaissé</div>
                <div style='font-size: 24px; font-weight: bold;'>{$amount} FCFA</div>
            </div>

            <table style='width: 100%; margin-top: 20px;'>
                <tr>
                    <td style='width: 45%; border-top: 1px dashed #000; padding-top: 8px; text-align: center;'>
                        <div style='font-size: 9px; font-weight: bold; text-transform: uppercase;'>Signature de l'Agent</div>
                        <br><br>
                    </td>
                    <td style='width: 10%;'></td>
                    <td style='width: 45%; border-top: 1px dashed #000; padding-top: 8px; text-align: center;'>
                        <div style='font-size: 9px; font-weight: bold; text-transform: uppercase;'>Validation Direction</div>
                        <br><br>
                    </td>
                </tr>
            </table>

            <div style='margin-top: 15px; text-align: center; font-size: 7px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px;'>
                Établi par: " . ($expense->user->first_name ?? 'Inconnu') . " • " . now()->format('d/m/y H:i') . "
            </div>
        </div>";
    }

    /**
     * Génère un PDF A4 avec 2 copies pour un reçu de dépense
     */
    public function generateExpenseA4Pdf(Expense $expense): string
    {
        $content = $this->expenseA4Html($expense);
        $html = "<html><head><style>
            @page { margin: 10mm; }
            body { margin: 0; padding: 0; background: #fff; }
            .copy-wrapper { width: 100%; }
        </style></head><body>
            <div class='copy-wrapper'>{$content}</div>
        </body></html>";
        
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }
    public function generateBulkInvoiceA4Pdf($orders): string
    {
        $html = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/><style>
            body { margin: 0; padding: 10mm; font-family: 'DejaVu Sans', sans-serif; background: #fff; }
            .invoice-outer { width: 100%; margin-bottom: 20px; }
            .invoice-inner { width: 180mm; margin: 0 auto; }
            .divider { border-bottom: 1px dashed #000; margin: 15px 0; width: 180mm; margin-left: auto; margin-right: auto; }
        </style></head><body>";
        
        $count = 0;
        foreach ($orders as $order) {
            $html .= "<div class='invoice-outer'><div class='invoice-inner'>";
            $html .= $this->invoiceA4Html($order);
            $html .= "</div>";
            if ($count < count($orders) - 1) {
                $html .= "<div class='divider'></div>";
            }
            $html .= "</div>";
            $count++;
        }
        
        $html .= "</body></html>";
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    public function generateKitchenTicketPdf(Order $order, string $destination = 'kitchen', ?array $itemIds = null): string
    {
        $html = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/></head><body>" . $this->kitchenTicketHtml($order, $destination, $itemIds) . "</body></html>";
        return Pdf::loadHTML($html)->setPaper([0, 0, 226.77, 600])->output();
    }

    /**
     * Facture A4 pour une ardoise client (CustomerTab)
     */
    public function tabInvoiceA4Html(\App\Models\CustomerTab $tab): string
    {
        $restaurant = $tab->restaurant;
        $customer = $tab->full_name;
        $date = now()->format('d/m/Y');
        $tab->load('orders.items.product');

        $html = "
        <div style='padding: 15px; border: 1px solid #000; font-family: Arial, sans-serif; background: #fff; color: #000;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;'>
                <tr>
                    <td>
                        <h1 style='margin: 0; font-size: 22px;'>" . strtoupper($restaurant->name) . "</h1>
                        <p style='margin: 2px 0; font-size: 11px;'>{$restaurant->address}</p>
                        <p style='margin: 2px 0; font-size: 11px;'>Tél: {$restaurant->phone}</p>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <h2 style='margin: 0; font-size: 18px;'>RELEVÉ D'ARDOISE</h2>
                        <p style='margin: 2px 0; font-size: 11px;'>Client: " . strtoupper($customer) . "</p>
                        <p style='margin: 2px 0; font-size: 11px;'>Date: {$date}</p>
                    </td>
                </tr>
            </table>

            <div style='margin: 10px 0; font-size: 12px; font-weight: bold;'>
                DÉTAIL DES CONSOMMATIONS
            </div>

            <table style='width: 100%; border-collapse: collapse; font-size: 11px;'>
                <thead>
                    <tr style='background: #f0f0f0; border: 1px solid #000;'>
                        <th style='padding: 6px; text-align: left; border: 1px solid #000;'>DESIGNATION</th>
                        <th style='padding: 6px; text-align: center; border: 1px solid #000;'>QTE</th>
                        <th style='padding: 6px; text-align: right; border: 1px solid #000;'>P.U</th>
                        <th style='padding: 6px; text-align: right; border: 1px solid #000;'>TOTAL</th>
                    </tr>
                </thead>
                <tbody>";

        $grandTotal = 0;
        foreach ($tab->orders as $order) {
            if ($order->status === 'cancelled') continue;
            foreach ($order->items as $item) {
                if ($item->status === 'cancelled') continue;
                $lineTotal = $item->unit_price * $item->quantity;
                $grandTotal += $lineTotal;
                $html .= "
                <tr>
                    <td style='padding: 5px; border: 1px solid #000;'>" . strtoupper($item->product?->name ?? $item->notes ?? 'ARTICLE LIBRE') . "</td>
                    <td style='padding: 5px; text-align: center; border: 1px solid #000;'>{$item->quantity}</td>
                    <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price, 0, ',', ' ') . "</td>
                    <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format($lineTotal, 0, ',', ' ') . "</td>
                </tr>";
            }
        }

        $paid = (float)$tab->paid_amount;
        $remaining = max(0, $grandTotal - $paid);

        $html .= "
                </tbody>
                <tfoot>
                    <tr style='font-weight: bold;'>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'>TOTAL GÉNÉRAL</td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($grandTotal, 0, ',', ' ') . " FCFA</td>
                    </tr>
                    <tr>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'>DÉJÀ PAYÉ</td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($paid, 0, ',', ' ') . " FCFA</td>
                    </tr>
                    <tr style='background: #000; color: #fff;'>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'>RESTE À PAYER</td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000; font-size: 14px;'><strong>" . number_format($remaining, 0, ',', ' ') . " FCFA</strong></td>
                    </tr>
                </tfoot>
            </table>

            <div style='margin-top: 20px; text-align: center;'>
                <p style='font-size: 11px; margin-bottom: 10px;'>Veuillez solder votre ardoise dans les plus brefs délais. Merci !</p>
                <p style='font-size: 8px; font-weight: bold; margin-top: 5px;'>Généré le " . now()->format('d/m/Y H:i') . "</p>
            </div>
        </div>";

        return $html;
    }

    public function generateTabInvoicePdf(\App\Models\CustomerTab $tab): string
    {
        $content = $this->tabInvoiceA4Html($tab);
        $html = "<html><head><style>body { font-family: Arial, sans-serif; color: #000; margin: 0; padding: 20px; } .wrapper { width: 180mm; margin: 0 auto; }</style></head><body><div class='wrapper'>{$content}</div></body></html>";
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    private function methodLabel(string $method): string { return match($method) { 'cash' => 'Espèces', 'card' => 'Carte', 'wave' => 'Wave', 'moov' => 'Moov', 'mixx' => 'Mixx', 'orange_money' => 'Orange', 'momo' => 'Momo', default => ucfirst($method) }; }
    private function typeLabel(string $type): string { 
        return match($type) { 
            'dine_in' => 'Sur place', 
            'takeaway' => 'À emporter', 
            'delivery' => 'Livraison', 
            'gozem' => 'Gozem',
            'cakes' => 'Gâteaux',
            default => $type 
        }; 
    }
}
