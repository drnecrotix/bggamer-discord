<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Вход · BG-GAMER Portal</title>
    <link rel="stylesheet" href="{{ url('/portal.css') }}">
</head>
<body>
<main class="login editor">
    <a href="{{ url('/') }}">← BG-GAMER</a>
    <p class="eyebrow">BG-GAMER / PORTAL ACCESS</p>
    <h1>Вход в портала.</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <section class="panel">
        <h2>Owner</h2>
        <p class="muted">Вход с имейл и парола, включително когато Discord е недостъпен.</p>
        <form method="post" action="{{ url('/login') }}">
            @csrf
            <label for="email">Имейл</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
            <label for="password">Парола</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="button">Вход като Owner</button>
        </form>
    </section>
    <section class="panel" style="margin-top:20px;min-height:0">
        <h2>Staff</h2>
        <p class="muted">Вход с Discord акаунт и потвърдена Admin, Moderator или Support роля.</p>
        <a class="button" href="{{ route('discord.login') }}">Вход с Discord ↗</a>
        @unless($discordReady)<p class="notice">Discord OAuth още не е настроен. Owner може да влезе с имейл и парола.</p>@endunless
    </section>
</main>
</body>
</html>
