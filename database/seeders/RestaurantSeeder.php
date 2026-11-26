<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\Floor;
use App\Models\Table;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\CustomerTab;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les clés étrangères pour permettre de vider les tables
        Schema::disableForeignKeyConstraints();

        $tables = [
            'restaurants',
            'roles',
            'users',
            'floors',
            'tables',
            'categories',
            'products',
            'ingredients',
            'recipes',
            'stock_movements',
            'orders',
            'order_items',
            'payments',
            'cash_sessions',
            'customer_tabs',
            'cake_orders',
            'expenses'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        // 1. Création du Restaurant principal
        $restaurant = Restaurant::create([
            'id'       => 1,
            'name'     => 'GLACE ROYALE - CETEF (GRC)',
            'slug'     => 'glace-royale-cetef',
            'address'  => 'CETEF, Lomé, Togo',
            'phone'    => '+228 90 00 00 00',
            'email'    => 'contact@glaceroyalpos.net',
            'logo'     => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e3/Ice_cream_icon.svg/512px-Ice_cream_icon.svg.png',
            'currency' => 'XOF',
            'timezone' => 'Africa/Lome',
            'settings' => [
                'receipt_footer'     => 'Merci pour votre confiance ! GLACE ROYALE - CETEF.',
                'receipt_width'      => '58mm',
                'default_vat_rate'   => 1,
                'auto_print_receipt' => true,
                'currency_symbol'    => 'FCFA',
                'currency_position'  => 'after',
            ],
        ]);

        // 2. Création des Rôles
        $roles = [
            ['name' => 'admin',   'display_name' => 'Administrateur', 'permissions' => ['*'], 'is_system' => true],
            ['name' => 'manager', 'display_name' => 'Manager',        'permissions' => ['orders.*', 'payments.*', 'stock.*', 'reports.*', 'users.view', 'cancellations.*'], 'is_system' => true],
            ['name' => 'cashier', 'display_name' => 'Caissier',       'permissions' => ['orders.view', 'payments.create', 'cash_sessions.*'], 'is_system' => true],
            ['name' => 'waiter',  'display_name' => 'Serveur',        'permissions' => ['orders.create', 'orders.update', 'tables.view'], 'is_system' => true],
            ['name' => 'cook',    'display_name' => 'Cuisinier',      'permissions' => ['kitchen.*'], 'is_system' => true],
        ];

        $createdRoles = [];
        foreach ($roles as $role) {
            $createdRoles[$role['name']] = Role::create([
                'restaurant_id' => $restaurant->id,
                'name' => $role['name'],
                'display_name' => $role['display_name'],
                'permissions' => $role['permissions'],
                'is_system' => $role['is_system']
            ]);
        }

        // 3. Création des Utilisateurs
        $users = [
            ['first_name' => 'Super', 'last_name' => 'Admin', 'email' => 'admin@omega.tg',   'pin' => '0000', 'role' => 'admin'],
            ['first_name' => 'Caisse', 'last_name' => 'Un', 'email' => 'caisse1@omega.tg', 'pin' => '1111', 'role' => 'cashier'],
        ];

        foreach ($users as $u) {
            User::create([
                'restaurant_id' => $restaurant->id,
                'role_id'       => $createdRoles[$u['role']]->id,
                'first_name'    => $u['first_name'],
                'last_name'     => $u['last_name'],
                'email'         => $u['email'],
                'password'      => Hash::make('password'),
                'pin'           => Hash::make($u['pin']),
                'active'        => true,
            ]);
        }

        // 4. Salle et Tables (Grille 10x10)
        $floor = Floor::create(['restaurant_id' => $restaurant->id, 'name' => 'Salle principale', 'order' => 1]);
        for ($i = 1; $i <= 100; $i++) {
            Table::create([
                'floor_id'   => $floor->id,
                'number'     => (string) $i,
                'capacity'   => ($i % 4 === 0) ? 6 : 4,
                'position_x' => (($i - 1) % 10) * 140 + 50,
                'position_y' => floor(($i - 1) / 10) * 120 + 50,
                'shape'      => ($i % 10 === 0) ? 'round' : 'rectangle',
                'active'     => true,
            ]);
        }

        // 5. Catégories GLACE ROYALE - CETEF (GRC) avec Destinations (bar = Glaces, kitchen = Cuisine)
        $categories = [
            ['name' => 'Glacier 🍦',           'dest' => 'bar',     'color' => '#0EA5E9'],
            ['name' => 'Beignets & Snacks 🍩', 'dest' => 'kitchen', 'color' => '#F59E0B'],
            ['name' => 'Riz Cantonais 🍛',     'dest' => 'kitchen', 'color' => '#EF4444'],
            ['name' => 'Akpan / Ablo 🥣',      'dest' => 'kitchen', 'color' => '#10B981'],
            ['name' => 'Frites / Koliko 🍟',   'dest' => 'kitchen', 'color' => '#F97316'],
            ['name' => 'Spaghetti 🍝',         'dest' => 'kitchen', 'color' => '#8B5CF6'],
            ['name' => 'Boissons 🥤',          'dest' => 'bar',     'color' => '#3B82F6'],
        ];

        $createdCats = [];
        foreach ($categories as $i => $cat) {
            $createdCats[$cat['name']] = Category::create([
                'restaurant_id' => $restaurant->id,
                'name'          => $cat['name'],
                'destination'   => $cat['dest'],
                'color'         => $cat['color'],
                'order'         => $i,
                'active'        => true
            ]);
        }

        // 6. Produits GLACE ROYALE - CETEF (GRC)
        $productsData = [
            'Glacier 🍦' => [
                ['name' => 'Vanille (500 F)',        'price' => 500,  'emoji' => '🍦'],
                ['name' => 'Vanille (1 000 F)',      'price' => 1000, 'emoji' => '🍦'],
                ['name' => 'Chocolat (500 F)',       'price' => 500,  'emoji' => '🍫'],
                ['name' => 'Chocolat (1 000 F)',     'price' => 1000, 'emoji' => '🍫'],
                ['name' => 'Fraise (500 F)',         'price' => 500,  'emoji' => '🍓'],
                ['name' => 'Fraise (1 000 F)',       'price' => 1000, 'emoji' => '🍓'],
                ['name' => 'Oréo (500 F)',           'price' => 500,  'emoji' => '🍪'],
                ['name' => 'Oréo (1 000 F)',         'price' => 1000, 'emoji' => '🍪'],
                ['name' => 'Lotus Menthe',           'price' => 1000, 'emoji' => '🌿'],
                ['name' => 'Speculos Lotus',         'price' => 1000, 'emoji' => '🍪'],
                ['name' => 'Caramel',                'price' => 1000, 'emoji' => '🍮'],
                ['name' => 'Mangue',                 'price' => 1000, 'emoji' => '🥭'],
                ['name' => 'Passion',                'price' => 1000, 'emoji' => '🍈'],
                ['name' => 'Stracciatella (500 F)',  'price' => 500,  'emoji' => '🍨'],
                ['name' => 'Stracciatella (1 000 F)','price' => 1000, 'emoji' => '🍨'],
                ['name' => 'Bubble Tea',             'price' => 1200, 'emoji' => '🧋'],
                ['name' => 'Slasch (500 F)',         'price' => 500,  'emoji' => '🧊'],
                ['name' => 'Slasch (1 000 F)',       'price' => 1000, 'emoji' => '🧊'],
            ],
            'Beignets & Snacks 🍩' => [
                ['name' => '6 Beignets / Gawou', 'price' => 1000, 'emoji' => '🍩'],
            ],
            'Riz Cantonais 🍛' => [
                ['name' => 'Riz cantonais + 1 cuisse de poulet local', 'price' => 2000, 'emoji' => '🍗'],
                ['name' => 'Riz cantonais + 6 morceaux de viande',     'price' => 1500, 'emoji' => '🥩'],
                ['name' => 'Riz cantonais + 1 morceau de poisson',     'price' => 1500, 'emoji' => '🐟'],
            ],
            'Akpan / Ablo 🥣' => [
                ['name' => 'Akpan / Ablo + 1 morceau de poisson',    'price' => 1500, 'emoji' => '🐟'],
                ['name' => "Akpan / Ablo + 2 morceaux d'Adokougbi",  'price' => 1500, 'emoji' => '🍲'],
            ],
            'Frites / Koliko 🍟' => [
                ['name' => 'Koliko + 2 saucisses', 'price' => 1000, 'emoji' => '🌭'],
                ['name' => 'Frites + 4 saucisses', 'price' => 2000, 'emoji' => '🍟'],
            ],
            'Spaghetti 🍝' => [
                ['name' => 'Spaghetti + viande + 1 saucisse',         'price' => 1500, 'emoji' => '🍝'],
                ['name' => 'Spaghetti + viande + 2 saucisses + 1 œuf','price' => 2000, 'emoji' => '🍳'],
            ],
        ];

        foreach ($productsData as $catName => $prods) {
            foreach ($prods as $p) {
                Product::create([
                    'restaurant_id' => $restaurant->id,
                    'category_id'   => $createdCats[$catName]->id,
                    'name'          => $p['name'],
                    'price'         => $p['price'],
                    'emoji'         => $p['emoji'],
                    'available'     => true,
                    'active'        => true
                ]);
            }
        }

        // 7. Clients pour les Ardoises (Tabs)
        // 7. Clients pour les Ardoises (Tabs)
        $tabs = [
            ['fname' => 'Directeur', 'lname' => 'Général', 'phone' => '90000000'],
            ['fname' => 'Commandant', 'lname' => 'Koffi', 'phone' => '91000000'],
            ['fname' => 'Delta', 'lname' => 'SARL', 'phone' => '92000000'],
        ];

        foreach ($tabs as $t) {
            CustomerTab::create([
                'restaurant_id' => $restaurant->id,
                'created_by'    => 1, // Admin par défaut
                'first_name'    => $t['fname'],
                'last_name'     => $t['lname'],
                'phone'         => $t['phone'],
                'total_amount'  => 0,
                'paid_amount'   => 0,
                'status'        => 'open'
            ]);
        }

        $this->command->info("✅ SYSTÈME SmartFlow POS COMPLET INITIALISÉ !");
    }
}
