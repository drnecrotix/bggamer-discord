<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Owner настройки · BG-GAMER</title>
    <link rel="stylesheet" href="{{ url('/portal.css') }}">
</head>
<body>
<main class="editor">
    <a href="{{ url('/admin') }}">← Dashboard</a>
    <p class="eyebrow">ACCOUNT / OWNER</p>
    <h1>Owner настройки</h1>
    <p class="muted">{{ $owner->email }}. Входът с имейл и парола остава активен независимо от Discord.</p>
    @if(session('status'))<p class="notice success">{{ session('status') }}</p>@endif
    @unless($schemaReady)
    <section class="panel" style="min-height:0;margin-bottom:20px">
        <h2>Довърши системния ъпдейт</h2>
        <p class="muted">След качване на новия FTP архив приложи новите таблици за роли, FAQ и support tickets. Направи резервно копие на базата преди това.</p>
        @error('migration')<p class="error">{{ $message }}</p>@enderror
        <form method="post" action="{{ url('/owner/settings/migrate') }}">@csrf<label for="migration_password">Owner парола</label><input id="migration_password" name="current_password" type="password" autocomplete="current-password" required><button class="button">Приложи таблиците</button></form>
    </section>
    @endunless
    <section class="panel">
        <h2>Discord интеграция</h2>
        <p class="muted">Въведи данните от Discord Developer Portal. Точният OAuth Redirect URL, който трябва да добавиш там, е:</p>
        <p><code>{{ $callbackUrl }}</code></p>
        <form method="post" action="{{ url('/owner/settings/discord') }}" autocomplete="off">
            @csrf
            <label for="client_id">Application / Client ID</label>
            <input id="client_id" name="client_id" inputmode="numeric" value="{{ old('client_id', $discord['client_id'] ?? '') }}">
            @error('client_id')<p class="error">{{ $message }}</p>@enderror
            <label for="client_secret">Client Secret</label>
            <input id="client_secret" name="client_secret" type="password" autocomplete="new-password" placeholder="{{ !empty($discord['client_secret']) ? 'Запазен - остави празно, за да го запазиш' : 'Въведи Client Secret' }}">
            <label for="bot_token">Bot Token</label>
            <input id="bot_token" name="bot_token" type="password" autocomplete="new-password" placeholder="{{ !empty($discord['bot_token']) ? 'Запазен - остави празно, за да го запазиш' : 'Въведи Bot Token' }}">
            <p class="muted">Тайните не се показват повторно. Staff входът изисква ботът да е в избрания сървър.</p>
            <label for="admin_role_ids">Admin role IDs</label>
            <input id="admin_role_ids" name="admin_role_ids" value="{{ old('admin_role_ids', implode(',', $discord['admin_role_ids'] ?? [])) }}" placeholder="ID, ID">
            <label for="moderator_role_ids">Moderator role IDs</label>
            <input id="moderator_role_ids" name="moderator_role_ids" value="{{ old('moderator_role_ids', implode(',', $discord['moderator_role_ids'] ?? [])) }}" placeholder="ID, ID">
            <label for="support_role_ids">Support role IDs</label>
            <input id="support_role_ids" name="support_role_ids" value="{{ old('support_role_ids', implode(',', $discord['support_role_ids'] ?? [])) }}" placeholder="ID, ID">
            @foreach(['admin_role_ids','moderator_role_ids','support_role_ids'] as $field)
                @error($field)<p class="error">{{ $message }}</p>@enderror
            @endforeach
            <label for="announcement_channel_id">Announcement channel ID</label>
            <input id="announcement_channel_id" name="announcement_channel_id" inputmode="numeric" value="{{ old('announcement_channel_id', $discord['announcement_channel_id'] ?? '') }}">
            @error('announcement_channel_id')<p class="error">{{ $message }}</p>@enderror
            <p class="muted"><a href="{{ url('/admin/server') }}">Настрой Discord Server ID и покана →</a></p>
            <label for="current_password">Owner парола за потвърждение</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            @error('current_password')<p class="error">{{ $message }}</p>@enderror
            <button class="button">Запази Discord настройките</button>
        </form>
    </section>
    <section class="panel" style="margin-top:20px;min-height:0">
        @if($owner->discord_id)
            <h2>Owner Discord е свързан</h2>
            <p class="muted">Акаунт ID: {{ $owner->discord_id }}</p>
            <form method="post" action="{{ url('/owner/discord/unlink') }}">
                @csrf
                <label for="unlink_password">Парола за потвърждение</label>
                <input id="unlink_password" name="password" type="password" required autocomplete="current-password">
                @error('password')<p class="error">{{ $message }}</p>@enderror
                <button class="button">Прекъсни връзката</button>
            </form>
        @else
            <h2>Свържи Owner с Discord</h2>
            <p class="muted">След настройката на OAuth можеш да влизаш с Discord или с имейл и парола.</p>
            @if(!empty($discord['client_id']) && !empty($discord['client_secret']))
                <a class="button" href="{{ url('/owner/discord/link') }}">Свържи Discord ↗</a>
            @else
                <p class="notice">Първо запази Client ID и Client Secret.</p>
            @endif
        @endif
    </section>
</main>
</body>
</html>
