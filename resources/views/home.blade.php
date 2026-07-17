<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホーム - 経費精算アプリ</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-sm bg-white p-8 rounded shadow text-center">
        <p class="mb-6">{{ auth()->user()->name }} さんとしてログインしています。</p>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                class="w-full bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700">
                ログアウト
            </button>
        </form>
    </div>
</body>
</html>
