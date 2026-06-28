<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();
if (isPostRequest()) {
    $csrf=$_POST['csrf_token']??''; $action=(string)($_POST['action']??'');
    $pid=filter_var($_POST['parent_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $pid=is_int($pid)?$pid:0;
    if (!validateCsrfToken($csrf)){setFlash('error','درخواست نامعتبر.');redirect(url('admin/parents.php'));}
    try {
        $pdo=getDb();
        if($pid===0){setFlash('error','شناسه نامعتبر.');redirect(url('admin/parents.php'));}
        $chk=$pdo->prepare('SELECT id,first_name,last_name,email,status FROM parents WHERE id=:id LIMIT 1');
        $chk->execute([':id'=>$pid]); $par=$chk->fetch();
        if(!$par){setFlash('error','یافت نشد.');redirect(url('admin/parents.php'));}
        $updates=['approve'=>'active','reject'=>'suspended','suspend'=>'suspended','activate'=>'active'];
        if(array_key_exists($action,$updates)){
            $ns=$updates[$action];
            $pdo->prepare('UPDATE parents SET status=:s WHERE id=:id')->execute([':s'=>$ns,':id'=>$pid]);
            $msgs=['approve'=>'حساب والد تأیید و فعال شد.','reject'=>'حساب والد رد شد.','suspend'=>'حساب والد مسدود شد.','activate'=>'حساب والد فعال شد.'];
            recordAudit('parent.'.$action,'parent',$pid,['status'=>$ns]);
            setFlash('success',$msgs[$action]);
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
$sql='SELECT id,first_name,last_name,email,phone,status,created_at FROM parents';
if($sf!=='all')$sql.=' WHERE status=:s';
$sql.=' ORDER BY created_at DESC LIMIT '.$pp.' OFFSET '.$off;
$stmt=$pdo->prepare($sql);
if($sf!=='all'){$stmt->execute([':s'=>$sf]);}else{$stmt->execute();}
$parents=$stmt->fetchAll();
$pagi=['current'=>$cp,'totalPages'=>$tp,'total'=>$total,'perPage'=>$pp,'from'=>$total>0?$off+1:0,'to'=>min($off+$pp,$total)];

$pageTitle='parents | '.siteName();
require_once __DIR__.'/header.php';
$fHref=static fn(string $v):string=>url('admin/parents.php?'.http_build_query(['status'=>$v]));
$fe=getFlash('error');$fs=getFlash('success');
?>
<section class="admin-page">
    <div class="admin-section-header"><h2 class="admin-section-title">&#128101; مدیریت والدین</h2></div>
    <?php if($fe!==''):?><div class="alert alert-error"><?=e($fe)?></div><?php endif;?>
    <?php if($fs!==''):?><div class="alert alert-success"><?=e($fs)?></div><?php endif;?>
    <p style="margin-bottom:16px">
        <a href="<?=$fHref('all')?>" class="btn btn-sm <?=$sf==='all'?'btn-primary':'btn-outline'?>">همه</a>
        <a href="<?=$fHref('pending')?>" class="btn btn-sm <?=$sf==='pending'?'btn-primary':'btn-outline'?>">&#9203; در انتظار</a>
        <a href="<?=$fHref('active')?>" class="btn btn-sm <?=$sf==='active'?'btn-primary':'btn-outline'?>">&#9989; فعال</a>
        <a href="<?=$fHref('suspended')?>" class="btn btn-sm <?=$sf==='suspended'?'btn-primary':'btn-outline'?>">&#10060; مسدود</a>
    </p>
    <?php if($parents===[]):?>
        <div class="empty-state empty-state-sm"><div class="empty-state-icon">&#128101;</div><h3>والدی یافت نشد</h3><p><?=$sf==='pending'?'ثبتنام جدیدی در انتظار تأیید نیست.':'لیست خالی است.'?></p></div>
    <?php else:?>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>نام</th><th>ایمیل</th><th>تلفن</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach($parents as $p):?>
            <tr>
                <td style="font-weight:600"><?=e($p['first_name'].' '.$p['last_name'])?></td>
                <td style="font-size:0.85rem"><?=e($p['email'])?></td>
                <td><?=e($p['phone']??'—')?></td>
                <td><?php $bc=match($p['status']){'active'=>'badge-success','pending'=>'badge-warning','suspended'=>'badge-danger',default=>'badge-info'};$sl=match($p['status']){'active'=>'فعال','pending'=>'در انتظار تأیید','suspended'=>'مسدود',default=>$p['status']};?><span class="badge <?=$bc?>"><?=$sl?></span></td>
                <td style="font-size:0.85rem;color:var(--muted)"><?=e(date('Y/m/d',strtotime($p['created_at'])))?></td>
                <td>
                    <?php if($p['status']==='pending'):?>
                        <form method="post" action="<?=e(url('admin/parents.php'))?>" style="display:inline"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="parent_id" value="<?=$p['id']?>"><button type="submit" class="btn btn-sm btn-success">&#9989; تأیید</button></form>
                        <form method="post" action="<?=e(url('admin/parents.php'))?>" style="display:inline" onsubmit="return confirm('رد شود؟')"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="parent_id" value="<?=$p['id']?>"><button type="submit" class="btn btn-sm btn-reject">&#10060; رد</button></form>
                    <?php elseif($p['status']==='active'):?>
                        <form method="post" action="<?=e(url('admin/parents.php'))?>" style="display:inline" onsubmit="return confirm('مسدود شود؟')"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="suspend"><input type="hidden" name="parent_id" value="<?=$p['id']?>"><button type="submit" class="btn btn-sm btn-muted">مسدود</button></form>
                    <?php else:?>
                        <form method="post" action="<?=e(url('admin/parents.php'))?>" style="display:inline"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="parent_id" value="<?=$p['id']?>"><button type="submit" class="btn btn-sm btn-success">فعال‌سازی</button></form>
                    <?php endif;?>
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