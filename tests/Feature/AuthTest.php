<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login page is displayed', function () {
    $this->get(route('login'))->assertOk();
});

test('user can login with correct credentials', function () {
    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'password',
    ]);

    $response = $this->post(route('login.attempt'), [
        'email' => 'employee@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('login fails with an incorrect password', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'password',
    ]);

    $response = $this->post(route('login.attempt'), [
        'email' => 'employee@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('authenticated user can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('guest is redirected to login when accessing the home route', function () {
    // HomeController@redirect の実際のリダイレクト先(expense-reports.index等)は
    // フェーズ10で実装予定のため未検証。ここではauthミドルウェアの動作のみ確認する。
    $this->get(route('home'))->assertRedirect(route('login'));
});
