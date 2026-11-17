<?php
/**
 * MESSAGES (customer <-> shop)
 * ----------------------------
 * One conversation per customer, stored in the `messages` table (customer_id = the thread).
 * from_staff = 1 when an owner/admin/staff wrote it. The shop team shares one inbox, so any of them can reply.
 * read_at is set when the other side opens the conversation.
 */
const MSG_MAX = 1000;

/** Number of unread messages for this user (customer: replies from the shop; staff: messages from customers). */
function chat_unread(PDO $pdo, array $user): int {
    if (in_array($user['role'], STAFF_ROLES, true)) {
        return (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE from_staff = 0 AND read_at IS NULL')->fetchColumn();
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE customer_id = ? AND from_staff = 1 AND read_at IS NULL');
    $st->execute([$user['id']]);
    return (int)$st->fetchColumn();
}

/** Messages of one conversation newer than $afterId, oldest first. */
function chat_fetch(PDO $pdo, int $customerId, int $afterId = 0): array {
    $st = $pdo->prepare('SELECT m.id, m.from_staff, m.body, m.created_at, u.name AS sender
        FROM messages m JOIN users u ON u.id = m.sender_id
        WHERE m.customer_id = ? AND m.id > ? ORDER BY m.id LIMIT 200');
    $st->execute([$customerId, $afterId]);
    return $st->fetchAll();
}

/** The viewer opened the conversation: mark what the other side wrote as read. */
function chat_mark_read(PDO $pdo, int $customerId, bool $viewerIsStaff): void {
    $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE customer_id = ? AND from_staff = ? AND read_at IS NULL')
        ->execute([$customerId, $viewerIsStaff ? 0 : 1]);
}

/** Save a message. Returns an error text, or null on success. */
function chat_send(PDO $pdo, int $customerId, array $sender, string $body): ?string {
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') return 'Type a message first.';
    if (mb_strlen($body) > MSG_MAX) return 'Message is too long (max ' . MSG_MAX . ' characters).';
    $isStaff = in_array($sender['role'], STAFF_ROLES, true);
    // Basic flood limit: at most 20 messages per minute per sender.
    $st = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $st->execute([$sender['id']]);
    if ((int)$st->fetchColumn() >= 20) return 'You are sending too fast. Wait a moment.';
    $pdo->prepare('INSERT INTO messages (customer_id, sender_id, from_staff, body) VALUES (?,?,?,?)')
        ->execute([$customerId, $sender['id'], $isStaff ? 1 : 0, $body]);
    return null;
}

/** Shared chat box markup: bubbles rendered on the server, assets/js/chat.js keeps it live. */
function chat_render_thread(array $rows, bool $viewerIsStaff, int $customerId): void {
    $last = $rows ? (int)end($rows)['id'] : 0;
    ?>
    <div class="chat-box" id="chat" data-api="<?= e(url('api/messages.php')) ?>" data-customer="<?= $customerId ?>" data-last="<?= $last ?>" data-staff="<?= $viewerIsStaff ? '1' : '0' ?>">
      <div class="chat-log" id="chatLog" aria-live="polite">
        <?php if (!$rows): ?><p class="chat-empty text-muted" id="chatEmpty">No messages yet. Say hello!</p><?php endif; ?>
        <?php foreach ($rows as $m): $mine = ((int)$m['from_staff'] === 1) === $viewerIsStaff; ?>
          <div class="chat-msg <?= $mine ? 'mine' : 'theirs' ?>">
            <div class="chat-meta"><?= e($m['from_staff'] ? 'Eayila Carwash · ' . $m['sender'] : $m['sender']) ?> · <?= e(date('M j, g:i A', strtotime($m['created_at']))) ?></div>
            <div class="chat-bubble"><?= nl2br(e($m['body'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" class="chat-form" id="chatForm">
        <?= csrf_field() ?>
        <textarea class="form-control" name="body" id="chatBody" rows="2" maxlength="<?= MSG_MAX ?>" placeholder="Type a message…" required></textarea>
        <button class="btn btn-red chat-send"><?= icon('send') ?> Send</button>
      </form>
    </div>
    <?php
}
