<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '123456789012345678';
    private const ADMIN_ROLE = '234567890123456789';
    private const MOD_ROLE = '345678901234567890';
    private const SUPPORT_ROLE = '456789012345678901';

    private function asStaff(string $role, string $level): static
    {
        config()->set('discord.admin_role_ids', [self::ADMIN_ROLE]);
        config()->set('discord.moderator_role_ids', [self::MOD_ROLE]);
        config()->set('discord.support_role_ids', [self::SUPPORT_ROLE]);
        config()->set('discord.bot_token', 'test');
        Http::fake([
            'discord.com/api/v10/guilds/*/members/*' => Http::response(['roles' => [$role]]),
            'discord.com/api/v10/channels/*/messages' => Http::response(['id' => '567890123456789012']),
        ]);
        return $this->withSession(['staff' => ['id' => self::USER_ID, 'name' => 'Staff', 'level' => $level]]);
    }

    public function test_guest_cannot_open_dashboard_or_publish(): void
    {
        $this->get('/admin')->assertForbidden();
        $this->post('/announcements', ['content' => 'Hello'])->assertForbidden();
    }

    public function test_support_cannot_edit_or_publish(): void
    {
        $this->asStaff(self::SUPPORT_ROLE, 'support')->get('/admin/homepage')->assertForbidden();
        $this->asStaff(self::SUPPORT_ROLE, 'support')->post('/announcements', ['content' => 'Hello'])->assertForbidden();
    }

    public function test_moderator_can_edit_homepage_but_not_publish(): void
    {
        $this->asStaff(self::MOD_ROLE, 'moderator')->post('/admin/homepage', [
            'title' => 'Community', 'subtitle' => 'Join us', 'body' => 'Hello',
            'cta_label' => 'Join', 'cta_url' => 'https://discord.gg/test',
        ])->assertRedirect();
        $this->assertDatabaseHas('portal_pages', ['slug' => 'home', 'title' => 'Community']);
        $this->post('/announcements', ['content' => 'Hello'])->assertForbidden();
    }

    public function test_admin_publication_disables_mentions_and_writes_audit(): void
    {
        config()->set('discord.announcement_channel_id', '678901234567890123');
        $this->asStaff(self::ADMIN_ROLE, 'admin')->post('/announcements', ['content' => '@everyone update'])->assertRedirect();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['allowed_mentions'] === ['parse' => []]);
        $this->assertDatabaseHas('portal_audit', ['actor_id' => self::USER_ID, 'action' => 'announcement.published']);
    }

    public function test_public_bans_only_show_public_fields(): void
    {
        $this->get('/bans')->assertOk()->assertSee('Източникът на банове');
        $this->get('/')->assertOk();
    }
}
