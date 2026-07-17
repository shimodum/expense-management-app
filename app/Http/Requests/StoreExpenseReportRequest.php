<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'payee' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'receipt_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expense_date.required' => '利用日を入力してください。',
            'expense_date.date' => '利用日は正しい日付形式で入力してください。',
            'expense_category_id.required' => '経費カテゴリを選択してください。',
            'expense_category_id.integer' => '経費カテゴリの指定が不正です。',
            'expense_category_id.exists' => '選択された経費カテゴリは存在しません。',
            'amount.required' => '金額を入力してください。',
            'amount.integer' => '金額は整数で入力してください。',
            'amount.min' => '金額は1円以上で入力してください。',
            'payee.required' => '支払先を入力してください。',
            'payee.string' => '支払先は文字列で入力してください。',
            'payee.max' => '支払先は255文字以内で入力してください。',
            'description.required' => '内容を入力してください。',
            'description.string' => '内容は文字列で入力してください。',
            'receipt_image.image' => '領収書画像は画像ファイルを指定してください。',
            'receipt_image.mimes' => '領収書画像はjpg・jpeg・png形式のみ利用できます。',
            'receipt_image.max' => '領収書画像は2MB以内のファイルを指定してください。',
        ];
    }
}
