<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_dashboard_or_publish(): void
    {
        $this->get('/dashboard')->assertForbidden();
        $this->post('/announcements', ['content' => 'Hello'])->assertForbidden();
    }

    public function test_moderator_cannot_publish(): void
    {
        $this->withSession(['staff' => ['id' => '123', 'name' => 'Mod', 'level' => 'moderator']])
            ->post('/announcements', ['content' => 'Hello'])->assertForbidden();
    }

    public function test_admin_publication_disables_mentions_and_writes_audit(): void
    {
        config()->set('discord.bot_token', 'test');
        config()->set('discord.announcement_channel_id', '123456789012345678');
        Http::fake(['discord.com/*' => Http::response(['id' => '234567890123456789'], 200)]);
        $this->withSession(['staff' => ['id' => '123', 'name' => 'Admin', 'level' => 'admin']])
            ->post('/announcements', ['content' => '@everyone update'])->assertRedirect();
        Http::assertSent(fn ($request) => $request['allowed_mentions'] === ['parse' => []]);
        $this->assertDatabaseHas('portal_audit', ['actor_id' => '123', 'action' => 'announcement.published']);
    }
}
