<?php

namespace Database\Seeders;

use App\Models\InventoryType;
use Illuminate\Database\Seeder;

// php artisan db:seed --class=InventoryTypeSeeder

class InventoryTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sampleData = [
            [
                'name' => 'Gas & Water',
                'code' => '01',
                'status' => 'active',
                'photo_url' => null,
                'details' => 'Gas and water supplies',
                'created_by' => null,
                'updated_by' => null,
            ],
            [
                'name' => 'Electronics',
                'code' => '02',
                'status' => 'active',
                'photo_url' => null,
                'details' => 'Electronic devices and gadgets',
                'created_by' => null,
                'updated_by' => null,
            ],
        ];

        foreach ($sampleData as $data) {
            // Store the existence check in a variable
            $exists = InventoryType::where('code', $data['code'])->exists();

            // Use isset to confirm if the variable holds a true/false value
            if (isset($exists) && !$exists) {
                InventoryType::create($data);
            }
        }
    }
}