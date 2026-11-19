<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/** @return array<string, string> */
function attendancePickupGuardians(array $row): array
{
    return array_filter([
        'parent' => trim((string) $row['parent_first_name'] . ' ' . (string) $row['parent_last_name']),
        'guardian' => trim((string) ($row['second_guardian_name'] ?? '')),
    ], static fn (string $name): bool => $name !== '');
}

/** The caller must authorize the attendance page and own the surrounding transaction. */
function confirmAttendancePickup(
    PDO $pdo,
    int $childId,
    string $date,
    int $adminId,
    string $guardian,
    string $guardianVersion,
    ?string $requestedCheckout,
    ?string $originalCheckout,
    bool $saveNotes,
    ?string $notes
): string {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.status, a.check_in, a.check_out, a.pickup_verified_by,
                p.first_name AS parent_first_name, p.last_name AS parent_last_name, c.second_guardian_name
         FROM attendance a
         INNER JOIN children c ON c.id = a.child_id
         INNER JOIN parents p ON p.id = c.parent_id
         WHERE a.child_id = ? AND a.attendance_date = ? AND c.status = 'active'
         FOR UPDATE"
    );
    $stmt->execute([$childId, $date]);
    $row = $stmt->fetch();
    if (!$row || $row['check_in'] === null || !in_array($row['status'], ['present', 'late'], true)) {
        throw new InvalidArgumentException('برای تأیید تحویل، ابتدا ورود کودک را ثبت کنید.');
    }
    if ($row['pickup_verified_by'] !== null) {
        throw new InvalidArgumentException('تحویل این کودک قبلاً تأیید شده است.');
    }

    $name = attendancePickupGuardians($row)[$guardian] ?? '';
    if ($name === '' || !hash_equals(hash('sha256', $name), $guardianVersion)) {
        throw new InvalidArgumentException('اطلاعات تحویل‌گیرنده تغییر کرده است. دوباره نام را انتخاب کنید.');
    }

    $checkout = $row['check_out'];
    if ($requestedCheckout !== null && $checkout !== $originalCheckout) {
        throw new InvalidArgumentException('ساعت خروج پس از باز شدن فرم تغییر کرده است. ساعت فعلی را بررسی کنید.');
    }
    if ($requestedCheckout !== null) {
        $checkout = $requestedCheckout;
    }
    $checkout ??= $date === date('Y-m-d') ? date('H:i:s') : null;
    if ($checkout === null) {
        throw new InvalidArgumentException('برای روزهای گذشته ساعت خروج را وارد کنید.');
    }
    portalAttendanceTimes($row['status'], $row['check_in'], $checkout);

    $save = $pdo->prepare(
        'UPDATE attendance SET check_out = ?, picked_up_by = ?, pickup_verified_by = ?,
            notes = IF(?, ?, notes) WHERE id = ?'
    );
    $save->execute([$checkout, $name, $adminId, $saveNotes ? 1 : 0, $notes, (int) $row['id']]);
    return $checkout;
}
