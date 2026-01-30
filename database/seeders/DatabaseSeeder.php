<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\HeadOfAccounts;
use App\Models\SubHeadOfAccounts;
use App\Models\ChartOfAccounts;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductSubcategory;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $userId = 1; // ID for created_by / updated_by

        // 🔑 Create Super Admin User
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin',
                'email' => 'admin@gmail.com', // optional, keep if you want for notifications
                'password' => Hash::make('12345678'),
            ]
        );

        $superAdmin = Role::firstOrCreate(['name' => 'superadmin']);
        $admin->assignRole($superAdmin);

        // 📌 Functional Modules (CRUD-style permissions)
        $modules = [
            // User Management
            'user_roles',
            'users',

            // Accounts
            // 'coa',
            // 'shoa',

            // Products
            'products',
            'product_categories',
            'product_subcategories',
            'attributes',

            // Stock Management
            'locations',
            'stock_transfer',

            // Purchases
            // 'purchase_invoices',
            // 'purchase_return',

            // Sales
            // 'sale_invoices',
            // 'sale_return',

            // Vouchers
            // 'vouchers',
            'pdc',
        ];

        $actions = ['index', 'create', 'edit', 'delete', 'print'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "$module.$action",
                ]);
            }
        }

        // 📊 Report permissions (only view access, no CRUD)
        // $reports = ['inventory', 'purchase', 'sales', 'accounts'];
        $reports = ['inventory'];

        foreach ($reports as $report) {
            Permission::firstOrCreate([
                'name' => "reports.$report",
            ]);
        }

        // Assign all permissions to Superadmin
        $superAdmin->syncPermissions(Permission::all());


        // ---------------------
        // HEADS OF ACCOUNTS
        // ---------------------
        HeadOfAccounts::insert([
            ['id' => 1, 'name' => 'Assets', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Liabilities', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Equity', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Revenue', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Expenses', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ---------------------
        // SUB HEADS (Required for future account creation)
        // ---------------------
        SubHeadOfAccounts::insert([
            ['id' => 1, 'hoa_id' => 1, 'name' => 'Cash', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'hoa_id' => 1, 'name' => 'Bank', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'hoa_id' => 1, 'name' => 'Accounts Receivable', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'hoa_id' => 1, 'name' => 'Inventory', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'hoa_id' => 2, 'name' => 'Accounts Payable', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'hoa_id' => 2, 'name' => 'Loans', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'hoa_id' => 3, 'name' => 'Owner Capital', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'hoa_id' => 4, 'name' => 'Sales', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'hoa_id' => 5, 'name' => 'Purchases', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 10,'hoa_id' => 5, 'name' => 'Salaries', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11,'hoa_id' => 5, 'name' => 'Rent', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12,'hoa_id' => 5, 'name' => 'Utilities', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ---------------------
        // CHART OF ACCOUNTS (Your 4 core accounts)
        // ---------------------
        $coaData = [
            ['id' => 1, 'account_code' => '104001', 'shoa_id' => 4, 'name' => 'Stock in Hand', 'account_type' => 'asset'],
            ['id' => 2, 'account_code' => '307001', 'shoa_id' => 7, 'name' => 'Owners Equity', 'account_type' => 'equity'],
            ['id' => 3, 'account_code' => '408001', 'shoa_id' => 8, 'name' => 'Sales Revenue', 'account_type' => 'revenue'],
            ['id' => 4, 'account_code' => '509001', 'shoa_id' => 9, 'name' => 'Cost of Goods Sold', 'account_type' => 'cogs'],
        ];

        foreach ($coaData as $data) {
            ChartOfAccounts::create([
                'id'             => $data['id'],
                'account_code'   => $data['account_code'],
                'shoa_id'        => $data['shoa_id'],
                'name'           => $data['name'],
                'account_type'   => $data['account_type'],
                'receivables'    => 0,
                'payables'       => 0,
                'credit_limit'   => 0,
                'opening_date'   => now(),
                'created_by'     => $userId,
                'updated_by'     => $userId,
            ]);
        }

        // 📏 Measurement Units
        MeasurementUnit::insert([
            ['id' => 1, 'name' => 'Kilogram', 'shortcode' => 'kg'],
            ['id' => 2, 'name' => 'Meter', 'shortcode' => 'm'],
            ['id' => 3, 'name' => 'Pieces', 'shortcode' => 'pcs'],
            ['id' => 4, 'name' => 'Bag', 'shortcode' => 'bag'],
            ['id' => 5, 'name' => 'Bundle', 'shortcode' => 'bundle'],
        ]);

        // 📦 Product Categories
        ProductCategory::insert([
            ['id' => 1, 'name' => 'Net',   'code' => 'net', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Rope',  'code' => 'rope', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // --- Mesh Size Attribute ---
        $meshSizeAttr = Attribute::create([
            'name' => 'Mesh Size',
            'slug' => Str::slug('mesh-size'),
        ]);

        $sizes = [
            '8000mm', '6000mm', '4000mm', '3000mm', '2400mm', '1600mm', '1200mm', '1000mm', 
            '800', '600mm', '560mm', '480mm', '400mm', '320mm', '240mm', '200mm', '160mm', 
            '120mm', '80mm', '60mm', '40mm', '30mm', '25mm', '20mm'
        ];

        foreach ($sizes as $sz) {
            AttributeValue::create([
                'attribute_id' => $meshSizeAttr->id,
                'value' => $sz,
            ]);
        }

        // --- Thickness Attribute ---
        $thicknessAttr = Attribute::create([
            'name' => 'Thickness', // Corrected spelling from 'Thickess'
            'slug' => Str::slug('thickness'),
        ]);

        $thicknessValues = [
            '51ply', '36ply', '24ply', '18ply', '20mm', '18mm', '16mm', '14mm', '8mm', '33ply'        
        ];

        foreach ($thicknessValues as $tk) {
            AttributeValue::create([
                'attribute_id' => $thicknessAttr->id, // Reference the Attribute model ID
                'value' => $tk,
            ]);
        }

        // 🏠 Locations (Godowns)
        $locations = [
            [
                'id' => 1, 
                'name' => 'Naveed Godown', 
                'code' => 'naveed-godown', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
            [
                'id' => 2, 
                'name' => 'W.W godown', 
                'code' => 'ww-godown', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
            [
                'id' => 3, 
                'name' => 'Hafiz Center Godown', 
                'code' => 'hafiz-center', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
            [
                'id' => 4, 
                'name' => 'Shop', 
                'code' => 'shop', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
            [
                'id' => 5, 
                'name' => 'Customer', 
                'code' => 'customer', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
            [
                'id' => 6, 
                'name' => 'Vendor', 
                'code' => 'vendor', 
                'created_at' => $now, 
                'updated_at' => $now
            ],
        ];

        foreach ($locations as $loc) {
            Location::updateOrCreate(['id' => $loc['id']], $loc);
        }
        // 🛠 Products
        $products = [
            [
                'id' => 1,
                'category_id' => 1,
                'subcategory_id' => null,
                'name' => 'HDPE',
                'sku' => 'HDPE-NET',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4, // Bag
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'category_id' => 2,
                'subcategory_id' => null,
                'name' => 'HDPE TWINE',
                'sku' => 'HDPE-TWINE-ROPE',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'category_id' => 2,
                'subcategory_id' => null,
                'name' => 'PE',
                'sku' => 'PE-ROPE',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'category_id' => 1,
                'subcategory_id' => null,
                'name' => 'POLYSTER',
                'sku' => 'POLYSTER-NET',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'category_id' => 1,
                'subcategory_id' => null,
                'name' => 'NYLON',
                'sku' => 'NYLON-NET',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 6,
                'category_id' => 1,
                'subcategory_id' => null,
                'name' => 'MONO',
                'sku' => 'MONO-NET',
                'description' => null,
                'opening_stock' => 0.00,
                'selling_price' => 0.00,
                'measurement_unit' => 4,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(['id' => $product['id']], $product);
        }
    }
}
