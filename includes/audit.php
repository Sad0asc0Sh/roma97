<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/db.php';

/**
 * Create the audit_log table (idempotent).
 */
function initializeAuditTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
    static $initialized = false;
    $initialized = true;
}

/**
 * Resolve the current actor from the session.
 *
 * @return array{0:string,1:?int,2:?string} [actor_type, actor_id, actor_label]
 */
function currentAuditActor(): array
{
    if (!empty($_SESSION['admin_logged_in'])) {
        return ['admin', (int) ($_SESSION['admin_id'] ?? 0) ?: null, (string) ($_SESSION['admin_username'] ?? 'admin')];
    }

    if (!empty($_SESSION['teacher_id'])) {
        return ['teacher', (int) $_SESSION['teacher_id'], (string) ($_SESSION['teacher_name'] ?? 'teacher')];
    }

    if (!empty($_SESSION['parent_id'])) {
        return ['parent', (int) $_SESSION['parent_id'], (string) ($_SESSION['parent_name'] ?? 'parent')];
    }

    return ['system', null, null];
}

/**
 * Record an audit-log entry. Designed to NEVER throw: a logging failure must not
 * break the underlying business action, so all errors are swallowed to error_log.
 *
 * @param string               $action     Dotted action key, e.g. 'news.create', 'auth.login'.
 * @param string|null          $entityType Logical entity, e.g. 'news', 'teacher'.
 * @param int|null             $entityId   Affected entity id, when applicable.
 * @param array<string,mixed>  $details    Extra structured context (stored as JSON).
 * @param array{0:string,1:?int,2:?string}|null $actorOverride Optional explicit actor
 *        (used when the session actor is not yet set, e.g. right after login).
 */
function recordAudit(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = [],
    ?array $actorOverride = null
): void {
    try {
        initializeAuditTable();
        $pdo = getDb();

        [$type, $id, $label] = $actorOverride ?? currentAuditActor();

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (actor_type, actor_id, actor_label, action, entity_type, entity_id, details, ip_address)
             VALUES
                (:atype, :aid, :alabel, :action, :etype, :eid, :details, :ip)'
        );
        $stmt->execute([
            ':atype'   => in_array($type, ['admin', 'teacher', 'parent', 'system'], true) ? $type : 'system',
            ':aid'     => $id,
            ':alabel'  => $label,
            ':action'  => $action,
            ':etype'   => $entityType,
            ':eid'     => $entityId,
            ':details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip'      => $ip !== '' ? substr($ip, 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}
