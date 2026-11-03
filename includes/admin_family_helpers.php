<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Shared helpers for the NEW admin manual family registration pages
 * (admin/add-parent.php, admin/edit-parent.php, admin/add-child.php).
 *
 * Scope note: these helpers are used ONLY by the new admin pages.
 * The existing self-service flow (register.php, parent/add-child.php)
 * and admin/edit-child.php keep their own local helpers untouched —
 * no behaviour change there. The child-field validation below mirrors
 * parent/add-child.php and admin/edit-child.php exactly (same regexes,
 * same limits) so admin-entered data is held to the same rules as
 * parent-entered data, without duplicating the code three more times.
 */

function adminFamilyStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Parent name rule — identical to register.php::isValidParentName(). */
function adminFamilyIsValidParentName(string $name): bool
{
    return $name !== '' && adminFamilyStringLength($name) <= 100
        && preg_match("/^[\p{L}\p{Z}\s\x{200C}'\-]+$/u", $name) === 1;
}

/** Child name rule — identical to parent/add-child.php::isValidChildName(). */
function adminFamilyIsValidChildName(string $name, int $maxLength = 100, bool $required = true): bool
{
    if ($name === '') {
        return !$required;
    }
    return adminFamilyStringLength($name) <= $maxLength
        && preg_match("/^[\p{L}\p{Z}\s'\-]+$/u", $name) === 1;
}

function adminFamilyIsValidChildPhone(string $phone): bool
{
    return $phone === ''
        || (adminFamilyStringLength($phone) <= 20 && preg_match('/\A[0-9+\-\s]{7,20}\z/', $phone) === 1);
}

/**
 * Child date-of-birth rule — Jalali input via parseJalaliDate(), must be
 * before today and not older than 15 years (parent/add-child.php rule,
 * which catches e.g. 1300 instead of 1400 typos). Returns Gregorian Y-m-d.
 */
function adminFamilyValidChildDob(string $value): ?string
{
    $parsed = function_exists('parseJalaliDate') ? parseJalaliDate($value) : null;
    if ($parsed !== null) {
        $value = $parsed;
    }
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }
    $today = new DateTimeImmutable('today');
    if ($date >= $today) {
        return null;
    }
    if ($date < $today->modify('-15 years')) {
        return null;
    }
    return $value;
}

/** Strong password — same rule as register.php (authStrongPassword). */
function adminFamilyIsStrongPassword(string $password): bool
{
    if (function_exists('authStrongPassword')) {
        return authStrongPassword($password);
    }
    return strlen($password) >= 8 && strlen($password) <= 72
        && !str_contains($password, "\0")
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

/**
 * Normalise one raw child block (from $_POST) into the canonical shape.
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function adminFamilyNormalizeChildInput(array $raw): array
{
    $status = (string) ($raw['status'] ?? 'active');
    if (!in_array($status, ['pending', 'active', 'inactive', 'graduated', 'withdrawn'], true)) {
        $status = 'active';
    }
    return [
        'first_name' => trim((string) ($raw['first_name'] ?? '')),
        'last_name' => trim((string) ($raw['last_name'] ?? '')),
        'preferred_name' => trim((string) ($raw['preferred_name'] ?? '')),
        'date_of_birth' => trim((string) ($raw['date_of_birth'] ?? '')),
        'gender' => (string) ($raw['gender'] ?? ''),
        'allergies' => trim((string) ($raw['allergies'] ?? '')),
        'medical_notes' => trim((string) ($raw['medical_notes'] ?? '')),
        'second_guardian_name' => trim((string) ($raw['second_guardian_name'] ?? '')),
        'second_guardian_phone' => trim((string) ($raw['second_guardian_phone'] ?? '')),
        'status' => $status,
        'classroom_id' => (int) ($raw['classroom_id'] ?? 0),
    ];
}

/** A child block the admin added but left completely blank is not a child. */
function adminFamilyChildBlockIsEmpty(array $child): bool
{
    foreach (['first_name', 'last_name', 'preferred_name', 'date_of_birth', 'allergies', 'medical_notes', 'second_guardian_name', 'second_guardian_phone'] as $k) {
        if (trim((string) ($child[$k] ?? '')) !== '') {
            return false;
        }
    }
    return (int) ($child['classroom_id'] ?? 0) === 0
        && (string) ($child['gender'] ?? '') === '';
}

/**
 * Validate one normalised child block.
 * @param array<string,mixed> $child
 * @param string $label Human label for error prefix, e.g. «کودک ۱»
 * @return array{0: list<string>, 1: ?string} [errors, gregorianDob]
 */
function adminFamilyValidateChild(array $child, string $label = 'کودک'): array
{
    $errors = [];
    if (!adminFamilyIsValidChildName((string) $child['first_name'])) {
        $errors[] = "{$label}: لطفاً نام معتبر وارد کنید.";
    }
    if (!adminFamilyIsValidChildName((string) $child['last_name'])) {
        $errors[] = "{$label}: لطفاً نام خانوادگی معتبر وارد کنید.";
    }
    if (!adminFamilyIsValidChildName((string) $child['preferred_name'], 100, false)) {
        $errors[] = "{$label}: لطفاً نام مستعار معتبر وارد کنید.";
    }
    $dob = adminFamilyValidChildDob((string) $child['date_of_birth']);
    if ($dob === null) {
        $errors[] = "{$label}: لطفاً تاریخ تولد معتبر و قبل از امروز وارد کنید.";
    }
    if (!in_array((string) $child['gender'], ['', 'male', 'female', 'other'], true)) {
        $errors[] = "{$label}: لطفاً جنسیت معتبر انتخاب کنید.";
    }
    if (!in_array((string) $child['status'], ['pending', 'active', 'inactive', 'graduated', 'withdrawn'], true)) {
        $errors[] = "{$label}: لطفاً وضعیت معتبر انتخاب کنید.";
    }
    if (adminFamilyStringLength((string) $child['allergies']) > 5000) {
        $errors[] = "{$label}: توضیحات حساسیت‌ها باید حداکثر ۵۰۰۰ کاراکتر باشد.";
    }
    if (adminFamilyStringLength((string) $child['medical_notes']) > 5000) {
        $errors[] = "{$label}: توضیحات پزشکی باید حداکثر ۵۰۰۰ کاراکتر باشد.";
    }
    if (!adminFamilyIsValidChildName((string) $child['second_guardian_name'], 200, false)) {
        $errors[] = "{$label}: لطفاً نام معتبر برای ولی دوم وارد کنید.";
    }
    if (!adminFamilyIsValidChildPhone((string) $child['second_guardian_phone'])) {
        $errors[] = "{$label}: لطفاً شماره تلفن معتبر برای ولی دوم وارد کنید.";
    }
    return [$errors, $dob];
}

function adminFamilyParentPhoneExists(PDO $pdo, string $phone, int $excludeId = 0): bool
{
    $stmt = $pdo->prepare('SELECT id FROM parents WHERE phone = :phone AND id != :eid LIMIT 1');
    $stmt->execute([':phone' => $phone, ':eid' => $excludeId]);
    return $stmt->fetch() !== false;
}

function adminFamilyParentEmailExists(PDO $pdo, string $email, int $excludeId = 0): bool
{
    if ($email === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id FROM parents WHERE email = :email AND id != :eid LIMIT 1');
    $stmt->execute([':email' => $email, ':eid' => $excludeId]);
    return $stmt->fetch() !== false;
}

/** Photo upload — mirrors admin/edit-child.php::uploadAdminChildPhoto(). */
function adminFamilyUploadChildPhoto(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری تصویر کودک با مشکل مواجه شد.');
    }
    if (($file['size'] ?? 0) > 512000) {
        throw new RuntimeException('حجم تصویر کودک باید ۵۰۰ کیلوبایت یا کمتر باشد.');
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('بارگذاری تصویر کودک نامعتبر است.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];
    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('تصویر کودک باید با فرمت JPG، PNG یا GIF باشد.');
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('نوع تصویر کودک نامعتبر است.');
        }
    }
    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('تصویر کودک نامعتبر است.');
    }
    $uploadDir = __DIR__ . '/../assets/uploads/children';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری تصویر در دسترس نیست.');
    }
    $indexFile = $uploadDir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($indexFile)) {
        @touch($indexFile);
    }
    $htaccess = dirname($uploadDir) . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
    }
    $fileName = 'child-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری تصویر در دسترس نیست.');
    }
    return 'assets/uploads/children/' . $fileName;
}

function adminFamilyDeleteChildPhoto(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    $projectRoot = realpath(__DIR__ . '/..');
    $childrenRoot = realpath(__DIR__ . '/../assets/uploads/children');
    if ($projectRoot === false || $childrenRoot === false) {
        return;
    }
    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    if ($candidate !== false
        && str_starts_with($candidate, $childrenRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)) {
        @unlink($candidate);
    }
}

/**
 * Insert one validated child (+ classroom enrolment) inside the caller's
 * transaction. Enrolment follows admin/edit-child.php: only ACTIVE
 * children are enrolled into a classroom.
 *
 * @param array<string,mixed> $child Normalised child with 'dob_gregorian' set.
 */
function adminFamilyInsertChild(PDO $pdo, int $parentId, array $child, ?string $photoPath = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO children (parent_id, first_name, last_name, preferred_name, date_of_birth, gender, allergies, medical_notes, second_guardian_name, second_guardian_phone, photo, status)
         VALUES (:pid, :fn, :ln, :pn, :dob, :g, :al, :mn, :sgn, :sgp, :photo, :st)'
    );
    $stmt->execute([
        ':pid' => $parentId,
        ':fn' => (string) $child['first_name'],
        ':ln' => (string) $child['last_name'],
        ':pn' => ((string) $child['preferred_name'] !== '') ? (string) $child['preferred_name'] : null,
        ':dob' => (string) ($child['dob_gregorian'] ?? $child['date_of_birth']),
        ':g' => (string) $child['gender'],
        ':al' => ((string) $child['allergies'] !== '') ? (string) $child['allergies'] : null,
        ':mn' => ((string) $child['medical_notes'] !== '') ? (string) $child['medical_notes'] : null,
        ':sgn' => ((string) $child['second_guardian_name'] !== '') ? (string) $child['second_guardian_name'] : null,
        ':sgp' => ((string) $child['second_guardian_phone'] !== '') ? (string) $child['second_guardian_phone'] : null,
        ':photo' => $photoPath,
        ':st' => (string) $child['status'],
    ]);
    $childId = (int) $pdo->lastInsertId();
    $classroomId = (int) ($child['classroom_id'] ?? 0);
    if ($classroomId > 0 && (string) $child['status'] === 'active') {
        $ins = $pdo->prepare(
            'INSERT INTO child_classroom (child_id, classroom_id, enrollment_date) VALUES (:cid, :clid, :dt)'
        );
        $ins->execute([':cid' => $childId, ':clid' => $classroomId, ':dt' => date('Y-m-d')]);
    }
    return $childId;
}

/**
 * Create a parent + all children + enrolments in ONE transaction.
 * All-or-nothing: any failure rolls the parent back too.
 *
 * @param array<string,mixed> $parent Keys: first_name, last_name, email(?string),
 *                                    phone (normalised), password_hash, status, phone_verified
 * @param list<array<string,mixed>> $children Normalised children with dob_gregorian.
 * @return array{0:int,1:list<int>} [parentId, childIds]
 */
function adminFamilyCreateParentWithChildren(PDO $pdo, array $parent, array $children): array
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO parents (first_name, last_name, email, phone, phone_verified, password, status, email_verified)
             VALUES (:fn, :ln, :em, :ph, :pv, :pw, :st, 0)"
        );
        $stmt->execute([
            ':fn' => (string) $parent['first_name'],
            ':ln' => (string) $parent['last_name'],
            ':em' => (($parent['email'] ?? null) !== null && (string) $parent['email'] !== '') ? (string) $parent['email'] : null,
            ':ph' => (string) $parent['phone'],
            ':pv' => !empty($parent['phone_verified']) ? 1 : 0,
            ':pw' => (string) $parent['password_hash'],
            ':st' => (string) ($parent['status'] ?? 'active'),
        ]);
        $parentId = (int) $pdo->lastInsertId();
        $childIds = [];
        foreach ($children as $child) {
            $childIds[] = adminFamilyInsertChild($pdo, $parentId, $child, null);
        }
        if ($ownTx) {
            $pdo->commit();
        }
        return [$parentId, $childIds];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Create one child for an existing parent (+ enrolment) in ONE transaction.
 * @param array<string,mixed> $child Normalised child with dob_gregorian.
 */
function adminFamilyCreateChild(PDO $pdo, int $parentId, array $child, ?string $photoPath = null): int
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $childId = adminFamilyInsertChild($pdo, $parentId, $child, $photoPath);
        if ($ownTx) {
            $pdo->commit();
        }
        return $childId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
