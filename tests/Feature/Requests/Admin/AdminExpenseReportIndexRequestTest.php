<?php

use App\Enums\UserRole;
use App\Http\Requests\Admin\AdminExpenseReportIndexRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    // prepareForValidation() の挙動は Validator::make() だけでは検証できないため、
    // 一時ルートを経由した実際のHTTPリクエストで確認する。
    Route::middleware(['web', 'auth'])
        ->get('/__test/admin/expense-reports', function (AdminExpenseReportIndexRequest $request) {
            return response()->json($request->validated());
        });

    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

test('valid status values pass the in rule', function (string $status) {
    $validator = Validator::make(['status' => $status], (new AdminExpenseReportIndexRequest())->rules());

    expect($validator->fails())->toBeFalse();
})->with(['submitted', 'approved', 'rejected']);

test('draft and unknown status values fail the in rule', function (string $status) {
    $validator = Validator::make(['status' => $status], (new AdminExpenseReportIndexRequest())->rules());

    expect($validator->fails())->toBeTrue();
})->with(['draft', 'bogus']);

test('status defaults to submitted when not provided', function () {
    $response = $this->actingAs($this->admin)->get('/__test/admin/expense-reports');

    $response->assertOk();
    expect($response->json('status'))->toBe('submitted');
});

test('empty status also defaults to submitted', function () {
    $response = $this->actingAs($this->admin)->get('/__test/admin/expense-reports?status=');

    $response->assertOk();
    expect($response->json('status'))->toBe('submitted');
});

test('invalid status is not defaulted and fails validation instead', function () {
    $response = $this->actingAs($this->admin)->get('/__test/admin/expense-reports?status=bogus');

    $response->assertRedirect();
    $response->assertSessionHasErrors('status');
});

test('draft is rejected rather than silently defaulted', function () {
    $response = $this->actingAs($this->admin)->get('/__test/admin/expense-reports?status=draft');

    $response->assertRedirect();
    $response->assertSessionHasErrors('status');
});
