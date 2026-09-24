<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use App\Models\SupportTicket;
use App\Models\BanAppeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_command_and_faq_page_shows_only_confirmed_entries(): void
    {
        KnowledgeItem::create(['type' => 'command', 'title' => '/help', 'body' => 'Команди на бота.']);
        KnowledgeItem::create(['type' => 'faq', 'title' => 'Как да обжалвам?', 'body' => 'Отвори формуляра за обжалване.']);
        $this->get('/commands')->assertOk()->assertSee('/help')->assertSee('Как да обжалвам?');
    }

    public function test_support_ticket_is_private_and_requires_valid_input(): void
    {
        $this->post('/support', ['email' => 'test@example.com', 'subject' => 'Help', 'message' => 'short'])
            ->assertSessionHasErrors();
        $this->post('/support', [
            'email' => 'test@example.com',
            'subject' => 'Нужна помощ',
            'message' => 'Това е подробно запитване за нашия Discord сървър.',
            'website' => '',
        ])->assertRedirect('/support');
        $this->assertSame(1, SupportTicket::count());
        $this->get('/admin/tickets')->assertForbidden();
    }

    public function test_ban_appeal_can_be_submitted_and_checked_without_public_listing(): void
    {
        $this->post('/appeals', [
            'discord_user_id' => '123456789012345678',
            'discord_username' => 'Player',
            'contact' => 'player@example.com',
            'appeal_reason' => 'Искам нов преглед',
            'additional_information' => 'Обяснявам подробно контекста и причините за преглед.',
        ])->assertRedirect('/appeals');
        $appeal = BanAppeal::firstOrFail();
        $this->post('/appeals/check', [
            'reference' => $appeal->public_reference,
            'discord_user_id' => '123456789012345678',
        ])->assertSessionHas('lookup');
        $this->get('/admin/appeals')->assertForbidden();
    }
}
