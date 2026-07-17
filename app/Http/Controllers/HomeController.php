<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * ロールに応じて初期画面へリダイレクトする。
     * expense-reports.index / admin.expense-reports.index はフェーズ10で実装予定のため、
     * それまではこのアクションを実際に叩くと RouteNotFoundException になる(既知の制約)。
     */
    public function redirect(): RedirectResponse
    {
        return match (Auth::user()->role) {
            UserRole::Employee => redirect()->route('expense-reports.index'),
            UserRole::Admin => redirect()->route('admin.expense-reports.index'),
        };
    }
}
