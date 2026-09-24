<?php

namespace Tests\Feature;

use App\Services\DiscordSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function owner(?string $discordId = null): int
    {
        return DB::table('portal_owners')->insertGetId([
            'email' => 'owner@example.com',
            'password' => Hash::make('very-long-owner-password'),
            'discord_id' => $discordId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_owner_password_login_works_without_discord(): void
    {
        $id = $this->owner();
        $this->post('/owner/login', [
            'email' => 'owner@example.com', 'password' => 'very-long-owner-password',
        ])->assertRedirect('/admin');
        $this->assertSame($id, session('owner_id'));
        $this->get('/admin/server')->assertOk();
    }

    public function test_one_login_offers_owner_and_discord_paths(): void
    {
        config()->set('discord.client_id', '123');
        config()->set('discord.client_secret', 'test');
        config()->set('discord.redirect_uri', 'https://example.com/discord/auth/discord/callback');
        $this->get('/login')->assertOk()->assertSee('Вход като Owner')->assertSee('Вход с Discord');
        $this->get('/owner/login')->assertRedirect('/login');
    }

    public function test_incomplete_installation_shows_recovery_instead_of_server_error(): void
    {
        Schema::drop('portal_owners');
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'any-password'])
            ->assertSessionHasErrors('email');
    }

    public function test_missing_discord_configuration_returns_to_shared_login(): void
    {
        config()->set('discord.client_id', null);
        $this->get('/auth/discord')->assertRedirect('/login')->assertSessionHasErrors('auth');
    }

    public function test_wrong_password_cannot_sign_in(): void
    {
        $this->owner();
        $this->post('/owner/login', ['email' => 'owner@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertNull(session('owner_id'));
    }

    public function test_linked_discord_identity_signs_in_as_owner_without_staff_role(): void
    {
        $id = $this->owner('123456789012345678');
        config()->set('discord.client_id', 'client');
        config()->set('discord.client_secret', 'secret');
        config()->set('discord.redirect_uri', 'https://portal.example.com/auth/discord/callback');
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'oauth-token']),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '123456789012345678', 'username' => 'Owner']),
        ]);
        $this->get('/auth/discord')->assertRedirect();
        $state = session('discord_oauth_state');
        $this->get('/auth/discord/callback?state='.$state.'&code=valid')->assertRedirect('/admin');
        $this->assertSame($id, session('owner_id'));
    }

    public function test_discord_link_requires_existing_owner_session(): void
    {
        $this->get('/owner/discord/link')->assertForbidden();
        $this->get('/owner/updates')->assertForbidden();
    }

    public function test_owner_can_unlink_only_with_password(): void
    {
        $id = $this->owner('123456789012345678');
        $this->withSession(['owner_id' => $id, 'staff' => ['id' => 'owner:'.$id, 'level' => 'admin']])
            ->post('/owner/discord/unlink', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('portal_owners', ['id' => $id, 'discord_id' => '123456789012345678']);
        $this->post('/owner/discord/unlink', ['password' => 'very-long-owner-password'])->assertRedirect();
        $this->assertDatabaseHas('portal_owners', ['id' => $id, 'discord_id' => null]);
    }

    public function test_owner_saves_encrypted_discord_settings_from_panel(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'discord-test-');
        unlink($file);
        $settings = new DiscordSettings($file);
        $this->app->instance(DiscordSettings::class, $settings);
        config()->set('app.url', 'https://bg-gamer.com/discord');
        $id = $this->owner();
        $this->withSession(['owner_id' => $id, 'staff' => ['id' => 'owner:'.$id, 'level' => 'admin']]);
        try {
            $this->post('/owner/settings/discord', [
                'client_id' => '123456789012345678',
                'client_secret' => 'private-discord-secret',
                'bot_token' => 'private-bot-token',
                'admin_role_ids' => '234567890123456789',
                'moderator_role_ids' => '',
                'support_role_ids' => '',
                'current_password' => 'very-long-owner-password',
            ])->assertRedirect();
            $this->assertStringNotContainsString('private-discord-secret', file_get_contents($file));
            $this->assertSame('private-bot-token', $settings->get('bot_token'));
            $this->assertSame(['234567890123456789'], $settings->get('admin_role_ids'));
            $this->get('/owner/settings')->assertOk()->assertSee('https://bg-gamer.com/discord/auth/discord/callback');
            $this->get('/auth/discord')->assertRedirect();
        } finally {
            @unlink($file);
        }
    }
}
