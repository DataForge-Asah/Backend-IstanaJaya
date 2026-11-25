<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['code' => 'BL', 'name' => 'Bola Lampu'],
            ['code' => 'CA', 'name' => 'Cat'],
            ['code' => 'KS', 'name' => 'Kuas'],
            ['code' => 'KA', 'name' => 'Kran Air'],
            ['code' => 'MT', 'name' => 'Meteran'],
            ['code' => 'PP', 'name' => 'Pipa'],
            ['code' => 'LM', 'name' => 'Lem'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['code' => $category['code']],
                ['name' => $category['name']]
            );
        }
    }
}
