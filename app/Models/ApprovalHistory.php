<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ExpenseReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalHistory extends Model
{
    /**
     * approval_historiesは追記型ログのためupdated_atを持たない(05_table_definition.md参照)。
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'expense_report_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'comment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'from_status' => ExpenseReportStatus::class,
            'to_status' => ExpenseReportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ExpenseReport, $this>
     */
    public function expenseReport(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
