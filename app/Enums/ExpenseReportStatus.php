<?php

namespace App\Enums;

/**
 * expense_reports.status の許容値。
 * approval_histories.from_status / to_status も同じ値域のため、このEnumを再利用する。
 */
enum ExpenseReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
