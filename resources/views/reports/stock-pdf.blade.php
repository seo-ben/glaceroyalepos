<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 10px; line-height: 1.4; background: #fff; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px; }
        .company-name { font-size: 18px; font-weight: bold; text-transform: uppercase; color: #0f172a; }
        .report-title { font-size: 14px; color: #dc2626; font-weight: bold; margin-top: 3px; text-transform: uppercase; }
        .meta { color: #64748b; font-size: 9px; margin-top: 4px; }
        
        .summary-grid { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .summary-box { background: #f8fafc; padding: 10px; border-radius: 6px; text-align: center; border: 1px solid #e2e8f0; }
        .summary-label { font-size: 8px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }
        .summary-value { font-size: 14px; color: #0f172a; font-weight: bold; }
        .summary-alert { color: #dc2626; }

        .section-header { background: #0f172a; color: #ffffff; padding: 6px 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; margin-top: 20px; margin-bottom: 8px; border-radius: 4px; }
        .section-header-alert { background: #dc2626; color: #ffffff; }

        .table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .table th { background: #f1f5f9; padding: 4px 6px; text-align: left; font-size: 7.5px; text-transform: uppercase; color: #475569; border: 1px solid #cbd5e1; }
        .table td { padding: 3.5px 5px; border: 1px solid #e2e8f0; vertical-align: middle; font-size: 8.5px; }
        .table tr { page-break-inside: avoid; }
        
        .badge { padding: 3px 6px; font-size: 8px; font-weight: bold; text-transform: uppercase; border-radius: 4px; display: inline-block; }
        .badge-rupture { background: #fee2e2; color: #991b1b; }
        .badge-faible { background: #ffedd5; color: #9a3412; }
        .badge-ok { background: #dcfce7; color: #166534; }

        .footer { margin-top: 30px; border-top: 1px solid #cbd5e1; padding-top: 10px; text-align: center; color: #64748b; font-size: 8px; }
        .sig-table { width: 100%; margin-top: 30px; }
        .sig-box { border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 8px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td width="65%">
                    <div class="company-name">{{ $restaurant->name ?? 'CIGUSTA / OMEGA POS' }}</div>
                    <div class="meta">
                        {{ $restaurant->address ?? 'Bénin' }}<br>
                        Tél : {{ $restaurant->phone ?? '-' }} | IFU : {{ $restaurant->vat_number ?? '1001580865' }}
                    </div>
                    <div class="report-title">RAPPORT DU STOCK & ÉTAT DES MANQUANTS</div>
                </td>
                <td width="35%" align="right">
                    <div class="meta" style="font-size: 9px; font-weight: bold;">
                        Date du Rapport : {{ now()->format('d/m/Y') }}<br>
                        Heure d'édition : {{ now()->format('H:i') }}<br>
                        Édité par : {{ $userName ?? 'Gestionnaire POS' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- SYNTHÈSE -->
    <table class="summary-grid">
        <tr>
            <td width="32%">
                <div class="summary-box">
                    <div class="summary-label">Total Ingrédients / Produits</div>
                    <div class="summary-value">{{ $totalItems }}</div>
                </div>
            </td>
            <td width="2%"></td>
            <td width="32%">
                <div class="summary-box">
                    <div class="summary-label">Produits en Rupture / Stock Critique</div>
                    <div class="summary-value summary-alert">{{ $alerts->count() }}</div>
                </div>
            </td>
            <td width="2%"></td>
            <td width="32%">
                <div class="summary-box">
                    <div class="summary-label">Valeur Totale du Stock</div>
                    <div class="summary-value">{{ number_format($totalValue, 0, ',', ' ') }} FCFA</div>
                </div>
            </td>
        </tr>
    </table>

    <!-- CE QUI MANQUE / RUPTURES ET ALERTES -->
    @if($alerts->count() > 0)
    <div class="section-header section-header-alert">
        PRODUITS & INGRÉDIENTS À RÉAPPROVISIONNER (CE QUI MANQUE)
    </div>
    <table class="table">
        <thead>
            <tr>
                <th width="35%">Produit / Ingrédient</th>
                <th width="15%" style="text-align: center;">Unité</th>
                <th width="15%" style="text-align: right;">Stock Restant</th>
                <th width="15%" style="text-align: right;">Seuil Alerte</th>
                <th width="20%" style="text-align: right;">Manquant à Commander</th>
            </tr>
        </thead>
        <tbody>
            @foreach($alerts as $item)
            @php
                $shortage = max(0, (float)$item->min_quantity - (float)$item->quantity);
            @endphp
            <tr style="background: #fff5f5;">
                <td style="font-weight: bold;">{{ $item->name }}</td>
                <td style="text-align: center;">{{ $item->unit ?? 'g' }}</td>
                <td style="text-align: right; font-weight: bold; color: #dc2626;">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                <td style="text-align: right;">{{ number_format($item->min_quantity, 2, ',', ' ') }}</td>
                <td style="text-align: right; font-weight: bold; color: #dc2626;">
                    + {{ number_format($shortage, 2, ',', ' ') }} {{ $item->unit ?? 'g' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- CE QUI RESTE / INVENTAIRE GLOBAL -->
    <div class="section-header">
        INVENTAIRE COMPLET DU STOCK (CE QUI RESTE)
    </div>
    <table class="table">
        <thead>
            <tr>
                <th width="30%">Produit / Ingrédient</th>
                <th width="15%">Catégorie</th>
                <th width="15%" style="text-align: right;">Stock Restant</th>
                <th width="15%" style="text-align: right;">Seuil Min</th>
                <th width="12%" style="text-align: right;">Coût Unit (FCFA)</th>
                <th width="13%" style="text-align: center;">Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ingredients as $item)
            @php
                $level = $item->quantity <= 0 ? 'rupture' : ($item->quantity <= $item->min_quantity ? 'faible' : 'ok');
            @endphp
            <tr>
                <td style="font-weight: bold;">{{ $item->name }}</td>
                <td style="color: #64748b;">{{ $item->category ?? 'Général' }}</td>
                <td style="text-align: right; font-weight: bold;">
                    {{ number_format($item->quantity, 2, ',', ' ') }} <span style="font-size: 7px; color: #64748b;">{{ $item->unit ?? 'g' }}</span>
                </td>
                <td style="text-align: right; color: #64748b;">
                    {{ number_format($item->min_quantity, 2, ',', ' ') }} {{ $item->unit ?? 'g' }}
                </td>
                <td style="text-align: right;">
                    {{ number_format($item->cost_per_unit, 0, ',', ' ') }}
                </td>
                <td style="text-align: center;">
                    @if($level === 'rupture')
                        <span class="badge badge-rupture">Rupture</span>
                    @elseif($level === 'faible')
                        <span class="badge badge-faible">Stock Faible</span>
                    @else
                        <span class="badge badge-ok">Stock OK</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="sig-table">
        <tr>
            <td width="40%" class="sig-box">Signature Responsable Stock</td>
            <td width="20%"></td>
            <td width="40%" class="sig-box">Cachet & Direction</td>
        </tr>
    </table>

    <div class="footer">
        Fiche d'inventaire certifiée par Omega POS • Générée le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
