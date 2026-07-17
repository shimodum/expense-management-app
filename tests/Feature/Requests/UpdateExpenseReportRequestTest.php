<?php

use App\Enums\ExpenseReportStatus;
use App\Enums\UserRole;
use App\Http\Requests\UpdateExpenseReportRequest;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function makeUpdateTestReport(User $owner, ExpenseCategory $category, ExpenseReportStatus $status): ExpenseReport
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

function validUpdatePayload(int $categoryId): array
{
    return [
        'expense_date' => '2026-02-01',
        'expense_category_id' => $categoryId,
        'amount' => 2000,
        'payee' => '更新後の支払先',
        'description' => '更新後の内容',
    ];
}

beforeEach(function () {
    // 実際の expense-reports.update ルートはフェーズ10で実装するため、
    // FormRequest単体(authorize→validate→暗黙のルートモデルバインディング)を
    // 検証するための一時ルートをここで登録する。
    Route::middleware(['web', 'auth'])
        ->put('/__test/expense-reports/{expense_report}', function (UpdateExpenseReportRequest $request, ExpenseReport $expense_report) {
            return response()->json([
                'expense_report_id' => $expense_report->id,
                'route_param_class' => get_class($request->route('expense_report')),
                'validated' => $request->validated(),
            ]);
        });

    $this->category = ExpenseCategory::create(['name' => 'テストカテゴリ']);
    $this->owner = User::factory()->create(['role' => UserRole::Employee]);
    $this->otherEmployee = User::factory()->create(['role' => UserRole::Employee]);
});

test('owner can update a draft report with valid input, and route binding resolves to a model', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    $response = $this->actingAs($this->owner)
        ->put("/__test/expense-reports/{$report->id}", validUpdatePayload($this->category->id));

    $response->assertOk();
    expect($response->json('expense_report_id'))->toBe($report->id);
    // authorize() が使う $this->route('expense_report') が、文字列IDではなく
    // ExpenseReportインスタンスを返していることを確認する。
    expect($response->json('route_param_class'))->toBe(ExpenseReport::class);
});

test('a different employee is forbidden from updating the report', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    $response = $this->actingAs($this->otherEmployee)
        ->put("/__test/expense-reports/{$report->id}", validUpdatePayload($this->category->id));

    $response->assertForbidden();
});

test('owner is forbidden from updating a submitted report', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    $response = $this->actingAs($this->owner)
        ->put("/__test/expense-reports/{$report->id}", validUpdatePayload($this->category->id));

    $response->assertForbidden();
});

test('invalid input redirects back with session errors for a normal form submission', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);
    $payload = validUpdatePayload($this->category->id);
    unset($payload['amount']);

    $response = $this->actingAs($this->owner)
        ->put("/__test/expense-reports/{$report->id}", $payload);

    $response->assertRedirect();
    $response->assertSessionHasErrors('amount');
});

test('invalid input returns 422 for an explicit json request', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);
    $payload = validUpdatePayload($this->category->id);
    unset($payload['amount']);

    $response = $this->actingAs($this->owner)
        ->putJson("/__test/expense-reports/{$report->id}", $payload);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('amount');
});

test('receipt_image rejects an oversized file', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);
    $payload = array_merge(validUpdatePayload($this->category->id), [
        'receipt_image' => UploadedFile::fake()->image('receipt.jpg')->size(2049),
    ]);

    $response = $this->actingAs($this->owner)
        ->put("/__test/expense-reports/{$report->id}", $payload);

    $response->assertRedirect();
    $response->assertSessionHasErrors('receipt_image');
});

test('remove_receipt_image accepts a boolean flag', function () {
    $report = makeUpdateTestReport($this->owner, $this->category, ExpenseReportStatus::Draft);
    $payload = array_merge(validUpdatePayload($this->category->id), [
        'remove_receipt_image' => true,
    ]);

    $response = $this->actingAs($this->owner)
        ->put("/__test/expense-reports/{$report->id}", $payload);

    $response->assertOk();
});
