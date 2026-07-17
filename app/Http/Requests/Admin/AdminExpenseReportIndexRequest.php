<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminExpenseReportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * status が未指定・空文字の場合のみ 'submitted' を既定値として補う。
     * 不正な文字列(draft含む)は補完せず rules() の in: でエラーにする。
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('status')) {
            $this->merge([
                'status' => 'submitted',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:submitted,approved,rejected'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'ステータスを指定してください。',
            'status.in' => 'ステータスの指定が不正です。',
        ];
    }
}
