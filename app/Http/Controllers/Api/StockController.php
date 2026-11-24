<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->alert, fn($q) => $q->whereColumn('quantity', '<=', 'min_quantity'))
            ->where('active', true)->orderBy('name')->paginate(30);
        return response()->json($ingredients);
    }

    public function alerts(Request $request)
    {
        $alerts = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->whereColumn('quantity', '<=', 'min_quantity')->where('active', true)
            ->orderByRaw('quantity / NULLIF(min_quantity, 0)')->get()
            ->map(function ($i) { $i->level = $i->quantity <= 0 ? 'rupture' : 'faible'; return $i; });
        return response()->json($alerts);
    }

    public function createMovement(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'type'          => 'required|in:in,out,adjustment,waste,return',
            'quantity'      => 'required|numeric|min:0.001',
            'reason'        => 'nullable|string',
            'reference'     => 'nullable|string',
            'unit_cost'     => 'nullable|numeric|min:0',
        ]);

        $ingredient = Ingredient::findOrFail($request->ingredient_id);
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $quantityBefore = $ingredient->quantity;

        DB::transaction(function () use ($request, $ingredient, $quantityBefore) {
            $delta = in_array($request->type, ['in', 'return', 'adjustment']) ? $request->quantity : -$request->quantity;
            $quantityAfter = max(0, $quantityBefore + $delta);

            if ($request->type === 'adjustment') {
                $quantityAfter = $request->quantity;
            }

            StockMovement::create([
                'restaurant_id'   => $request->user()->restaurant_id,
                'ingredient_id'   => $ingredient->id,
                'user_id'         => $request->user()->id,
                'type'            => $request->type,
                'quantity'        => $request->quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after'  => $quantityAfter,
                'unit_cost'       => $request->unit_cost,
                'reason'          => $request->reason,
                'reference'       => $request->reference,
            ]);

            $ingredient->update(['quantity' => $quantityAfter]);
            $ingredient->logActivity('stock_updated', "Mouvement stock ({$request->type}): {$request->quantity} {$ingredient->unit} pour {$ingredient->name}");
        });

        return response()->json($ingredient->fresh(), 201);
    }

    public function movements(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $movements = $ingredient->movements()->with('user:id,first_name,last_name')
            ->when($request->from, fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn($q) => $q->where('created_at', '<=', $request->to))
            ->latest()->paginate(50);
        return response()->json($movements);
    }

    public function value(Request $request)
    {
        $value = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)->sum(DB::raw('quantity * cost_per_unit'));
        return response()->json(['total_value' => round($value, 2)]);
    }

    public function saveRecipe(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'product_id'   => 'required|exists:products,id',
            'ingredients'  => 'required|array|min:1',
            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'ingredients.*.quantity'      => 'required|numeric|min:0.001',
        ]);

        Recipe::where('product_id', $request->product_id)->delete();

        $recipes = [];
        foreach ($request->ingredients as $item) {
            $recipes[] = Recipe::create([
                'product_id'    => $request->product_id,
                'ingredient_id' => $item['ingredient_id'],
                'quantity'      => $item['quantity'],
            ]);
        }
        return response()->json($recipes, 201);
    }

    public function getRecipe(Request $request, int $productId)
    {
        $recipe = Recipe::with('ingredient')->where('product_id', $productId)->get();
        return response()->json($recipe);
    }

    /**
     * Générer le rapport PDF A4 du stock (Ce qui reste & Ce qui manque)
     */
    public function reportPdf(Request $request)
    {
        try {
            $user = $request->user();
            $restaurantId = $user?->restaurant_id;
            
            $restaurant = $user?->restaurant 
                ?? ($restaurantId ? \App\Models\Restaurant::find($restaurantId) : null) 
                ?? \App\Models\Restaurant::first();
                
            $restaurantId = $restaurantId ?? $restaurant?->id;

            // 1. Ingrédients du stock
            $queryIng = Ingredient::query()->where('active', true);
            if ($restaurantId) {
                $queryIng->where('restaurant_id', $restaurantId);
            }
            $ingredients = $queryIng->orderBy('category')->orderBy('name')->get()->map(function($i) {
                $i->is_product = false;
                return $i;
            });

            // 2. Produits de vente avec suivi du stock activé (crochés en stock)
            $queryProd = \App\Models\Product::with('category')
                ->where('active', true)
                ->where(function($q) {
                    $q->where('track_stock', true)
                      ->orWhere('track_stock', 1)
                      ->orWhere('track_stock', '1')
                      ->orWhere('track_stock', 'true');
                });

            if ($restaurantId) {
                $queryProd->where('restaurant_id', $restaurantId);
            }
            $productsInStock = $queryProd->get()->map(function($p) {
                return (object)[
                    'id'            => 'p_' . $p->id,
                    'name'          => $p->name . ' [Produit Vente]',
                    'category'      => $p->category?->name ?? 'Produits Vente',
                    'unit'          => $p->unit ?? 'pièce',
                    'quantity'      => (float)($p->quantity ?? 0),
                    'min_quantity'  => (float)($p->min_quantity ?? 0),
                    'cost_per_unit' => (float)($p->cost_price ?: $p->price),
                    'is_product'    => true,
                ];
            });

            // Fusionner tous les articles gérés en stock (Ingrédients + Produits Vente)
            $allItems = $ingredients->concat($productsInStock)->sortBy(fn($item) => strtolower($item->name))->values();

            $alerts = $allItems->filter(fn($i) => (float)$i->quantity <= (float)$i->min_quantity);
            $totalValue = $allItems->sum(fn($i) => (float)$i->quantity * (float)$i->cost_per_unit);
            $totalItems = $allItems->count();
            $userName = $user ? trim("{$user->first_name} {$user->last_name}") : 'Gestionnaire POS';

            $html = view('reports.stock-pdf', [
                'restaurant'  => $restaurant,
                'ingredients' => $allItems,
                'alerts'      => $alerts,
                'totalValue'  => $totalValue,
                'totalItems'  => $totalItems,
                'userName'    => $userName,
            ])->render();

            $pdfContent = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', true)
                ->output();

            $filename = "Rapport_Stock_" . date('Y-m-d') . ".pdf";

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Stock PDF Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error' => true,
                'message' => 'Erreur lors de la génération du PDF du stock: ' . $e->getMessage()
            ], 500);
        }
    }
}
