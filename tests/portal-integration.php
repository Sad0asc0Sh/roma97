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
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $pdo->prepare('INSERT INTO child_classroom (child_id,classroom_id,enrollment_date) VALUES (1,1,?),(2,1,?),(3,2,?)')
        ->execute([$today, $today, $today]);
    $pdo->exec("INSERT INTO events (title,event_date,status,category) VALUES ('جشن دوستی و قصه‌گویی',DATE_ADD(CURDATE(),INTERVAL 3 DAY),'scheduled','celebration')");
    $owner = ['admin_logged_in'=>true,'admin_id'=>1,'admin_role'=>'owner','admin_username'=>'مدیر روما'];
    $accountant = ['admin_logged_in'=>true,'admin_id'=>2,'admin_role'=>'accountant','admin_username'=>'حسابدار'];
    $reception = ['admin_logged_in'=>true,'admin_id'=>3,'admin_role'=>'receptionist','admin_username'=>'پذیرش'];
    $teacher = ['teacher_id'=>1,'teacher_classroom_id'=>1,'teacher_name'=>'مریم کریمی'];
    $parent = ['parent_id'=>1,'parent_name'=>'نگار احمدی','parent_first_name'=>'نگار'];
    $otherParent = ['parent_id'=>2,'parent_name'=>'سارا رضایی','parent_first_name'=>'سارا'];

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
    $assert((int) $pdo->query("SELECT is_read FROM messages WHERE id={$replyId}")->fetchColumn() === 1, 'Intended admin can mark reply read');
    $pdo->exec("INSERT INTO messages(sender_type,sender_id,parent_id,subject,body) VALUES('admin',1,NULL,'خبر عمومی','فردا روز نقاشی است')");
    $broadcast = (int) $pdo->lastInsertId();
    $request('parent/messages.php', $parent, [], ['view'=>$broadcast]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM message_reads WHERE message_id={$broadcast} AND parent_id=1")->fetchColumn() === 1, 'Broadcast read receipt belongs to first family');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM message_reads WHERE message_id={$broadcast} AND parent_id=2")->fetchColumn() === 0, 'Reading broadcast leaves second family unread');

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
    [$html] = $request('admin/index.php', $owner);
    $assert(str_contains($html, 'attendance.php?filter=missing') && str_contains($html, 'attendance.php?filter=open_checkout'), 'Dashboard links to both actionable attendance filters');
    [$html] = $request('admin/attendance.php', $owner, [], ['filter'=>'open_checkout','classroom_id'=>1]);
    $assert(str_contains($html, 'attendance[1][status]') && !str_contains($html, 'attendance[2][status]'),
        'Open checkout includes registered arrivals and excludes completed checkouts');
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
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>'happy']]);
    $assert($pdo->query('SELECT mood FROM daily_reports WHERE child_id=1')->fetchColumn() === 'normal', 'Partial report submit preserves omitted children');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[2=>''],'nutrition_status'=>[2=>'normal_appetite']]);
    [$html] = $request('parent/child-detail.php', $parent, [], ['id'=>2]);
    $assert($pdo->query('SELECT mood FROM daily_reports WHERE child_id=2')->fetchColumn() === null
        && str_contains($html, 'مشاهده‌نشده') && str_contains($html, 'خوردن متناسب و بدون فشار'),
        'An observation without mood is saved and displayed without invented observations');
    $request('teacher/report.php', $teacher, ['classroom_id'=>1,'report_date'=>$today,'mood'=>[4=>'happy']]);
    [$html] = $request('teacher/index.php', $teacher);
    $assert(str_contains($html, 'تکمیل شده ✓') && str_contains($html, '>از ۲</small>') && !str_contains($html, 'گزارش باقی‌مانده'),
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

    $addChild = $pdo->prepare("INSERT INTO children (parent_id, first_name, last_name, date_of_birth, status) VALUES (2, ?, 'آزمایشی', '2022-05-01', 'active')");
    for ($n = 1; $n <= 56; $n++) {
        $addChild->execute(['کودک ' . $n]);
    }
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
