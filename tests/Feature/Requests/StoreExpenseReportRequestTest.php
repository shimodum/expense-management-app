<?php

use App\Http\Requests\StoreExpenseReportRequest;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function validExpenseReportData(int $categoryId): array
{
    return [
        'expense_date' => '2026-01-01',
        'expense_category_id' => $categoryId,
        'amount' => 1000,
        'payee' => 'テスト株式会社',
        'description' => '出張の交通費',
    ];
}

beforeEach(function () {
    $this->category = ExpenseCategory::create(['name' => 'テストカテゴリ']);
    $this->request = new StoreExpenseReportRequest();
});

test('valid data passes validation', function () {
    $validator = Validator::make(validExpenseReportData($this->category->id), $this->request->rules());

    expect($validator->fails())->toBeFalse();
});

test('required fields fail when missing', function (string $field) {
    $data = validExpenseReportData($this->category->id);
    unset($data[$field]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has($field))->toBeTrue();
})->with(['expense_date', 'expense_category_id', 'amount', 'payee', 'description']);

test('amount must be at least 1', function () {
    $data = array_merge(validExpenseReportData($this->category->id), ['amount' => 0]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('amount'))->toBeTrue();
});

test('amount must be an integer', function () {
    $data = array_merge(validExpenseReportData($this->category->id), ['amount' => 'abc']);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
});

test('expense_category_id must exist', function () {
    $data = array_merge(validExpenseReportData($this->category->id), ['expense_category_id' => 999999]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('expense_category_id'))->toBeTrue();
});

test('receipt_image rejects a non-image file', function () {
    $data = array_merge(validExpenseReportData($this->category->id), [
        'receipt_image' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
    ]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('receipt_image'))->toBeTrue();
});

test('receipt_image rejects a file larger than 2MB', function () {
    $data = array_merge(validExpenseReportData($this->category->id), [
        'receipt_image' => UploadedFile::fake()->image('receipt.jpg')->size(2049),
    ]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('receipt_image'))->toBeTrue();
});

test('receipt_image accepts a valid jpg within the size limit', function () {
    $data = array_merge(validExpenseReportData($this->category->id), [
        'receipt_image' => UploadedFile::fake()->image('receipt.jpg')->size(1024),
    ]);

    $validator = Validator::make($data, $this->request->rules());

    expect($validator->fails())->toBeFalse();
});

test('japanese custom messages are applied', function () {
    $data = validExpenseReportData($this->category->id);
    unset($data['amount']);

    $validator = Validator::make($data, $this->request->rules(), $this->request->messages());

    expect($validator->errors()->first('amount'))->toBe('金額を入力してください。');
});
