<?php

use App\Enums\ApprovalAction;
use App\Enums\ExpenseReportStatus;
use App\Enums\UserRole;
use App\Models\ApprovalHistory;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('user role is cast to the UserRole enum', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect($user->fresh()->role)
        ->toBeInstanceOf(UserRole::class)
        ->toBe(UserRole::Admin);
});

test('expense report resolves its user and expense category relations', function () {
    $user = User::factory()->create();
    $category = ExpenseCategory::create(['name' => '交通費']);

    $expenseReport = ExpenseReport::create([
        'user_id' => $user->id,
        'expense_category_id' => $category->id,
        'expense_date' => '2026-07-01',
        'amount' => 1000,
        'payee' => 'JR東日本',
        'description' => '出張の交通費',
    ]);

    expect($expenseReport->user->is($user))->toBeTrue()
        ->and($expenseReport->expenseCategory->is($category))->toBeTrue();
});

test('expense report casts status to enum and expense_date to a date', function () {
    $user = User::factory()->create();
    $category = ExpenseCategory::create(['name' => '宿泊費']);

    $expenseReport = ExpenseReport::create([
        'user_id' => $user->id,
        'expense_category_id' => $category->id,
        'expense_date' => '2026-07-02',
        'amount' => 5000,
        'payee' => 'ホテル',
        'description' => '出張の宿泊費',
        'status' => ExpenseReportStatus::Draft->value,
    ]);

    expect($expenseReport->status)
        ->toBeInstanceOf(ExpenseReportStatus::class)
        ->toBe(ExpenseReportStatus::Draft)
        ->and($expenseReport->expense_date)->toBeInstanceOf(Carbon::class);
});

test('expense report and approval history resolve each other', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = ExpenseCategory::create(['name' => '消耗品費']);

    $expenseReport = ExpenseReport::create([
        'user_id' => $user->id,
        'expense_category_id' => $category->id,
        'expense_date' => '2026-07-03',
        'amount' => 2000,
        'payee' => '文具店',
        'description' => '消耗品購入',
        'status' => ExpenseReportStatus::Submitted->value,
    ]);

    $history = ApprovalHistory::create([
        'expense_report_id' => $expenseReport->id,
        'actor_id' => $admin->id,
        'action' => ApprovalAction::Approved->value,
        'from_status' => ExpenseReportStatus::Submitted->value,
        'to_status' => ExpenseReportStatus::Approved->value,
    ]);

    expect($expenseReport->approvalHistories->pluck('id')->all())
        ->toBe([$history->id])
        ->and($history->expenseReport->is($expenseReport))->toBeTrue()
        ->and($history->actor->is($admin))->toBeTrue();
});

test('approval history casts action and status columns to enums and has no updated_at', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = ExpenseCategory::create(['name' => 'その他']);

    $expenseReport = ExpenseReport::create([
        'user_id' => $user->id,
        'expense_category_id' => $category->id,
        'expense_date' => '2026-07-04',
        'amount' => 3000,
        'payee' => '雑費',
        'description' => 'その他経費',
        'status' => ExpenseReportStatus::Submitted->value,
    ]);

    $history = ApprovalHistory::create([
        'expense_report_id' => $expenseReport->id,
        'actor_id' => $admin->id,
        'action' => ApprovalAction::Rejected->value,
        'from_status' => ExpenseReportStatus::Submitted->value,
        'to_status' => ExpenseReportStatus::Rejected->value,
        'comment' => '領収書不備',
    ]);

    expect($history->action)->toBe(ApprovalAction::Rejected)
        ->and($history->from_status)->toBe(ExpenseReportStatus::Submitted)
        ->and($history->to_status)->toBe(ExpenseReportStatus::Rejected)
        ->and($history->updated_at)->toBeNull()
        ->and($history->created_at)->not->toBeNull();
});
