<?php

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeding creates the five expected expense categories', function () {
    $this->seed();

    expect(ExpenseCategory::count())->toBe(5)
        ->and(ExpenseCategory::pluck('name')->sort()->values()->all())
        ->toBe(collect(['交通費', '宿泊費', '交際費', '消耗品費', 'その他'])->sort()->values()->all());
});

test('seeding creates the employee and admin test users', function () {
    $this->seed();

    $employee = User::where('email', 'employee@example.com')->first();
    $admin = User::where('email', 'admin@example.com')->first();

    expect($employee)->not->toBeNull()
        ->and($employee->role)->toBe(UserRole::Employee)
        ->and($admin)->not->toBeNull()
        ->and($admin->role)->toBe(UserRole::Admin);
});

test('seeding twice does not throw or create duplicate rows', function () {
    $this->seed();
    $this->seed();

    expect(ExpenseCategory::count())->toBe(5)
        ->and(User::where('email', 'employee@example.com')->count())->toBe(1)
        ->and(User::where('email', 'admin@example.com')->count())->toBe(1);
});
