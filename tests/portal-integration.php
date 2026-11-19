<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    $request = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/\Aroma_portal_test_[a-f0-9]{12}\z/', $request['database'])) { exit(2); }
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', $request['database']);
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DEVELOPMENT_MODE', true);
    define('SESSION_SAVE_PATH', $request['directory']);
    define('ERROR_LOG_PATH', $request['directory'] . '/errors.log');
    define('SITE_URL', 'http://127.0.0.1:8097');
    require $root . '/config.php';
    require $root . '/includes/auth.php';
    require $root . '/includes/csrf.php';
    $_SESSION = $request['session'];
    $_SERVER['REQUEST_METHOD'] = $request['post'] === [] ? 'GET' : 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['DOCUMENT_ROOT'] = $root;
    $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $request['page'];
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $request['page'];
    $_GET = $request['get'];
    $_POST = $request['post'];
    if ($_POST !== []) { $_POST['csrf_token'] = generateCsrfToken(); }
    register_shutdown_function(static function () use ($request): void {
        file_put_contents($request['directory'] . '/result.json', json_encode(['session' => $_SESSION, 'status' => http_response_code()]));
    });
    require $root . '/' . $request['page'];
    exit;
}

$database = 'roma_portal_test_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $database;
mkdir($directory, 0700);
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$keep = in_array('--preview', $argv, true);
$count = 0;
$assert = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $count++;
    echo "PASS: {$label}\n";
};
$request = static function (string $page, array $session, array $post = [], array $get = []) use ($database, $directory): array {
    $payload = compact('database', 'directory', 'page', 'session', 'post', 'get');
    file_put_contents($directory . '/request.json', json_encode($payload));
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $directory . '/request.json'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || preg_match('/Fatal error|Uncaught |Warning:|Deprecated:/', $html . $stderr)) {
        throw new RuntimeException("Page failed: {$page}: {$stderr}\n" . mb_substr(strip_tags($html), 0, 1000));
    }
    $result = json_decode(file_get_contents($directory . '/result.json'), true);
    return [$html, $result['session'], $result['status']];
};

try {
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");
    $schema = explode('-- ─── Post-create migrations', file_get_contents($root . '/schema.sql'))[0];
    $schema = preg_replace('/^--.*$/m', '', $schema);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $sql) {
        if (trim($sql) !== '') { $pdo->exec($sql); }
    }
    $pdo->exec("INSERT INTO settings (meta_key, meta_value) VALUES ('sms_enabled','0'),('site_name','روما'),('default_tuition_amount','2500000')");
    $password = password_hash('RomaPreview2026!', PASSWORD_DEFAULT);
    $adminInsert = $pdo->prepare('INSERT INTO admins (id, username, password, role) VALUES (?,?,?,?)');
    foreach ([[1,'preview_owner','owner'],[2,'preview_accountant','accountant'],[3,'preview_reception','receptionist'],[4,'preview_manager','manager']] as [$id,$name,$role]) {
        $adminInsert->execute([$id,$name,$password,$role]);
    }
    $parentInsert = $pdo->prepare("INSERT INTO parents (id, first_name, last_name, phone, phone_verified, password, status) VALUES (?,?,?, ?,1,?,'active')");
    $parentInsert->execute([1,'نگار','احمدی','09120000001',$password]);
    $parentInsert->execute([2,'سارا','رضایی','09120000002',$password]);
    $teacherInsert = $pdo->prepare("INSERT INTO teachers (id,first_name,last_name,phone,password,status) VALUES (1,'مریم','کریمی','09120000003',?,'active')");
    $teacherInsert->execute([$password]);
    $pdo->exec("INSERT INTO classrooms (id,name,teacher_id,capacity) VALUES (1,'شکوفه‌ها',1,12),(2,'آفتابگردان',1,10),(3,'باران',NULL,8)");
    $pdo->exec("INSERT INTO children (id,parent_id,first_name,last_name,date_of_birth,status,allergies) VALUES
        (1,1,'آوا','احمدی','2022-05-20','active','حساسیت به بادام‌زمینی'),
        (2,1,'آراد','احمدی','2021-03-10','active',NULL),
        (3,2,'نیلا','رضایی','2022-09-15','active',NULL),
        (4,2,'باران','رضایی','2023-02-11','pending',NULL)");
    $localToday = new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran'));
    $today = $localToday->format('Y-m-d');
    $tomorrow = $localToday->modify('+1 day')->format('Y-m-d');
    $pdo->prepare('INSERT INTO child_classroom (child_id,classroom_id,enrollment_date) VALUES (1,1,?),(2,1,?),(3,2,?)')
        ->execute([$today, $today, $today]);
    $pdo->exec("INSERT INTO events (title,event_date,status,category) VALUES ('جشن دوستی و قصه‌گویی',DATE_ADD(CURDATE(),INTERVAL 3 DAY),'scheduled','celebration')");
    $owner = ['admin_logged_in'=>true,'admin_id'=>1,'admin_role'=>'owner','admin_username'=>'مدیر روما'];
    $accountant = ['admin_logged_in'=>true,'admin_id'=>2,'admin_role'=>'accountant','admin_username'=>'حسابدار'];
    $reception = ['admin_logged_in'=>true,'admin_id'=>3,'admin_role'=>'receptionist','admin_username'=>'پذیرش'];
    $manager = ['admin_logged_in'=>true,'admin_id'=>4,'admin_role'=>'manager','admin_username'=>'مدیر داخلی'];
    $teacher = ['teacher_id'=>1,'teacher_classroom_id'=>1,'teacher_name'=>'مریم کریمی'];
    $parent = ['parent_id'=>1,'parent_name'=>'نگار احمدی','parent_first_name'=>'نگار'];
    $otherParent = ['parent_id'=>2,'parent_name'=>'سارا رضایی','parent_first_name'=>'سارا'];

    [$html] = $request('admin/index.php', $owner);
    $assert(str_contains($html, 'کلاس‌های بدون روز کاری') && !str_contains($html, 'filter=missing'),
        'Existing classes need explicit workdays before triggering missing attendance');
    $allWorkdays = range(0, 6);
    $class1Settings = ['action'=>'edit','classroom_id'=>1,'name'=>'شکوفه‌ها','teacher_id'=>1,'capacity'=>12,'workdays_form'=>1];
    $class2Settings = ['action'=>'edit','classroom_id'=>2,'name'=>'آفتابگردان','teacher_id'=>1,'capacity'=>10,'workdays_form'=>1];
    $request('admin/classrooms.php', $owner, $class1Settings + ['workdays'=>$allWorkdays]);
    $request('admin/classrooms.php', $owner, $class2Settings + ['workdays'=>array_diff($allWorkdays, [(int) $localToday->format('N') - 1])]);
    [$html] = $request('admin/attendance.php', $reception, [], ['filter'=>'expected','classroom_id'=>2]);
    $assert(!str_contains($html, 'attendance[3][status]') && str_contains($html, 'کودکی برای حضور در این روز انتظار نمی‌رود'),
        'A classroom with a schedule but no workday today has no expected children');
    $request('admin/classrooms.php', $owner, $class2Settings + ['workdays'=>$allWorkdays]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM classroom_workdays')->fetchColumn() === 14,
        'Saving a class replaces its structured workdays without duplicates');

    foreach (['admin'=>$owner,'parent'=>$parent,'teacher'=>$teacher] as $portal=>$session) {
        foreach (glob($root . '/' . $portal . '/*.php') as $file) {
            $name = basename($file);
            if (in_array($name, ['header.php','footer.php','login.php','logout.php','auto-login.php','export-csv.php','delete-parent.php','child-action.php'], true)) continue;
            [$html,,$status] = $request($portal . '/' . $name, $session, [], ['id'=>1]);
            $assert($status !== 500 && $status !== 503, $portal . '/' . $name . ' renders without runtime errors');
        }
    }
    [$html] = $request('admin/index.php', $accountant);
    $assert(!str_contains($html,'آوا') && !str_contains($html,'admin/child-detail.php'), 'Accountant dashboard does not disclose child records');
    $assert(!str_contains($html, 'data-directory-search'), 'Global directory search is limited to roles with access to the child directory');
    [$html] = $request('admin/index.php', $reception);
    $assert(!str_contains($html,'دریافتی شهریه') && !str_contains($html,'admin/tuition.php'), 'Reception dashboard hides finance data and links');
    [,,$status] = $request('admin/export-csv.php', $reception, [], ['type'=>'salary']);
    $assert($status === 403, 'Reception cannot bypass finance permission through CSV export');

    $request('parent/messages.php', $parent, ['action'=>'reply','body'=>'پیگیری گزارش آوا','subject'=>'گزارش روزانه','recipient_admin_id'=>'1']);
    $replyId = (int) $pdo->query("SELECT id FROM messages WHERE sender_type='parent' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $assert($replyId > 0, 'Parent reply saves with native prepared statements');
    $request('parent/messages.php', $parent, [], ['view'=>$replyId]);
    $assert((int) $pdo->query("SELECT is_read FROM messages WHERE id={$replyId}")->fetchColumn() === 0, 'Parent opening own sent message does not mark it read by management');
    [$html] = $request('admin/messages.php', $reception);
    $assert(!str_contains($html,'پیگیری گزارش آوا'), 'Private reply appears only for addressed management account');
    $request('admin/messages.php', $owner, ['action'=>'mark_reply_read','reply_id'=>$replyId]);
    $assert((int) $pdo->query("SELECT is_read FROM messages WHERE id={$replyId} AND follow_up_status='open' AND follow_up_admin_id=1")->fetchColumn() === 1,
        'Reading a reply leaves it open and assigned to its recipient');
    $request('parent/messages.php', $parent, ['action'=>'reply','body'=>'اطلاعات درخواست جدید','subject'=>'پیگیری پذیرش']);
    $generalReply = (int) $pdo->query("SELECT MAX(id) FROM messages WHERE sender_type='parent'")->fetchColumn();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $general = $pdo->query("SELECT follow_up_admin_id, response_due_at FROM messages WHERE id={$generalReply}")->fetch();
    $assert((int) $general['follow_up_admin_id'] === 3
        && abs((new DateTimeImmutable($general['response_due_at'], $now->getTimezone()))->getTimestamp() - $now->modify('+24 hours')->getTimestamp()) < 60,
        'New general replies receive a receptionist and a 24-hour response deadline');

    $request('parent/messages.php', $otherParent, ['action'=>'reply','body'=>'درخواست اختصاصی خانواده','subject'=>'پیام ویژه برای پذیرش','recipient_admin_id'=>'3']);
    $privateReply = (int) $pdo->query("SELECT MAX(id) FROM messages WHERE sender_type='parent'")->fetchColumn();
    [$html] = $request('admin/index.php', $manager);
    [$futureInbox] = $request('admin/messages.php', $manager, [], ['filter'=>'overdue']);
    $assert(!str_contains($html, 'messages.php?filter=overdue#family-inbox') && !str_contains($futureInbox, 'پیام ویژه برای پذیرش'),
        'Unresolved messages with a future deadline are not escalated to the manager');
    $updateReply = ['action'=>'save_follow_up','reply_id'=>$privateReply,'follow_up_status'=>'in_progress','follow_up_admin_id'=>'3',
        'response_due_at'=>$now->modify('-1 hour')->format('Y-m-d\TH:i')];
    $request('admin/messages.php', $reception, $updateReply);
    [$html] = $request('admin/index.php', $manager);
    [$overdueInbox] = $request('admin/messages.php', $manager, [], ['filter'=>'overdue']);
    $assert(str_contains($html, 'messages.php?filter=overdue#family-inbox') && str_contains($overdueInbox, 'پیام ویژه برای پذیرش')
        && !str_contains($overdueInbox, 'پیگیری پذیرش'), 'The manager can act on a past-due assigned message while other messages stay out of that queue');
    $request('admin/messages.php', $manager, array_replace($updateReply, ['follow_up_status'=>'resolved']), ['filter'=>'overdue']);
    [$html] = $request('admin/index.php', $manager);
    [$archive] = $request('admin/messages.php', $reception, [], ['filter'=>'resolved']);
    $assert(!str_contains($html, 'messages.php?filter=overdue#family-inbox') && str_contains($archive, 'پیام ویژه برای پذیرش'),
        'Resolving a message removes its escalation and keeps it in the assigned inbox archive');
    $request('admin/messages.php', $reception, array_replace($updateReply, ['reply_id'=>$generalReply,'follow_up_admin_id'=>'4']));
    [$html] = $request('admin/messages.php', $manager);
    $assert((int) $pdo->query("SELECT follow_up_admin_id FROM messages WHERE id={$generalReply}")->fetchColumn() === 4
        && str_contains($html, 'پیگیری پذیرش'), 'A staff member can transfer responsibility to another authorized staff account');
    $pdo->prepare('UPDATE messages SET response_due_at = ? WHERE id = ?')->execute([$now->modify('+24 hours')->format('Y-m-d H:i:s'), $generalReply]);
    $pdo->exec("INSERT INTO messages(sender_type,sender_id,parent_id,subject,body) VALUES('admin',1,NULL,'خبر عمومی','فردا روز نقاشی است')");
    $broadcast = (int) $pdo->lastInsertId();
    $request('parent/messages.php', $parent, [], ['view'=>$broadcast]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM message_reads WHERE message_id={$broadcast} AND parent_id=1")->fetchColumn() === 1, 'Broadcast read receipt belongs to first family');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM message_reads WHERE message_id={$broadcast} AND parent_id=2")->fetchColumn() === 0, 'Reading broadcast leaves second family unread');

    $lateNotice = ['action'=>'save_notice','child_id'=>1,'notice_date'=>$today,'notice_type'=>'late',
        'expected_arrival'=>'10:15','note'=>'قرار آزمایشی خانواده'];
    $request('parent/attendance.php', $parent, $lateNotice);
    $request('parent/attendance.php', $otherParent, array_replace($lateNotice, ['expected_arrival'=>'11:30']));
    $notice = $pdo->query('SELECT parent_id, expected_arrival FROM attendance_notices WHERE child_id=1')->fetch();
    $assert((int) $notice['parent_id'] === 1 && $notice['expected_arrival'] === '10:15:00'
        && (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 0,
        'An authorized family can announce a late arrival without creating an attendance record');
    [$html] = $request('admin/attendance.php', $reception, [], ['filter'=>'missing','classroom_id'=>1]);
    [$teacherHtml] = $request('teacher/attendance.php', $teacher, [], ['classroom_id'=>1,'filter'=>'notices']);
    $assert(str_contains($html, 'attendance[1][status]') && str_contains($html, 'قرار آزمایشی خانواده')
        && !str_contains($html, 'attendance[3][status]') && str_contains($teacherHtml, 'ورود حدود ۱۰:۱۵')
        && !preg_match('/name="attendance\[1\]\[status\]"[^>]*\bchecked\b/', $teacherHtml),
        'Announced lateness remains expected, is visible to staff, and has no preselected attendance');
    $request('parent/attendance.php', $parent, array_replace($lateNotice, ['notice_type'=>'absent']));
    [$html] = $request('admin/attendance.php', $reception, [], ['filter'=>'missing','classroom_id'=>1]);
    [$noticesHtml] = $request('admin/attendance.php', $reception, [], ['filter'=>'notices','classroom_id'=>1]);
    $assert(!str_contains($html, 'attendance[1][status]') && str_contains($html, 'attendance[2][status]')
        && str_contains($noticesHtml, 'غیبت اعلام‌شده از طرف خانواده')
        && $pdo->query('SELECT expected_arrival FROM attendance_notices WHERE child_id=1')->fetchColumn() === null,
        'Changing the notice to absence removes the child from expected attendance while keeping the notice visible');
    $request('parent/attendance.php', $parent, ['action'=>'cancel_notice','child_id'=>1,'notice_date'=>$today]);
    [$html] = $request('admin/attendance.php', $reception, [], ['filter'=>'missing','classroom_id'=>1]);
    $assert(str_contains($html, 'attendance[1][status]') && (int) $pdo->query('SELECT COUNT(*) FROM attendance_notices')->fetchColumn() === 0,
        'Cancelling a notice restores the child to expected attendance');
    $request('parent/attendance.php', $parent, array_replace($lateNotice, ['notice_date'=>$tomorrow]));
    [$html] = $request('admin/attendance.php', $reception, [], ['date'=>$tomorrow,'filter'=>'notices']);
    $assert(str_contains($html, 'قرار آزمایشی خانواده') && !str_contains($html, 'class="attendance-save-form"'),
        'Reception can review tomorrow’s announcements without a future attendance editor');

    $request('admin/attendance.php', $owner, ['attendance_date'=>$today,'attendance'=>[1=>['notes'=>'بدون انتخاب وضعیت']]]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 0, 'Missing attendance status does not become present');
    $request('teacher/attendance.php', array_merge($teacher,['teacher_classroom_id'=>2]), ['classroom_id'=>1,'attendance_date'=>$today,'attendance'=>[1=>['status'=>'present','check_in'=>'08:30']]]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE child_id=1 AND status='present'")->fetchColumn() === 1, 'Stale tab saves attendance to the classroom on the form');
    $request('teacher/attendance.php', $teacher, ['classroom_id'=>3,'attendance_date'=>$today,'attendance'=>[3=>['status'=>'present']]]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE child_id=3')->fetchColumn() === 0, 'Teacher cannot save attendance to unassigned classroom');
    $request('teacher/attendance.php', $teacher, ['classroom_id'=>1,'attendance_date'=>$today,'attendance'=>[1=>['status'=>'absent','check_in'=>'08:30','check_out'=>'09:00']]]);
    $assert($pdo->query('SELECT check_in FROM attendance WHERE child_id=1')->fetchColumn() === null, 'Absent status clears visit times');
    $request('teacher/attendance.php', $teacher, ['classroom_id'=>1,'attendance_date'=>$today,'attendance'=>[2=>['status'=>'present','check_in'=>'10:00','check_out'=>'09:00']]]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE child_id=2')->fetchColumn() === 0, 'Impossible checkout rolls back attendance');

    $pdo->exec("UPDATE attendance SET status='present', check_in='08:30:00' WHERE child_id=1");
    $pdo->prepare("INSERT INTO attendance (child_id, attendance_date, status, check_in, check_out) VALUES (2, ?, 'late', '09:00', '15:00')")
        ->execute([$today]);
    $request('parent/attendance.php', $parent, $lateNotice);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_notices WHERE notice_date = '$today'")->fetchColumn() === 0,
        'A family announcement cannot be added after attendance has been recorded');
    $holiday = ['form_action'=>'save_event','category'=>'holiday','event_date'=>$today,'title'=>'تعطیلی آزمایشی مهد','status'=>'scheduled'];
    $request('admin/events.php', $owner, $holiday);
    $holidayId = (int) $pdo->query('SELECT event_id FROM school_holidays')->fetchColumn();
    [$html] = $request('admin/index.php', $owner);
    [$checkoutHtml] = $request('admin/attendance.php', $reception, [], ['filter'=>'open_checkout','classroom_id'=>1]);
    [$teacherHtml] = $request('teacher/attendance.php', $teacher, [], ['classroom_id'=>1]);
    $assert($holidayId > 0 && str_contains($html, 'تعطیلی آزمایشی مهد') && !str_contains($html, 'filter=missing')
        && str_contains($checkoutHtml, 'attendance[1][status]') && str_contains($teacherHtml, '۰ کودک مورد انتظار'),
        'A calendar closure clears both expected lists while retaining physical checkout tracking');
    [, $session] = $request('parent/attendance.php', $otherParent, array_replace($lateNotice, ['child_id'=>3]));
    $assert(str_contains($session['flash']['error'] ?? '', 'مهد در این تاریخ تعطیل است'), 'Parents cannot book a notice on a closed day');
    $request('admin/events.php', $owner, array_replace($holiday, ['event_id'=>$holidayId,'event_date'=>$tomorrow]));
    $assert($pdo->query('SELECT holiday_date FROM school_holidays')->fetchColumn() === $tomorrow,
        'Editing a calendar holiday moves the closure to the new date');
    $request('admin/events.php', $owner, array_replace($holiday, ['event_id'=>$holidayId,'event_date'=>$tomorrow,'status'=>'cancelled']));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM school_holidays')->fetchColumn() === 0,
        'Cancelling a holiday reopens its day');
    [$html] = $request('admin/index.php', $owner);
    $assert(str_contains($html, 'attendance.php?filter=missing') && str_contains($html, 'attendance.php?filter=open_checkout'), 'Dashboard links to both actionable attendance filters');
    [$html] = $request('admin/attendance.php', $owner, [], ['filter'=>'open_checkout','classroom_id'=>1]);
    $assert(str_contains($html, 'attendance[1][status]') && str_contains($html, 'attendance[2][status]') && str_contains($html, 'در انتظار تأیید تحویل'),
        'The open list includes arrivals both with and without an unverified checkout time');
    [$html] = $request('admin/attendance.php', $owner, [], ['filter'=>'missing','classroom_id'=>2]);
    $assert(str_contains($html, 'attendance[3][status]') && !str_contains($html, 'attendance[1][status]')
        && str_contains($html, 'filter=missing&amp;classroom_id=2'), 'Missing attendance is scoped to the class and the form retains the filters');
    [$html] = $request('admin/attendance.php', $owner, [], ['child_id'=>2]);
    $assert(str_contains($html, 'attendance[2][status]') && !str_contains($html, 'attendance[1][status]'), 'Directory attendance link opens only the selected child');
    $request('admin/attendance.php', $owner, ['attendance_date'=>$today,'attendance'=>[3=>['status'=>'absent']]], ['filter'=>'missing','classroom_id'=>1]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE child_id=3')->fetchColumn() === 0, 'A filtered attendance save respects its classroom');
    foreach (['mobile_attendance'=>'15:10', 'attendance'=>'15:05'] as $field => $checkout) {
        $request('admin/attendance.php', $owner, ['attendance_date'=>$today,$field=>[1=>['status'=>'present','check_in'=>'08:30','check_out'=>$checkout]]],
            ['filter'=>'open_checkout','classroom_id'=>1]);
    }
    $assert($pdo->query('SELECT check_out FROM attendance WHERE child_id=1')->fetchColumn() === '15:10:00',
        'Saving an old open-checkout list preserves a checkout already recorded');

    $pdo->exec("UPDATE children SET second_guardian_name='سرپرست نمونه' WHERE id=1");
    $guardianChoice = '1:guardian:' . hash('sha256', 'سرپرست نمونه');
    [$pickupForm] = $request('admin/attendance.php', $reception, [], ['filter'=>'open_checkout','classroom_id'=>1]);
    $assert(str_contains($pickupForm, $guardianChoice) && str_contains($pickupForm, '1:parent:' . hash('sha256', 'نگار احمدی')),
        'Reception can pick either guardian stored in the child profile');
    $request('admin/attendance.php', $reception, ['attendance_date'=>$today,'confirm_pickup'=>$guardianChoice], ['filter'=>'open_checkout','classroom_id'=>1]);
    $pickupRecord = $pdo->query('SELECT check_out, picked_up_by, pickup_verified_by FROM attendance WHERE child_id=1')->fetch();
    $assert($pickupRecord['picked_up_by'] === 'سرپرست نمونه' && (int) $pickupRecord['pickup_verified_by'] === 3
        && $pickupRecord['check_out'] === '15:10:00', 'Confirmation records the guardian and authenticated receptionist and preserves the existing checkout time');
    [$html] = $request('admin/attendance.php', $reception, [], ['filter'=>'open_checkout','classroom_id'=>1]);
    $assert(!str_contains($html, 'attendance[1][status]') && str_contains($html, 'attendance[2][status]'),
        'Confirming one child removes only that child from the handover queue');
    $request('teacher/attendance.php', $teacher, ['classroom_id'=>1,'attendance_date'=>$today,'attendance'=>[1=>['status'=>'absent']]]);
    $request('admin/attendance.php', $owner, ['attendance_date'=>$today,'attendance'=>[1=>['status'=>'absent']]]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE child_id=1 AND status='present' AND pickup_verified_by=3 AND check_out='15:10:00'")->fetchColumn() === 1,
        'Ordinary teacher and admin saves preserve a confirmed handover');
    foreach (['absent', 'excused'] as $attendanceStatus) {
        $request('admin/attendance.php', $owner, ['attendance_date'=>$today,'attendance'=>[3=>['status'=>$attendanceStatus]]],
            ['filter'=>'missing','classroom_id'=>2]);
    }
    $assert($pdo->query('SELECT status FROM attendance WHERE child_id=3')->fetchColumn() === 'absent',
        'Saving an old missing-attendance list preserves a status already recorded');

    $pdo->exec("UPDATE children SET status='active' WHERE id=4");
    $pdo->prepare('INSERT INTO child_classroom (child_id,classroom_id,enrollment_date) VALUES (4,1,?)')->execute([$today]);
    $pdo->prepare("INSERT INTO attendance (child_id, attendance_date, status) VALUES (4, ?, 'excused')")->execute([$today]);
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>'invalid','mood'=>[1=>'happy']]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn() === 0, 'Invalid report date cannot silently write today');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[1=>'normal']]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM daily_reports WHERE child_id=1 AND mood='normal' AND nutrition_status IS NULL AND rest_energy IS NULL")->fetchColumn() === 1,
        'An explicit mood leaves other observations unset');
    $reportId = (int) $pdo->query('SELECT id FROM daily_reports WHERE child_id=1')->fetchColumn();
    $feedback = ['action'=>'report_feedback','report_id'=>$reportId,'reaction'=>'seen'];
    $request('parent/child-detail.php', $parent, $feedback, ['id'=>1]);
    $seenAt = $pdo->query('SELECT seen_at FROM daily_report_feedback')->fetchColumn();
    $assert(is_string($seenAt) && $pdo->query('SELECT reaction FROM daily_report_feedback')->fetchColumn() === null,
        'A parent can acknowledge a report without choosing a reaction');
    $request('parent/child-detail.php', $otherParent, array_replace($feedback, ['reaction'=>'helpful']), ['id'=>3]);
    $request('parent/child-detail.php', $parent, array_replace($feedback, ['reaction'=>'more_details']), ['id'=>1]);
    $request('parent/child-detail.php', $parent, $feedback, ['id'=>1]);
    $feedbackRows = $pdo->query('SELECT * FROM daily_report_feedback')->fetchAll();
    $assert(count($feedbackRows) === 1 && (int) $feedbackRows[0]['parent_id'] === 1
        && $feedbackRows[0]['reaction'] === 'more_details' && $feedbackRows[0]['seen_at'] === $seenAt,
        'Only the report’s family can react, and acknowledging again preserves the reaction and initial seen time');
    [$html] = $request('parent/child-detail.php', $parent, [], ['id'=>1]);
    [$teacherHtml] = $request('teacher/report.php', $teacher, [], ['classroom_id'=>1]);
    [$otherClassHtml] = $request('teacher/report.php', $teacher, [], ['classroom_id'=>2]);
    $assert(str_contains($html, 'مشاهده تأیید شد') && str_contains($teacherHtml, 'خانواده مشاهده کرد')
        && str_contains($teacherHtml, 'توضیح بیشتری می‌خواهم') && !str_contains($otherClassHtml, 'توضیح بیشتری می‌خواهم'),
        'Saved feedback is visible to the family and teacher in the report’s class');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>'happy']]);
    $assert($pdo->query('SELECT mood FROM daily_reports WHERE child_id=1')->fetchColumn() === 'normal', 'Partial report submit preserves omitted children');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>''],'nutrition_status'=>[2=>'normal_appetite']]);
    [$html] = $request('parent/child-detail.php', $parent, [], ['id'=>2]);
    $assert($pdo->query('SELECT mood FROM daily_reports WHERE child_id=2')->fetchColumn() === null
        && str_contains($html, 'مشاهده‌نشده') && str_contains($html, 'خوردن متناسب و بدون فشار'),
        'An observation without mood is saved and displayed without invented observations');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[4=>'happy']]);
    [$html] = $request('teacher/index.php', $teacher);
    $assert(str_contains($html, 'تکمیل شده ✓') && str_contains($html, '>از ۲</small>') && !str_contains($html, 'گزارش باقی‌مانده')
        && str_contains($html, 'آخرین بازخوردهای خانواده‌ها'),
        'Report progress includes present and late children and excludes the excused child even with a report');

    foreach (['نقاشی گروهی', 'نقاشی گروهی و قصه‌خوانی'] as $classActivity) {
        $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'class_activity'=>$classActivity]);
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM classroom_daily_activities')->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM classroom_activity_attendance')->fetchColumn() === 2
        && (int) $pdo->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn() === 3,
        'Editing a shared activity retains one class-day entry, its attendees and the individual reports');
    [$html] = $request('parent/child-detail.php', $parent, [], ['id'=>2]);
    [$absentHtml] = $request('parent/child-detail.php', $otherParent, [], ['id'=>4]);
    $assert(str_contains($html, 'نقاشی گروهی و قصه‌خوانی') && !str_contains($absentHtml, 'نقاشی گروهی'),
        'Shared activity is visible to families of children who attended');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'nutrition_status'=>[2=>'']]);
    [$html] = $request('teacher/index.php', $teacher);
    $assert(str_contains($html, '۱ گزارش باقی‌مانده'), 'Clearing the only individual observation marks that report as pending even with a shared activity');
    $yesterday = $localToday->modify('-1 day')->format('Y-m-d');
    $pdo->prepare('INSERT INTO daily_reports (child_id, classroom_id, teacher_id, report_date, notes) VALUES (1, 1, 1, ?, ?)')
        ->execute([$yesterday, 'گزارش روز گذشته برای آزمون']);
    $olderReportId = (int) $pdo->lastInsertId();
    [$html] = $request('parent/child-detail.php', $parent, [], ['id'=>1]);
    $request('parent/child-detail.php', $parent, ['action'=>'report_feedback','report_id'=>$olderReportId,'reaction'=>'helpful'], ['id'=>1]);
    [$teacherHtml] = $request('teacher/report.php', $teacher, [], ['classroom_id'=>1,'date'=>$yesterday]);
    [$dashboardHtml] = $request('teacher/index.php', $teacher);
    $assert(str_contains($html, 'id="report-feedback-' . $olderReportId . '"') && str_contains($teacherHtml, 'مفید بود')
        && str_contains($dashboardHtml, '&amp;date=' . $yesterday . '#child-report-1'),
        'Families and teachers can use feedback on earlier individual reports');

    $pdo->exec('INSERT INTO classroom_waitlist (child_id,classroom_id) VALUES (3,1),(3,3),(4,2)');
    $request('admin/child-action.php', $reception, ['action'=>'assign_classroom','child_id'=>3,'classroom_id'=>1]);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM classroom_waitlist WHERE child_id=3 AND classroom_id=1 AND fulfilled_at IS NOT NULL')->fetchColumn() === 1
        && $pdo->query('SELECT fulfilled_at FROM classroom_waitlist WHERE child_id=3 AND classroom_id=3')->fetchColumn() === null,
        'Successful assignment closes only the matching waitlist entry');
    $request('admin/child-action.php', $reception, ['action'=>'add_waitlist','child_id'=>3,'classroom_id'=>1]);
    $assert($pdo->query('SELECT fulfilled_at FROM classroom_waitlist WHERE child_id=3 AND classroom_id=1')->fetchColumn() !== null,
        'A stale waitlist action cannot reopen the entry for a child already in that classroom');
    $request('admin/edit-child.php', $reception, [
        'first_name'=>'باران','last_name'=>'رضایی','date_of_birth'=>'2023-02-11','status'=>'active','classroom_id'=>2,
    ], ['id'=>4]);
    $assert((int) $pdo->query('SELECT classroom_id FROM child_classroom WHERE child_id=4')->fetchColumn() === 2
        && $pdo->query('SELECT fulfilled_at FROM classroom_waitlist WHERE child_id=4 AND classroom_id=2')->fetchColumn() !== null,
        'Assignment through the child editor also closes the waitlist entry');

    $addChild = $pdo->prepare("INSERT INTO children (parent_id, first_name, last_name, date_of_birth, status) VALUES (2, ?, 'آزمایشی', '2022-05-01', 'active')");
    for ($n = 1; $n <= 56; $n++) {
        $addChild->execute(['کودک ' . $n]);
    }
    [$html] = $request('admin/index.php', $manager);
    [$unassigned] = $request('admin/children.php', $manager, [], ['status'=>'active','classroom'=>'unassigned']);
    $assert(str_contains($html, 'children.php?status=active&amp;classroom=unassigned')
        && str_contains($unassigned, 'کودک 56') && !str_contains($unassigned, 'admin/child-detail.php?id=1"'),
        'The dashboard warning opens the directory filtered to active children with no class');
    [$html] = $request('admin/children.php', $reception, [], ['q'=>'آوا احمدي']);
    $assert(str_contains($html, 'admin/child-detail.php?id=1') && str_contains($html, 'admin/attendance.php?child_id=1')
        && str_contains($html, 'admin/messages.php?parent_id=1#compose-message'),
        'Global search finds a child outside the first page and links to profile, attendance and family messaging');
    [$byParent] = $request('admin/children.php', $reception, [], ['q'=>'نگار احمدی']);
    [$byPhone] = $request('admin/children.php', $reception, [], ['q'=>'+۹۸ (۹۱۲) ۰۰۰-۰۰۰۱']);
    $assert(str_contains($byParent, 'admin/child-detail.php?id=2') && str_contains($byPhone, 'admin/child-detail.php?id=2')
        && !str_contains($byPhone, 'admin/child-detail.php?id=3'), 'Search supports a full parent name and Persian digits in a formatted mobile number');
    [$html] = $request('admin/children.php', $reception, [], ['q'=>'سارا','status'=>'active','p'=>3]);
    $assert(str_contains($html, 'admin/child-detail.php?id=3') && str_contains($html, 'status=active&amp;q='),
        'Search pagination retains the term and enrollment status');
    [$html] = $request('admin/messages.php', $reception, [], ['parent_id'=>1]);
    $assert(str_contains($html, '<option value="1" selected>') && !str_contains($html, '<option value="all" selected>'),
        'The family link preselects that parent in the message composer');

    $request('admin/tuition.php', $owner, ['form_action'=>'set_plan','plan_child_id'=>1,'plan_month_year'=>'1405-08','expected_amount'=>3000000]);
    $request('admin/tuition.php', $owner, ['form_action'=>'set_plan','plan_child_id'=>1,'plan_month_year'=>'1405-08','expected_amount'=>3500000]);
    $assert((int) $pdo->query("SELECT expected_amount FROM tuition_plans WHERE child_id=1 AND month_year='1405-08'")->fetchColumn() === 3500000, 'Tuition plan insert and update both work with native prepares');
    $request('admin/tuition.php', $owner, ['form_action'=>'set_plan','plan_child_id'=>1,'plan_month_year'=>'1405-13','expected_amount'=>1]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM tuition_plans WHERE month_year='1405-13'")->fetchColumn() === 0, 'Invalid tuition month is rejected');

    $setInvoice = static function (int $child, string $month, int $amount, ?string $due, ?string $discrepancy = null) use ($request, $accountant): void {
        $post = ['form_action'=>'set_plan','plan_child_id'=>$child,'plan_month_year'=>$month,'expected_amount'=>$amount];
        if ($due !== null) { $post['due_date'] = $due; }
        if ($discrepancy !== null) { $post['discrepancy_note'] = $discrepancy; }
        $request('admin/tuition.php', $accountant, $post);
    };
    $setInvoice(1, '1405-08', 3500000, $today);
    $setInvoice(1, '1405-07', 3500000, $yesterday);
    $setInvoice(2, '1405-08', 3500000, $tomorrow);
    $setInvoice(2, '1405-07', 3500000, $yesterday, 'مغایرت آزمایشی در رسید');
    $setInvoice(3, '1405-08', 3500000, null);
    $setInvoice(3, '1405-07', 3500000, $yesterday);
    $setInvoice(4, '1405-08', 3500000, $yesterday);
    $setInvoice(4, '1405-06', 3500000, $yesterday);
    $ledgerPayment = $pdo->prepare('INSERT INTO tuition_payments (child_id,parent_id,month_year,amount,payment_date) VALUES (?,?,?,?,?)');
    $ledgerPayment->execute([1,1,'1405-07',1500000,$today]);
    $ledgerPayment->execute([3,2,'1405-07',3500000,$today]);
    $ledgerPayment->execute([4,2,'1405-08',3600000,$today]);
    $ledgerPayment->execute([3,2,'1405-06',1500000,$today]);
    $ledgerPayment->execute([2,1,'1405-05',2500000,$today]);
    $pdo->exec("INSERT INTO tuition_reminder_log (child_id,parent_id,month_year) VALUES (4,2,'1405-06')");

    [$review] = $request('admin/tuition.php', $accountant);
    [$needsDue] = $request('admin/tuition.php', $accountant, [], ['month_year'=>'1405-08','filter'=>'needs_due_date']);
    [$allBills] = $request('admin/tuition.php', $accountant, [], ['month_year'=>'1405-08','filter'=>'all']);
    $assert(substr_count($review, 'data-invoice-state="overdue"') === 2 && substr_count($review, 'data-invoice-state="discrepancy"') === 3
        && !str_contains($review, 'data-invoice-state="upcoming"') && !str_contains($review, 'data-invoice-state="needs_due_date"'),
        'The accountant queue covers older months and shows only overdue or disputed invoices');
    $assert(str_contains($needsDue, 'data-invoice-state="needs_due_date"') && substr_count($allBills, 'data-invoice-state="upcoming"') === 2,
        'Due-today and future invoices stay out of collections; legacy plans have their own filter');
    $request('admin/tuition.php', $accountant, ['form_action'=>'set_missing_due_dates','plan_month_year'=>'1405-08','due_date'=>$tomorrow]);
    $assert($pdo->query("SELECT due_date FROM tuition_plans WHERE child_id=3 AND month_year='1405-08'")->fetchColumn() === $tomorrow
        && $pdo->query("SELECT due_date FROM tuition_plans WHERE child_id=1 AND month_year='1405-08'")->fetchColumn() === $today,
        'Bulk due-date setup fills missing dates and preserves dates already agreed with families');
    $setInvoice(1, '1405-08', 3500000, null);
    $assert($pdo->query("SELECT due_date FROM tuition_plans WHERE child_id=1 AND month_year='1405-08'")->fetchColumn() === $today,
        'An older invoice form preserves the stored due date when only the amount changes');
    $setInvoice(1, '1405-08', 4000000, '2026-02-30');
    $legacyBill = $pdo->query("SELECT due_date, expected_amount FROM tuition_plans WHERE child_id=1 AND month_year='1405-08'")->fetch();
    $assert($legacyBill['due_date'] === $today && (float) $legacyBill['expected_amount'] === 3500000.0,
        'An invalid due date cannot change an existing invoice');

    $runReminder = static function (string ...$options) use ($root, $database, $directory): string {
        $env = array_replace(getenv(), ['DB_HOST'=>'127.0.0.1','DB_NAME'=>$database,'DB_USER'=>'root','DB_PASS'=>'',
            'SITE_URL'=>'http://127.0.0.1:8097','ERROR_LOG_PATH'=>$directory . '/reminder-errors.log']);
        $process = proc_open([PHP_BINARY, $root . '/bin/send-overdue-reminders.php', ...$options],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root, $env);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Reminder run failed: ' . $errors . $output); }
        return $output;
    };
    $dryRun = $runReminder('--dry-run');
    $currentOnly = $runReminder('--dry-run', '--month=1405-08');
    $assert(substr_count($dryRun, 'would remind') === 1 && str_contains($dryRun, '1405-07 — would remind')
        && !str_contains($currentOnly, 'would remind') && (int) $pdo->query('SELECT COUNT(*) FROM tuition_reminder_log')->fetchColumn() === 1,
        'Dry run includes overdue carryovers, respects month scope and legacy dedup, and performs no deliveries');
    $runReminder();
    $runReminder();
    $assert((int) $pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key='tuition_reminder'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE subject LIKE 'یادآوری دوستانه شهریه%' AND body LIKE '%سررسید پرداخت%'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM tuition_reminder_log WHERE month_year='1405-07' AND child_id=1")->fetchColumn() === 1,
        'Eligible invoice produces a single portal reminder with its due date and never contacts SMS when disabled');

    $changeDate = $localToday->modify('-3 days')->format('Y-m-d');
    $createPilot = ['action'=>'create_pilot','classroom_id'=>1,'change_date'=>$changeDate];
    $request('admin/operations-pilot.php', $accountant, $createPilot);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM operations_pilots')->fetchColumn() === 0, 'Only management can configure a class pilot');
    $request('admin/operations-pilot.php', $manager, $createPilot);
    $request('admin/operations-pilot.php', $manager, $createPilot);
    $pilotId = (int) $pdo->query('SELECT id FROM operations_pilots')->fetchColumn();
    $assert($pilotId > 0 && (int) $pdo->query('SELECT COUNT(*) FROM operations_pilots')->fetchColumn() === 1,
        'Pilot creation is class-scoped and overlapping windows are rejected');

    $preDay = $localToday->modify('-6 days')->format('Y-m-d');
    $dayData = ['action'=>'save_day','pilot_id'=>$pilotId,'work_date'=>$preDay,'day_kind'=>'worked',
        'manager_minutes'=>50,'open_cases'=>4,'closed_cases'=>5,'independent_cases'=>2];
    $request('admin/operations-pilot.php', $manager, $dayData);
    $request('admin/operations-pilot.php', $manager, array_replace($dayData, ['work_date'=>$today,'manager_minutes'=>20,'open_cases'=>1,'closed_cases'=>4,'independent_cases'=>3]));
    $request('admin/operations-pilot.php', $manager, ['action'=>'save_day','pilot_id'=>$pilotId,'work_date'=>$yesterday,'day_kind'=>'closed']);
    $request('admin/operations-pilot.php', $manager, array_replace($dayData, ['work_date'=>$tomorrow]));
    $request('admin/operations-pilot.php', $manager, array_replace($dayData, ['independent_cases'=>7]));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM operations_pilot_days')->fetchColumn() === 3
        && (int) $pdo->query("SELECT independent_cases FROM operations_pilot_days WHERE work_date='$preDay'")->fetchColumn() === 2,
        'Daily outcomes distinguish closure from missing records and reject future or impossible counts');

    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>'normal'], 'report_work_ms'=>[2=>126000, 4=>50000]]);
    $pilotReport = $pdo->query('SELECT m.report_id, m.work_ms FROM operations_pilot_reports m INNER JOIN daily_reports r ON r.id=m.report_id WHERE r.child_id=2')->fetch();
    $pilotReportId = (int) ($pilotReport['report_id'] ?? 0);
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>'happy'], 'report_work_ms'=>[2=>9000]]);
    $assert($pilotReportId > 0 && (int) $pdo->query("SELECT work_ms FROM operations_pilot_reports WHERE report_id=$pilotReportId")->fetchColumn() === 126000
        && (int) $pdo->query('SELECT COUNT(*) FROM operations_pilot_reports')->fetchColumn() === 1,
        'Only a successful first individual report save contributes a duration to the pilot');

    [$parentReport] = $request('parent/child-detail.php', $parent, [], ['id'=>2]);
    $request('parent/report-view.php', $otherParent, ['report_id'=>$pilotReportId]);
    $assert(str_contains($parentReport, 'data-pilot-report-view="' . $pilotReportId . '"')
        && $pdo->query("SELECT first_viewed_at FROM operations_pilot_reports WHERE report_id=$pilotReportId")->fetchColumn() === null,
        'Only the child’s actual parent can register a report view');
    [, , $viewStatus] = $request('parent/report-view.php', $parent, ['report_id'=>$pilotReportId]);
    $assert($viewStatus === 204 && is_string($pdo->query("SELECT first_viewed_at FROM operations_pilot_reports WHERE report_id=$pilotReportId")->fetchColumn()),
        'The read marker records the first view separately from feedback');

    $olderPilotReport = $pdo->prepare('INSERT INTO daily_reports (teacher_id,classroom_id,child_id,report_date,mood) VALUES (1,1,1,?,?)');
    $oldMetric = $pdo->prepare('INSERT INTO operations_pilot_reports (report_id,pilot_id,published_at,work_ms,first_viewed_at) VALUES (?,?,?,?,?)');
    foreach ([[$preDay, 90000, true], [$localToday->modify('-5 days')->format('Y-m-d'), 150000, false]] as [$date, $duration, $viewed]) {
        $olderPilotReport->execute([$date, 'normal']);
        $oldMetric->execute([(int) $pdo->lastInsertId(), $pilotId, $date . ' 10:00:00', $duration, $viewed ? $date . ' 12:00:00' : null]);
    }
    [$results] = $request('admin/operations-pilot.php', $manager, [], ['pilot'=>$pilotId]);
    $assert(substr_count($results, 'data-pilot-metric=') === 5 && str_contains($results, '۵۰.۰') && str_contains($results, '۷۵.۰')
        && str_contains($results, '۲.۱') && str_contains($results, '۱ از ۲ گزارش با مهلت ۴۸ساعتهٔ کامل')
        && preg_match('/data-pilot-metric="report_views".*?data-pilot-phase="after"[^>]*>\s*—/s', $results) === 1,
        'Comparison aggregates distinct weeks and leaves the view rate pending until reports have had 48 hours');

    $pdo->exec("UPDATE parents SET status='suspended' WHERE id=1");
    [,$session] = $request('parent/index.php', $parent);
    $assert(!isset($session['parent_id']), 'Suspended parent loses access in existing session');
    $pdo->exec("UPDATE parents SET status='active' WHERE id=1");
    echo "Portal integration: {$count} checks passed.\n";
    if ($keep) {
        file_put_contents(sys_get_temp_dir() . '/roma97-preview.json', json_encode(['database'=>$database,'directory'=>$directory]));
        echo "Preview fixture retained: {$database}\n";
    }
} finally {
    if (!$keep) { $pdo->exec("DROP DATABASE IF EXISTS `{$database}`"); }
}
