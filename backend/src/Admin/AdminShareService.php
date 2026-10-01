<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Page;
use Glimpse\Repositories\BrowseAccess;
use Glimpse\Sharing\LinkPolicies;
use Glimpse\Sharing\LinkStatus;
use Glimpse\Sharing\ShareUrls;
use Glimpse\Sharing\UnavailableLink;
use PDO;

/** Access links of every owner in the admin panel: the list, and Show and Revoke of any link, without audit events. */
final readonly class AdminShareService
{
    public const PAGE_SIZE = 50;

    public function __construct(private Connection $connection, private ShareUrls $urls, private LinkPolicies $policies) {}

    /** @return Page<AdminLink> */
    public function list(LinkFilter $filter): Page
    {
        [$user, $status, $search, $page] = [$filter->user, $filter->status, $filter->search, $filter->page];
        $state = LinkStatus::STATUS_SQL;
        $where = ['1 = 1'];
        $params = [];
        if ($user !== null) {
            $where[] = 'l.user_id = ?';
            $params[] = $user;
        }
        if ($status !== '') {
            $where[] = $state . ' = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = "(l.name LIKE ? OR CONVERT(CONCAT(r.owner, '/', r.name) USING utf8mb4) LIKE ?)";
            array_push($params, $like, $like);
        }
        $from = ' FROM share_links l JOIN repositories r ON r.id = l.repository_id AND r.user_id = l.user_id JOIN users u ON u.id = l.user_id WHERE '
            . implode(' AND ', $where);
        $pdo = $this->connection->get();
        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $read = $pdo->prepare('SELECT l.id, l.public_id, l.name, l.created_at, l.expires_at, l.revoked_at, l.allowed_branches, l.allow_history,
            ' . $state . ' AS status,
            r.provider AS repository_provider, r.instance_url AS repository_instance, r.owner AS repository_owner, r.name AS repository_name, r.active AS repository_active,
            u.id AS user_id, u.username, u.first_name, u.last_name' . $from
            . ' ORDER BY l.id DESC LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $read->execute($params);
        $rows = $read->fetchAll(PDO::FETCH_ASSOC);
        $visible = array_slice($rows, 0, self::PAGE_SIZE);
        $policies = $this->policies->forLinks(null, array_values(array_map(static fn(array $row): int => (int) $row['id'], $visible)));
        $items = [];
        foreach ($visible as $row) {
            $items[] = new AdminLink(
                (string) $row['public_id'],
                (string) $row['name'],
                ['id' => (int) $row['user_id'], 'username' => (string) $row['username'],
                    'firstName' => $row['first_name'] === null ? null : (string) $row['first_name'], 'lastName' => $row['last_name'] === null ? null : (string) $row['last_name']],
                ['provider' => (string) $row['repository_provider'], 'instance' => (string) $row['repository_instance'],
                    'owner' => (string) $row['repository_owner'], 'name' => (string) $row['repository_name'], 'active' => (bool) $row['repository_active']],
                LinkStatus::from((string) $row['status']),
                Dates::isoOrNull($row['created_at']),
                Dates::isoOrNull($row['expires_at']),
                BrowseAccess::stored((string) $row['allowed_branches']),
                (bool) $row['allow_history'],
                $policies[(int) $row['id']] ?? [],
            );
        }
        return new Page($items, $page, count($rows) > self::PAGE_SIZE ? $page + 1 : null, (int) $count->fetchColumn());
    }

    /**
     * The URL of any owner's active link on an enabled repository.
     *
     * @return array{url: string}
     */
    public function reveal(string $publicId): array
    {
        $pdo = $this->connection->get();
        return Transaction::run($pdo, function () use ($pdo, $publicId): array {
            $read = $pdo->prepare('SELECT l.encrypted_secret, l.secret_digest FROM share_links l
                JOIN repositories r ON r.id = l.repository_id AND r.user_id = l.user_id WHERE l.public_id = ? AND r.active = 1
                AND ' . LinkStatus::ACTIVE_SQL . ' FOR UPDATE');
            $read->execute([$publicId]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw UnavailableLink::failure();
            }
            return ['url' => $this->urls->recover($publicId, (string) $row['encrypted_secret'], (string) $row['secret_digest'])];
        });
    }

    /** Revokes any owner's link with the same effect as the owner's Revoke. */
    public function revoke(string $publicId): void
    {
        $pdo = $this->connection->get();
        $change = $pdo->prepare('UPDATE share_links SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)), encrypted_secret = NULL WHERE public_id = ?');
        $change->execute([$publicId]);
        if ($change->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM share_links WHERE public_id = ?');
            $exists->execute([$publicId]);
            if ($exists->fetchColumn() === false) {
                throw UnavailableLink::failure();
            }
        }
    }
}
