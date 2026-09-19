<?php
declare(strict_types=1);

/**
 * B1 — Role matrix must fail CLOSED for unknown roles.
 *
 * Regression test for: `$matrix[$role] ?? $matrix['owner']` in
 * includes/auth.php::isPageAllowedForRole(), which silently granted owner-level
 * access to any unexpected role value (legacy DB rows, typos).
 *
 * Fixed behavior: unknown roles resolve to an empty page list (deny-all),
 * isKnownAdminRole() exposes the check, and currentAdminRole() defaults to ''
 * instead of 'owner'.
 *
 * Pure-function test — no database needed.
 *
 * Run: php tests/role-matrix-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';

defined('ROOMA_APP') || define('ROOMA_APP', true);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/permissions.php';

echo "B1 role-matrix fail-closed tests\n";

// ── A. Known roles keep their exact behavior (regression guard) ──────────────
testAssert(isPageAllowedForRole('admin/index.php', 'owner') === true, 'owner can access admin/index.php');
testAssert(isPageAllowedForRole('admin/settings.php', 'owner') === true, 'owner can access admin/settings.php');
testAssert(isPageAllowedForRole('admin/index.php', 'receptionist') === true, 'receptionist can access admin/index.php');
testAssert(isPageAllowedForRole('admin/backup.php', 'receptionist') === false, 'receptionist denied admin/backup.php');
testAssert(isPageAllowedForRole('admin/settings.php', 'manager') === false, 'manager denied admin/settings.php');
testAssert(isPageAllowedForRole('admin/tuition.php', 'accountant') === true, 'accountant can access admin/tuition.php');

// ── B. Unknown roles are denied EVERYWHERE (the actual fix) ─────────────────
testAssert(isPageAllowedForRole('admin/index.php', 'superadmin') === false, "unknown role 'superadmin' denied admin/index.php");
testAssert(isPageAllowedForRole('admin/backup.php', 'superadmin') === false, "unknown role 'superadmin' denied admin/backup.php");
testAssert(isPageAllowedForRole('admin/settings.php', 'superadmin') === false, "unknown role 'superadmin' denied admin/settings.php");
testAssert(isPageAllowedForRole('admin/index.php', '') === false, "empty role denied admin/index.php");
testAssert(isPageAllowedForRole('admin/index.php', 'Owner') === false, "case-variant 'Owner' denied (exact match)");
testAssert(isPageAllowedForRole('admin/index.php', 'owner ') === false, "trailing-space 'owner ' denied (exact match)");
testAssert(isPageAllowedForRole('admin/settings.php', 'root') === false, "unknown role 'root' denied admin/settings.php");
testAssert(isPageAllowedForRole('admin/tuition.php', 'admin') === false, "unknown role 'admin' denied admin/tuition.php");

// ── C. isKnownAdminRole() ───────────────────────────────────────────────────
testAssert(isKnownAdminRole('owner') === true, "isKnownAdminRole('owner')");
testAssert(isKnownAdminRole('manager') === true, "isKnownAdminRole('manager')");
testAssert(isKnownAdminRole('accountant') === true, "isKnownAdminRole('accountant')");
testAssert(isKnownAdminRole('receptionist') === true, "isKnownAdminRole('receptionist')");
testAssert(isKnownAdminRole('superadmin') === false, "isKnownAdminRole('superadmin') === false");
testAssert(isKnownAdminRole('') === false, "isKnownAdminRole('') === false");
testAssert(isKnownAdminRole('Owner') === false, "isKnownAdminRole('Owner') === false");

// ── D. currentAdminRole() fails closed on missing role ───────────────────────
unset($_SESSION['admin_role']);
testAssert(currentAdminRole() === '', 'missing session role resolves to empty string (not owner)');
$_SESSION['admin_role'] = 'manager';
testAssert(currentAdminRole() === 'manager', 'session role manager resolves correctly');
$_SESSION['admin_role'] = 'superadmin';
testAssert(currentAdminRole() === 'superadmin', 'unknown session role passes through for requireLogin guard');
unset($_SESSION['admin_role']);

// ── E. Matrix invariants ────────────────────────────────────────────────────
$matrix = adminPermissionsMatrix();
testAssert(count($matrix) === 4, 'matrix defines exactly 4 roles');

$ownerPages = $matrix['owner'];
$allOther = [];
foreach ($matrix as $role => $pages) {
    if ($role === 'owner') {
        continue;
    }
    $allOther = array_merge($allOther, $pages);
}
$allOther = array_unique($allOther);
$missing = array_diff($allOther, $ownerPages);
testAssert($missing === [], 'owner page list is a superset of every other role (least-privilege hierarchy)');

$badFormat = [];
$dupes = [];
foreach ($matrix as $role => $pages) {
    foreach ($pages as $p) {
        if (!preg_match('#\Aadmin/[a-z0-9-]+\.php\z#', $p)) {
            $badFormat[] = "{$role}:{$p}";
        }
    }
    if (count($pages) !== count(array_unique($pages))) {
        $dupes[] = $role;
    }
}
testAssert($badFormat === [], 'every matrix page matches admin/<name>.php');
testAssert($dupes === [], 'no duplicate pages inside any role list');

// Unknown pages stay denied even for owner (guards the in_array strictness).
testAssert(isPageAllowedForRole('admin/definitely-not-here.php', 'owner') === false, 'unknown page denied for owner');
testAssert(isPageAllowedForRole('admin/../config.php', 'owner') === false, 'path-traversal page denied for owner');
testAssert(isPageAllowedForRole('', 'owner') === false, 'empty page denied for owner');

exit(testSummary('role-matrix-test'));
