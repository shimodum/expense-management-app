<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectExpenseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('expense_report'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.string' => '却下理由は文字列で入力してください。',
            'comment.max' => '却下理由は1000文字以内で入力してください。',
        ];
    }
}
