<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin();
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
if (isPostRequest()) {
    $csrf=$_POST['csrf_token']??''; $action=(string)($_POST['action']??'');
    $pid=filter_var($_POST['parent_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $pid=is_int($pid)?$pid:0;
    if (!validateCsrfToken($csrf)){setFlash('error','درخواست نامعتبر.');redirect(url('admin/parents.php'));}
    // Reset links hand over a live account; only the two most trusted roles may mint them.
    if ($action === 'generate_reset_link'){requireAdminRole(['owner','manager']);}
    try {
        initializeFinancialTables();
        $pdo=getDb();
        if($pid===0){setFlash('error','شناسه نامعتبر.');redirect(url('admin/parents.php'));}
        $chk=$pdo->prepare('SELECT id,first_name,last_name,email,phone,status FROM parents WHERE id=:id LIMIT 1');
        $chk->execute([':id'=>$pid]); $par=$chk->fetch();
        if(!$par){setFlash('error','یافت نشد.');redirect(url('admin/parents.php'));}

        if ($action === 'generate_reset_link') {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $ins = $pdo->prepare(
                'INSERT INTO password_reset_tokens (token, account_type, account_id, created_by_admin_id, expires_at)
                 VALUES (:token, "parent", :pid, :aid, :exp)'
            );
            $ins->execute([
                ':token' => $token,
                ':pid' => $pid,
                ':aid' => $currentAdminId > 0 ? $currentAdminId : null,
                ':exp' => $expires,
            ]);
            recordAudit('parent.generate_reset_link', 'parent', $pid);
            setFlash('reset_link_url', url('reset-password.php?token=' . urlencode($token)));
            redirect(url('admin/parents.php'));
        }

        $updates=['approve'=>'active','reject'=>'suspended','suspend'=>'suspended','activate'=>'active'];
        if(array_key_exists($action,$updates)){
            $ns=$updates[$action];
            $pdo->prepare('UPDATE parents SET status=:s WHERE id=:id')->execute([':s'=>$ns,':id'=>$pid]);
            $msgs=['approve'=>'حساب والد تأیید و فعال شد.','reject'=>'حساب والد رد شد.','suspend'=>'حساب والد مسدود شد.','activate'=>'حساب والد فعال شد.'];
            recordAudit('parent.'.$action,'parent',$pid,['status'=>$ns]);

            $flashMessage = $msgs[$action];

            // On approval/activation, welcome the family through the unified
            // engine so they get BOTH a portal message (with what to do next)
            // and the welcome SMS — previously only the SMS was attempted.
            if (in_array($action, ['approve', 'activate'], true)) {
                $fullName = trim((string) $par['first_name'] . ' ' . (string) $par['last_name']);
                $portalBody = "{$fullName} عزیز؛\n\n"
                    . 'به خانواده بزرگ مهدکودک ' . siteName() . " خوش آمدید! 🌷\n\n"
                    . "حساب کاربری شما تأیید و فعال شد. از این پس می‌توانید:\n"
                    . "• گزارش روزانه و حال‌وهوای فرزندتان را مشاهده کنید\n"
                    . "• حضور و غیاب هفتگی را پیگیری کنید\n"
                    . "• وضعیت و تاریخچه شهریه را ببینید\n"
                    . "• تنظیمات دریافت پیامک‌ها را در پروفایل خود شخصی‌سازی کنید\n\n"
                    . "با مهر،\nمدیریت مهدکودک " . siteName() . ' 💖';

                $welcomeResult = notifyParent($pid, 'welcome', [
                    'args'           => [$fullName],
                    'portal_subject' => 'به ' . siteName() . ' خوش آمدید 🌷',
                    'portal_body'    => $portalBody,
                    'dedup_key'      => "welcome:{$pid}",
                    'sender_id'      => $currentAdminId,
                    'priority'       => 3,
                ]);

                smsQueueFlushInline($pdo);

                $summary = summarizeNotificationResults([$welcomeResult]);
                if ($summary !== '') {
                    $flashMessage .= ' ' . $summary;
                }
            }

            setFlash('success', $flashMessage);
        } else { setFlash('error','عملیات نامعتبر.'); }
        redirect(url('admin/parents.php'));
    } catch(Throwable $e){error_log($e->getMessage());setFlash('error','خطا.');redirect(url('admin/parents.php'));}
}

$pdo=getDb();
$sf=strtolower(trim((string)($_GET['status']??'all')));
if(!in_array($sf,['all','pending','active','suspended'],true))$sf='all';
$pp=20;$cp=max(1,(int)($_GET['page']??1));
if($sf==='all'){$total=(int)$pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn();}
else{$ct=$pdo->prepare('SELECT COUNT(*) FROM parents WHERE status=:s');$ct->execute([':s'=>$sf]);$total=(int)$ct->fetchColumn();}
$tp=max(1,(int)ceil($total/$pp));$cp=min($cp,$tp);$off=($cp-1)*$pp;
$sql='SELECT id,first_name,last_name,email,phone,phone_verified,status,created_at FROM parents';
if($sf!=='all')$sql.=' WHERE status=:s';
$sql.=' ORDER BY created_at DESC LIMIT '.$pp.' OFFSET '.$off;
$stmt=$pdo->prepare($sql);
if($sf!=='all'){$stmt->execute([':s'=>$sf]);}else{$stmt->execute();}
$parents=$stmt->fetchAll();
$pagi=['current'=>$cp,'totalPages'=>$tp,'total'=>$total,'perPage'=>$pp,'from'=>$total>0?$off+1:0,'to'=>min($off+$pp,$total)];

$pageTitle = 'مدیریت والدین | ' . siteName();
require_once __DIR__.'/header.php';
$fHref=static fn(string $v):string=>url('admin/parents.php?'.http_build_query(['status'=>$v]));
$fe=getFlash('error');$fs=getFlash('success');$resetLinkUrl=getFlash('reset_link_url');
?>
<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>مدیریت والدین</h1>
                <span class="adm-count-pill"><?= e(persianNumber((string) $pagi['total'])) ?> خانواده</span>
            </div>
            <p class="adm-page-subtitle">مدیریت حساب کاربری والدین، تأیید ثبت‌نام‌ها، بازیابی رمز و ثبت کودک جدید</p>
        </div>
        <div class="adm-page-actions">
            <a href="<?=e(url('admin/add-parent.php'))?>" class="app-btn app-btn-primary">+ والد جدید</a>
        </div>
    </div>

    <?php if(!empty($resetLinkUrl)):?>
        <div class="adm-reset-banner">
            <div class="font-bold">لینک بازیابی رمز عبور تولید شد (اعتبار: ۱ ساعت):</div>
            <div class="adm-reset-banner-row">
                <input type="text" value="<?=e($resetLinkUrl)?>" readonly onclick="this.select()" class="form-control" style="flex:1; font-family:monospace; direction:ltr;">
                <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText('<?=e($resetLinkUrl)?>').then(()=>{if(window.showToast)showToast('لینک در حافظه کپی شد.','success');});">کپی لینک</button>
            </div>
        </div>
    <?php endif;?>

    <?php if(!empty($fe)):?><div class="alert alert-error" role="alert"><?=e($fe)?></div><?php endif;?>
    <?php if(!empty($fs)):?><div class="notice" role="status"><?=e($fs)?></div><?php endif;?>

    <nav class="filter-nav" aria-label="فیلتر بر اساس وضعیت والدین">
        <a href="<?=$fHref('all')?>" class="<?=$sf==='all'?'is-active':''?>">همه</a>
        <a href="<?=$fHref('pending')?>" class="<?=$sf==='pending'?'is-active':''?>">در انتظار</a>
        <a href="<?=$fHref('active')?>" class="<?=$sf==='active'?'is-active':''?>">فعال</a>
        <a href="<?=$fHref('suspended')?>" class="<?=$sf==='suspended'?'is-active':''?>">مسدود</a>
    </nav>
    <?php if($parents===[]):?>
        <div class="empty-state empty-state-sm">
            <div class="empty-state-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            </div>
            <h3>والدی یافت نشد</h3>
            <p><?=$sf==='pending'?'ثبت نام جدیدی در انتظار تأیید نیست.':'لیست خالی است.'?></p>
        </div>
    <?php else:?>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>نام و نام خانوادگی</th><th>شماره موبایل</th><th>ایمیل</th><th>وضعیت</th><th>تاریخ ثبت</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach($parents as $p):?>
            <tr>
                <td class="font-semibold"><?=e($p['first_name'].' '.$p['last_name'])?></td>
                <td dir="ltr" style="text-align:right;font-family:monospace;">
                    <?= !empty($p['phone']) ? e(persianNumber($p['phone'])) : '—' ?>
                    <?php if((int)($p['phone_verified']??0)===1):?><span title="تأیید پیامکی شده" style="color:var(--adm-success);">✓</span><?php endif;?>
                </td>
                <td class="muted"><?= !empty($p['email']) ? e($p['email']) : '—' ?></td>
                <td><?php $bc=match($p['status']){'active'=>'badge-success','pending'=>'badge-warning','suspended'=>'badge-danger',default=>'badge-info'};$sl=match($p['status']){'active'=>'فعال','pending'=>'در انتظار تأیید','suspended'=>'مسدود',default=>$p['status']};?><span class="badge <?=$bc?>"><?=$sl?></span></td>
                <td class="muted"><?=e(shamsiDate((string)$p['created_at']))?></td>
                <td>
                    <div class="adm-row-actions">
                        <?php if($p['status']==='pending'):?>
                            <form method="post" action="<?=e(url('admin/parents.php'))?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="parent_id" value="<?=$p['id']?>">
                                <button type="submit" class="btn btn-sm btn-success">تأیید</button>
                            </form>
                        <?php else:?>
                            <a href="<?=e(url('admin/edit-parent.php?id='.(int)$p['id']))?>" class="btn btn-sm btn-secondary">ویرایش</a>
                        <?php endif;?>

                        <div class="adm-action-menu">
                            <button type="button" class="adm-action-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="سایر عملیات برای <?=e($p['first_name'].' '.$p['last_name'])?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                            </button>
                            <div class="adm-action-popover" role="menu">
                                <?php if($p['status']==='pending'):?>
                                    <a href="<?=e(url('admin/edit-parent.php?id='.(int)$p['id']))?>" class="adm-action-item" role="menuitem">ویرایش مشخصات</a>
                                <?php endif;?>
                                <a href="<?=e(url('admin/add-child.php?parent_id='.(int)$p['id']))?>" class="adm-action-item" role="menuitem">افزودن کودک</a>
                                <form method="post" action="<?=e(url('admin/parents.php'))?>">
                                    <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                                    <input type="hidden" name="action" value="generate_reset_link">
                                    <input type="hidden" name="parent_id" value="<?=$p['id']?>">
                                    <button type="submit" class="adm-action-item" role="menuitem">ساخت لینک بازیابی رمز</button>
                                </form>
                                <div class="adm-action-divider"></div>
                                <?php if($p['status']==='pending'):?>
                                    <form method="post" action="<?=e(url('admin/parents.php'))?>" data-confirm="آیا این درخواست ثبت‌نام رد شود؟">
                                        <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="parent_id" value="<?=$p['id']?>">
                                        <button type="submit" class="adm-action-item adm-action-item--danger" role="menuitem">رد ثبت‌نام</button>
                                    </form>
                                <?php elseif($p['status']==='active'):?>
                                    <form method="post" action="<?=e(url('admin/parents.php'))?>" data-confirm="آیا حساب این والد مسدود شود؟">
                                        <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="parent_id" value="<?=$p['id']?>">
                                        <button type="submit" class="adm-action-item" role="menuitem">مسدودسازی حساب</button>
                                    </form>
                                <?php else:?>
                                    <form method="post" action="<?=e(url('admin/parents.php'))?>">
                                        <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="parent_id" value="<?=$p['id']?>">
                                        <button type="submit" class="adm-action-item" role="menuitem">فعال‌سازی حساب</button>
                                    </form>
                                <?php endif;?>
                                <?php if(in_array(currentAdminRole(),['owner','manager'],true)):?>
                                    <a href="<?=e(url('admin/delete-parent.php?id='.(int)$p['id']))?>" class="adm-action-item adm-action-item--danger" role="menuitem">حذف دائمی والد</a>
                                <?php endif;?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody></table></div>
        <?php if($pagi['total']>$pagi['perPage']):?>
            <p class="pagination-summary">نمایش <?=e(persianNumber($pagi['from']))?> تا <?=e(persianNumber($pagi['to']))?> از <?=e(persianNumber($pagi['total']))?> والد</p>
            <?=renderPagination($pagi,url('admin/parents.php'),$sf!=='all'?['status'=>$sf]:[])?>
        <?php endif;?>
    <?php endif;?>
</section>
<?php require_once __DIR__.'/footer.php';?>