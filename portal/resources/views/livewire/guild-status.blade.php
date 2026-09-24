<section class="metrics" wire:poll.60s aria-label="Discord статистика">
    <article><small>MEMBERS</small><strong>{{ $guild['approximate_member_count'] ?? '—' }}</strong><span>Общ брой</span></article>
    <article><small>ONLINE NOW</small><strong>{{ $guild['approximate_presence_count'] ?? '—' }}</strong><span>Активни членове</span></article>
    <article><small>BOOSTS</small><strong>{{ $guild['premium_subscription_count'] ?? '—' }}</strong><span>Level {{ $guild['premium_tier'] ?? '—' }}</span></article>
    <article><small>STATUS</small><strong>{{ $guild ? '●' : '○' }}</strong><span>{{ $guild ? 'Discord свързан' : 'Няма актуални данни' }}</span></article>
</section>
