<?php

use App\Enums\ExpenseReportStatus;
use App\Enums\UserRole;
use App\Http\Requests\RejectExpenseReportRequest;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function makeRejectTestReport(User $owner, ExpenseCategory $category, ExpenseReportStatus $status): ExpenseReport
{
    return ExpenseReport::create([
        'user_id' => $owner->id,
        'expense_category_id' => $category->id,
        'expense_date' => '2026-01-01',
        'amount' => 1000,
        'payee' => 'テスト支払先',
        'description' => 'テスト内容',
        'status' => $status->value,
    ]);
}

beforeEach(function () {
    // 実際の admin.expense-reports.reject ルートはフェーズ10で実装するため、
    // FormRequest単体(authorize→validate→暗黙のルートモデルバインディング)を
    // 検証するための一時ルートをここで登録する。
    Route::middleware(['web', 'auth'])
        ->post('/__test/expense-reports/{expense_report}/reject', function (RejectExpenseReportRequest $request, ExpenseReport $expense_report) {
            return response()->json([
                'expense_report_id' => $expense_report->id,
                'route_param_class' => get_class($request->route('expense_report')),
                'validated' => $request->validated(),
            ]);
        });

    $this->category = ExpenseCategory::create(['name' => 'テストカテゴリ']);
    $this->employee = User::factory()->create(['role' => UserRole::Employee]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

test('admin can reject a submitted report with a comment, and route binding resolves to a model', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->admin)
        ->post("/__test/expense-reports/{$report->id}/reject", ['comment' => '領収書が不鮮明です']);

    $response->assertOk();
    expect($response->json('expense_report_id'))->toBe($report->id);
    expect($response->json('route_param_class'))->toBe(ExpenseReport::class);
});

test('admin can reject with no comment at all', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->admin)
        ->post("/__test/expense-reports/{$report->id}/reject", []);

    $response->assertOk();
});

test('a non-admin is forbidden from rejecting', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->employee)
        ->post("/__test/expense-reports/{$report->id}/reject", ['comment' => 'test']);

    $response->assertForbidden();
});

test('admin is forbidden from rejecting a draft report', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Draft);

    $response = $this->actingAs($this->admin)
        ->post("/__test/expense-reports/{$report->id}/reject", ['comment' => 'test']);

    $response->assertForbidden();
});

test('comment of exactly 1000 characters passes', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->admin)
        ->post("/__test/expense-reports/{$report->id}/reject", ['comment' => str_repeat('あ', 1000)]);

    $response->assertOk();
});

test('comment of 1001 characters fails validation', function () {
    $report = makeRejectTestReport($this->employee, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->admin)
        ->post("/__test/expense-reports/{$report->id}/reject", ['comment' => str_repeat('あ', 1001)]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('comment');
});
