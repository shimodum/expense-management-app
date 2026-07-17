<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $names = [
            '交通費',
            '宿泊費',
            '交際費',
            '消耗品費',
            'その他',
        ];

        foreach ($names as $name) {
            ExpenseCategory::updateOrCreate(['name' => $name]);
        }
    }
}
