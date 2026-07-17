<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * TODO: フェーズ10で、ロールに応じて expense-reports.index / admin.expense-reports.index へ
     * リダイレクトする正式な実装に差し替える。
     */
    public function redirect(): View
    {
        return view('home');
    }
}
