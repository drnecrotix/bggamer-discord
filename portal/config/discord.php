<?php

return [
    'client_id' => env('DISCORD_CLIENT_ID'),
    'client_secret' => env('DISCORD_CLIENT_SECRET'),
    'redirect_uri' => env('DISCORD_REDIRECT_URI'),
    'bot_token' => env('DISCORD_BOT_TOKEN'),
    'guild_id' => env('DISCORD_GUILD_ID', '114667416247599110'),
    'admin_role_ids' => array_filter(array_map('trim', explode(',', env('DISCORD_ADMIN_ROLE_IDS', '')))),
    'moderator_role_ids' => array_filter(array_map('trim', explode(',', env('DISCORD_MODERATOR_ROLE_IDS', '')))),
    'announcement_channel_id' => env('DISCORD_ANNOUNCEMENT_CHANNEL_ID'),
];
