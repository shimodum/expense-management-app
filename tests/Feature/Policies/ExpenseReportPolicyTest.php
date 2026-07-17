<?php

use App\Enums\ExpenseReportStatus;
use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeExpenseReport(User $owner, ExpenseCategory $category, ExpenseReportStatus $status): ExpenseReport
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
    $this->category = ExpenseCategory::create(['name' => 'テストカテゴリ']);
    $this->owner = User::factory()->create(['role' => UserRole::Employee]);
    $this->otherEmployee = User::factory()->create(['role' => UserRole::Employee]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

// view

test('view allows the owner regardless of status', function (ExpenseReportStatus $status) {
    $report = makeExpenseReport($this->owner, $this->category, $status);

    expect($this->owner->can('view', $report))->toBeTrue();
})->with([
    ExpenseReportStatus::Draft,
    ExpenseReportStatus::Submitted,
    ExpenseReportStatus::Approved,
    ExpenseReportStatus::Rejected,
]);

test('view denies a different employee', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    expect($this->otherEmployee->can('view', $report))->toBeFalse();
});

test('view denies the admin for a draft report', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->admin->can('view', $report))->toBeFalse();
});

test('view allows the admin for a non-draft report', function (ExpenseReportStatus $status) {
    $report = makeExpenseReport($this->owner, $this->category, $status);

    expect($this->admin->can('view', $report))->toBeTrue();
})->with([
    ExpenseReportStatus::Submitted,
    ExpenseReportStatus::Approved,
    ExpenseReportStatus::Rejected,
]);

// update

test('update allows the owner when status is draft or rejected', function (ExpenseReportStatus $status) {
    $report = makeExpenseReport($this->owner, $this->category, $status);

    expect($this->owner->can('update', $report))->toBeTrue();
})->with([ExpenseReportStatus::Draft, ExpenseReportStatus::Rejected]);

test('update denies the owner when status is submitted or approved', function (ExpenseReportStatus $status) {
    $report = makeExpenseReport($this->owner, $this->category, $status);

    expect($this->owner->can('update', $report))->toBeFalse();
})->with([ExpenseReportStatus::Submitted, ExpenseReportStatus::Approved]);

test('update denies a different employee even for a draft', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->otherEmployee->can('update', $report))->toBeFalse();
});

// delete

test('delete allows the owner only when status is draft', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->owner->can('delete', $report))->toBeTrue();
});

test('delete denies the owner when status is not draft', function (ExpenseReportStatus $status) {
    $report = makeExpenseReport($this->owner, $this->category, $status);

    expect($this->owner->can('delete', $report))->toBeFalse();
})->with([ExpenseReportStatus::Submitted, ExpenseReportStatus::Approved, ExpenseReportStatus::Rejected]);

// submit

test('submit allows the owner only when status is draft', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->owner->can('submit', $report))->toBeTrue();
});

test('submit denies the owner when status is not draft', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Rejected);

    expect($this->owner->can('submit', $report))->toBeFalse();
});

// resubmit

test('resubmit allows the owner only when status is rejected', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Rejected);

    expect($this->owner->can('resubmit', $report))->toBeTrue();
});

test('resubmit denies the owner when status is not rejected', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->owner->can('resubmit', $report))->toBeFalse();
});

// approve

test('approve allows the admin only when status is submitted', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    expect($this->admin->can('approve', $report))->toBeTrue();
});

test('approve denies a non-admin', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    expect($this->owner->can('approve', $report))->toBeFalse();
});

test('approve denies the admin when status is not submitted', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Draft);

    expect($this->admin->can('approve', $report))->toBeFalse();
});

// reject

test('reject allows the admin only when status is submitted', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    expect($this->admin->can('reject', $report))->toBeTrue();
});

test('reject denies a non-admin', function () {
    $report = makeExpenseReport($this->owner, $this->category, ExpenseReportStatus::Submitted);

    expect($this->owner->can('reject', $report))->toBeFalse();
});
