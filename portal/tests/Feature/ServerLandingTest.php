<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerLandingTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): static
    {
        config()->set('discord.bot_token', 'test');
        config()->set('discord.admin_role_ids', ['234567890123456789']);
        Http::fake([
            'discord.com/api/v10/guilds/*/members/*' => Http::response(['roles' => ['234567890123456789']]),
            'discord.com/api/v10/guilds/345678901234567890?*' => Http::response(['id' => '345678901234567890', 'name' => 'Test guild', 'approximate_presence_count' => 90, 'approximate_member_count' => 3000]),
            'discord.com/api/v10/guilds/345678901234567890/widget.json' => Http::response(['presence_count' => 90, 'members' => [
                ['username' => 'Player', 'avatar_url' => 'https://cdn.discordapp.com/avatars/123/avatar.png', 'channel_id' => '456789012345678901'],
            ]]),
        ]);
        return $this->withSession(['staff' => ['id' => '123456789012345678', 'name' => 'Admin', 'level' => 'admin']]);
    }

    public function test_admin_selected_server_powers_public_landing(): void
    {
        $this->staff()->post('/admin/server', [
            'guild_id' => '345678901234567890', 'invite_code' => 'example',
        ])->assertRedirect();
        $this->get('/')->assertOk()
            ->assertSee('Test guild')
            ->assertSee('90')
            ->assertSee('Player')
            ->assertSee('https://discord.gg/example');
    }

    public function test_invalid_server_is_not_saved(): void
    {
        $this->staff()->post('/admin/server', ['guild_id' => 'not-a-guild'])->assertSessionHasErrors('guild_id');
        $this->assertDatabaseCount('discord_portal_settings', 0);
    }

    public function test_unavailable_widget_does_not_invent_voice_or_avatars(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 503)]);
        $this->get('/')->assertOk()->assertSee('Гласовите данни не са достъпни')->assertDontSee('live-avatars');
    }
}
