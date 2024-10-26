<?php

namespace Database\Seeders;

use App\Models\InventoryType;
use App\Models\Product;
use Illuminate\Database\Seeder;

// php artisan db:seed --class=UpdateProductInventoryTypeSeeder

class UpdateProductInventoryTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Fetch the inventory type with code '01'
        $inventoryType = InventoryType::where('code', '01')->first();

        // Stop if inventory type does not exist
        if (!$inventoryType) {
            $this->command->info('Inventory type with code "01" does not exist. Seeder stopped.');
            return;
        }

        // Update products with null inventory_types_id
        Product::whereNull('inventory_types_id')
            ->update(['inventory_types_id' => $inventoryType->id]);

        $this->command->info('Products with null inventory_types_id have been updated.');
    }
}