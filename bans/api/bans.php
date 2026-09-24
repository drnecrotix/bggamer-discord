<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/ban-center.php';

bg_require_method('GET');

try {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min((int) ($_GET['per_page'] ?? 6), (int) bg_config('limits.page_size_max', 6)));
    $query = trim((string) ($_GET['q'] ?? ''));
    $statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'active')));
    $dateFrom = bg_validate_date_filter($_GET['date_from'] ?? null);
    $dateTo = bg_validate_date_filter($_GET['date_to'] ?? null);
    $reference = trim((string) ($_GET['reference'] ?? ''));

    if ($reference !== '') {
        $record = bg_fetch_ban_record_by_reference($reference);

        if ($record === null) {
            bg_json([
                'ok' => false,
                'error' => 'not_found',
            ], 404);
        }

        bg_json([
            'ok' => true,
            'item' => bg_normalize_ban_for_public($record),
            'meta' => [
                'generated_at' => gmdate('c'),
            ],
        ]);
    }

    $statusMap = [
        'active' => ['active', 'temporary'],
        'permanent' => ['active'],
        'temporary' => ['temporary'],
        'expired' => ['expired'],
        'unbanned' => ['unbanned'],
        'all' => [],
    ];

    if (!array_key_exists($statusFilter, $statusMap)) {
        $statusFilter = 'active';
    }

    $where = [];
    $params = [];

    if ($statusMap[$statusFilter] !== []) {
        $placeholders = [];

        foreach ($statusMap[$statusFilter] as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params[$key] = $status;
        }

        $where[] = 'status IN (' . implode(', ', $placeholders) . ')';
    }

    if ($query !== '') {
        $params[':query'] = '%' . $query . '%';
        $where[] = '(username LIKE :query OR global_name LIKE :query OR public_reference LIKE :query OR public_reason LIKE :query)';
    }

    if ($dateFrom !== null) {
        $params[':date_from'] = $dateFrom . ' 00:00:00';
        $where[] = '(banned_at IS NOT NULL AND banned_at >= :date_from)';
    }

    if ($dateTo !== null) {
        $params[':date_to'] = $dateTo . ' 23:59:59';
        $where[] = '(banned_at IS NOT NULL AND banned_at <= :date_to)';
    }

    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $pdo = bg_pdo();
    $activeTotal = (int) $pdo
        ->query("SELECT COUNT(*) FROM discord_bans WHERE status IN ('active', 'temporary')")
        ->fetchColumn();

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM discord_bans ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStatement->bindValue($key, $value);
    }

    $countStatement->execute();
    $filteredTotal = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredTotal / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rowsStatement = $pdo->prepare(
        'SELECT public_reference, discord_user_id, username, global_name, avatar_url, public_reason, banned_at, expires_at, status, appeal_status
         FROM discord_bans
         ' . $whereSql . '
         ORDER BY COALESCE(banned_at, created_at) DESC, created_at DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $rowsStatement->bindValue($key, $value);
    }

    $rowsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $rowsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStatement->execute();

    $items = array_map(
        static fn (array $row): array => bg_normalize_ban_for_public($row),
        $rowsStatement->fetchAll()
    );

    $syncState = bg_get_sync_state();

    bg_json([
        'ok' => true,
        'filters' => [
            'q' => $query,
            'status' => $statusFilter,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        'summary' => [
            'active_total' => $activeTotal,
            'filtered_total' => $filteredTotal,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'last_synced_at' => $syncState['synced_at'] ?? null,
        ],
        'items' => $items,
        'meta' => [
            'generated_at' => gmdate('c'),
            'sync' => $syncState,
        ],
    ]);
} catch (Throwable $throwable) {
    bg_log_event('ban-api-errors', [
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    $error = bg_public_exception_payload($throwable, 'service_unavailable', 503);
    bg_json($error['payload'], $error['status']);
}
