<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * role:employee / role:admin を実際に使う業務ルートはフェーズ10で実装するため、
 * ここではミドルウェア単体の動作確認用に一時的なテストルートを登録する。
 */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:employee'])->get('/__test/employee-only', fn () => 'employee-ok');
    Route::middleware(['web', 'auth', 'role:admin'])->get('/__test/admin-only', fn () => 'admin-ok');
});

test('employee can access an employee-only route', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)->get('/__test/employee-only')->assertOk();
});

test('admin cannot access an employee-only route', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/__test/employee-only')->assertForbidden();
});

test('admin can access an admin-only route', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/__test/admin-only')->assertOk();
});

test('employee cannot access an admin-only route', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)->get('/__test/admin-only')->assertForbidden();
});

test('guest is redirected to login instead of getting a 403', function () {
    $this->get('/__test/employee-only')->assertRedirect(route('login'));
});
