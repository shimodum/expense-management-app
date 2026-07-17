<?php

namespace App\Policies;

use App\Enums\ExpenseReportStatus;
use App\Enums\UserRole;
use App\Models\ExpenseReport;
use App\Models\User;

class ExpenseReportPolicy
{
    /**
     * 一般社員: 自分の申請であること。管理者: 下書き以外の申請であること。
     */
    public function view(User $user, ExpenseReport $expenseReport): bool
    {
        if ($user->role === UserRole::Admin) {
            return $expenseReport->status !== ExpenseReportStatus::Draft;
        }

        return $expenseReport->user_id === $user->id;
    }

    /**
     * オーナー一致 かつ ステータスが下書きまたは却下であること。
     */
    public function update(User $user, ExpenseReport $expenseReport): bool
    {
        return $expenseReport->user_id === $user->id
            && in_array($expenseReport->status, [ExpenseReportStatus::Draft, ExpenseReportStatus::Rejected], true);
    }

    /**
     * オーナー一致 かつ ステータスが下書きであること。
     */
    public function delete(User $user, ExpenseReport $expenseReport): bool
    {
        return $expenseReport->user_id === $user->id
            && $expenseReport->status === ExpenseReportStatus::Draft;
    }

    /**
     * オーナー一致 かつ ステータスが下書きであること。
     */
    public function submit(User $user, ExpenseReport $expenseReport): bool
    {
        return $expenseReport->user_id === $user->id
            && $expenseReport->status === ExpenseReportStatus::Draft;
    }

    /**
     * オーナー一致 かつ ステータスが却下であること。
     */
    public function resubmit(User $user, ExpenseReport $expenseReport): bool
    {
        return $expenseReport->user_id === $user->id
            && $expenseReport->status === ExpenseReportStatus::Rejected;
    }

    /**
     * 管理者であること かつ ステータスが提出済みであること。
     */
    public function approve(User $user, ExpenseReport $expenseReport): bool
    {
        return $user->role === UserRole::Admin
            && $expenseReport->status === ExpenseReportStatus::Submitted;
    }

    /**
     * 管理者であること かつ ステータスが提出済みであること。
     */
    public function reject(User $user, ExpenseReport $expenseReport): bool
    {
        return $user->role === UserRole::Admin
            && $expenseReport->status === ExpenseReportStatus::Submitted;
    }
}
