<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

function gsLen(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
function findGal(PDO $p, int $id): ?array { $s=$p->prepare('SELECT * FROM gallery_images WHERE id=:i LIMIT 1'); $s->execute([':i'=>$id]); return $s->fetch()?:null; }
function allGal(PDO $p, int $l=20, int $o=0): array { $s=$p->prepare('SELECT * FROM gallery_images ORDER BY sort_order ASC, created_at DESC LIMIT '.(int)$l.' OFFSET '.(int)$o); $s->execute(); return $s->fetchAll(); }
function countGal(PDO $p): int { return (int)$p->query('SELECT COUNT(*) FROM gallery_images')->fetchColumn(); }
function pGid(mixed $v): int { $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return is_int($id)?$id:0; }
function pGso(mixed $v): ?int { $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]); return is_int($n)?$n:null; }
function delGalF(string $p): void { if($p==='')return;$pr=realpath(__DIR__.'/..');$ur=realpath(__DIR__.'/../assets/uploads');if($pr===false||$ur===false)return;$c=realpath($pr.DIRECTORY_SEPARATOR.str_replace(['/','\\'],DIRECTORY_SEPARATOR,$p));if($c!==false&&str_starts_with($c,$ur.DIRECTORY_SEPARATOR)&&is_file($c))@unlink($c); }
function upGalI(array $f,bool $r):?string{
    if(($f['error']??4)===4){if($r)throw new RuntimeException('err');return null;}
    if(($f['error']??0)!==0)throw new RuntimeException('err');
    if(($f['size']??0)>2048000)throw new RuntimeException('max 2MB');
    $t=(string)($f['tmp_name']??'');if($t===''||!is_uploaded_file($t))throw new RuntimeException('err');
    $e=strtolower(pathinfo((string)($f['name']??''),PATHINFO_EXTENSION));
    $ok=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp']];
    if(!array_key_exists($e,$ok))throw new RuntimeException('bad format');
    if(class_exists('finfo')){$fi=new finfo(FILEINFO_MIME_TYPE);$m=$fi->file($t);if(!is_string($m)||!in_array($m,$ok[$e],true))throw new RuntimeException('err');}
    if(getimagesize($t)===false)throw new RuntimeException('err');
    $d=__DIR__.'/../assets/uploads/gallery';if(!is_dir($d)&&!mkdir($d,0755,true))throw new RuntimeException('err');
    $fn=uniqid('gallery_',true).'.'.$e;$ds=$d.DIRECTORY_SEPARATOR.$fn;if(!move_uploaded_file($t,$ds))throw new RuntimeException('err');
    return 'assets/uploads/gallery/'.$fn;
}

try {
    $pdo = getDb();
    $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_images (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) DEFAULT NULL,
        caption VARCHAR(500) DEFAULT NULL, image VARCHAR(255) NOT NULL,
        sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { error_log($e->getMessage()); setFlash('error','gallery unavailable'); redirect(url('admin/index.php')); }

if (isPostRequest()) {
    $csrf=$_POST['csrf_token']??''; $act=(string)($_POST['action']??'');
    if (!validateCsrfToken($csrf)) { setFlash('error','bad request'); redirect(url('admin/gallery.php')); }
    try {
        if ($act==='save_image') {
            $iid=pGid($_POST['image_id']??null);$t=trim((string)($_POST['title']??''));$c=trim((string)($_POST['caption']??''));
            $so=pGso($_POST['sort_order']??null);$ia=isset($_POST['is_active'])?1:0;$ed=$iid>0;$cur=null;$ni=null;
            if($so===null){setFlash('error','invalid sort');redirect(url('admin/gallery.php'));}
            if($ed){$cur=findGal($pdo,$iid);if(!$cur){setFlash('error','not found');redirect(url('admin/gallery.php'));}}
            $ni=upGalI($_FILES['image']??[],!$ed);$ip=$ni??(string)($cur['image']??'');
            if($ip===''){setFlash('error','upload required');redirect(url('admin/gallery.php'));}
            if($ed){
                $pdo->prepare('UPDATE gallery_images SET title=:t,caption=:c,image=:i,sort_order=:s,is_active=:a WHERE id=:id')->execute([':t'=>$t!==''?$t:null,':c'=>$c!==''?$c:null,':i'=>$ip,':s'=>$so,':a'=>$ia,':id'=>$iid]);
                if($ni!==null)delGalF((string)$cur['image']);
                recordAudit('gallery.update','gallery',(int)$iid);setFlash('success','updated');redirect(url('admin/gallery.php'));
            }
            $pdo->prepare('INSERT INTO gallery_images (title,caption,image,sort_order,is_active) VALUES (:t,:c,:i,:s,:a)')->execute([':t'=>$t!==''?$t:null,':c'=>$c!==''?$c:null,':i'=>$ip,':s'=>$so,':a'=>$ia]);
            recordAudit('gallery.create','gallery',(int)$pdo->lastInsertId());setFlash('success','created');redirect(url('admin/gallery.php'));
        }
        if($act==='delete_image'){$iid=pGid($_POST['image_id']??null);$img=$iid>0?findGal($pdo,$iid):null;if(!$img){setFlash('error','not found');redirect(url('admin/gallery.php'));}$pdo->prepare('DELETE FROM gallery_images WHERE id=:id')->execute([':id'=>$iid]);delGalF((string)$img['image']);recordAudit('gallery.delete','gallery',(int)$iid);setFlash('success','deleted');redirect(url('admin/gallery.php'));}
        if($act==='toggle_active'){$iid=pGid($_POST['image_id']??null);$img=$iid>0?findGal($pdo,$iid):null;if(!$img){setFlash('error','not found');redirect(url('admin/gallery.php'));}$ns=((int)$img['is_active']===1)?0:1;$pdo->prepare('UPDATE gallery_images SET is_active=:a WHERE id=:id')->execute([':a'=>$ns,':id'=>$iid]);recordAudit('gallery.toggle','gallery',(int)$iid);setFlash('success','toggled');redirect(url('admin/gallery.php'));}
        if($act==='update_sort'){$o=$_POST['sort_order']??[];if(!is_array($o)){setFlash('error','invalid');redirect(url('admin/gallery.php'));}$pdo->beginTransaction();$st=$pdo->prepare('UPDATE gallery_images SET sort_order=:s WHERE id=:id');foreach($o as $k=>$v){$g=pGid($k);$gs=pGso($v);if($g===0||$gs===null){$pdo->rollBack();setFlash('error','invalid');redirect(url('admin/gallery.php'));}$st->execute([':s'=>$gs,':id'=>$g]);}$pdo->commit();recordAudit('gallery.reorder','gallery');setFlash('success','reordered');redirect(url('admin/gallery.php'));}
        setFlash('error','invalid');redirect(url('admin/gallery.php'));
    }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(isset($ni)&&is_string($ni))delGalF($ni);error_log($e->getMessage());setFlash('error','save failed');redirect(url('admin/gallery.php'));}
}

$eid=pGid($_GET['edit']??null);$editImage=$eid>0?findGal($pdo,$eid):null;
$perPage=20;$cp=max(1,(int)($_GET['page']??1));$total=countGal($pdo);
$tp=max(1,(int)ceil($total/$perPage));$cp=min($cp,$tp);
$off=($cp-1)*$perPage;$galleryImages=allGal($pdo,$perPage,$off);
$pagi=['current'=>$cp,'totalPages'=>$tp,'total'=>$total,'perPage'=>$perPage,'from'=>$total>0?$off+1:0,'to'=>min($off+$perPage,$total)];
$pageTitle='gallery | '.siteName();
require_once __DIR__.'/header.php';
?>

<section class="admin-page">
    <div class="admin-section">
        <div class="admin-section-header"><h2 class="admin-section-title"><?=$editImage?'&#9998; ویرایش تصویر':'&#128247; افزودن تصویر جدید'?></h2></div>
        <?php $fe=getFlash('error');$fs=getFlash('success'); ?>
        <?php if($fe!==''):?><div class="alert alert-error"><?=e($fe)?></div><?php endif;?>
        <?php if($fs!==''):?><div class="alert alert-success"><?=e($fs)?></div><?php endif;?>
        <form method="post" enctype="multipart/form-data" action="<?=e(url('admin/gallery.php'))?>" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
            <input type="hidden" name="action" value="save_image">
            <?php if($editImage):?><input type="hidden" name="image_id" value="<?=e($editImage['id'])?>"><?php endif;?>
            <div class="form-grid form-grid-2">
                <div class="form-group"><label for="gtitle" class="form-label">عنوان (اختیاری)</label><input type="text" id="gtitle" name="title" class="form-control" value="<?=e($editImage['title']??'')?>" maxlength="255"></div>
                <div class="form-group"><label for="gso" class="form-label">ترتیب نمایش</label><input type="number" id="gso" name="sort_order" class="form-control" value="<?=e($editImage['sort_order']??'0')?>" min="0" step="1" required></div>
            </div>
            <div class="form-group"><label for="gcap" class="form-label">توضیحات (اختیاری)</label><textarea id="gcap" name="caption" class="form-control" rows="2" maxlength="500"><?=e($editImage['caption']??'')?></textarea></div>
            <div class="form-group"><label for="gimg" class="form-label">تصویر <?=$editImage?'<span class="text-muted">(برای تغییر جدید)</span>':''?></label><input type="file" id="gimg" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" <?=$editImage?'':'required'?>><small class="text-muted">JPG, PNG, GIF, WebP — max 2MB</small></div>
            <?php if($editImage):?><div class="form-group"><label class="form-label">تصویر فعلی</label><div class="admin-image-preview-wrap"><img src="<?=e(url($editImage['image']))?>" alt="" class="admin-image-preview"></div></div><?php endif;?>
            <div class="form-group"><label class="form-check"><input type="checkbox" name="is_active" value="1" <?=($editImage===null||(int)$editImage['is_active']===1)?'checked':''?>> <span class="form-check-label">فعال</span></label></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary"><?=$editImage?'&#128190; بروزرسانی':'&#10010; افزودن'?></button><?php if($editImage):?><a href="<?=e(url('admin/gallery.php'))?>" class="btn btn-outline">&#10006; لغو</a><?php endif;?></div>
        </form>
    </div>
    <div class="admin-section">
        <div class="admin-section-header"><h2 class="admin-section-title">&#128444; همه تصاویر</h2></div>
        <?php if($galleryImages===[]):?>
            <div class="empty-state empty-state-sm"><div class="empty-state-icon">&#128247;</div><h3>هنوز تصویری نیست</h3><p>از فرم بالا اضافه کنید.</p></div>
        <?php else:?>
            <form method="post" action="<?=e(url('admin/gallery.php'))?>">
                <input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>">
                <input type="hidden" name="action" value="update_sort">
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>تصویر</th><th>عنوان</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
                <?php foreach($galleryImages as $gi):?>
                    <tr>
                        <td><img src="<?=e(url($gi['image']))?>" alt="" class="admin-gallery-thumb"></td>
                        <td style="font-weight:600"><?=e($gi['title']??'—')?></td>
                        <td><input type="number" name="sort_order[<?=e($gi['id'])?>]" min="0" value="<?=e($gi['sort_order'])?>" class="admin-sort-input" required></td>
                        <td><form method="post" action="<?=e(url('admin/gallery.php'))?>" style="display:inline"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="image_id" value="<?=e($gi['id'])?>"><button type="submit" class="btn btn-xs <?=(int)$gi['is_active']===1?'btn-success':'btn-muted'?>"><?=(int)$gi['is_active']===1?'&#9989; فعال':'&#10060; غیرفعال'?></button></form></td>
                        <td><a href="<?=e(url('admin/gallery.php?edit='.$gi['id']))?>" class="btn btn-sm btn-secondary">&#9998;</a> <form method="post" action="<?=e(url('admin/gallery.php'))?>" style="display:inline" onsubmit="return confirm('حذف شود؟')"><input type="hidden" name="csrf_token" value="<?=e(generateCsrfToken())?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?=e($gi['id'])?>"><button type="submit" class="btn btn-sm btn-reject">&#128465;</button></form></td>
                    </tr>
                <?php endforeach;?>
                </tbody></table></div>
                <div class="form-actions"><button type="submit" class="btn btn-primary btn-sm">&#128260; بروزرسانی ترتیب</button></div>
            </form>
            <?php if($pagi['total']>$pagi['perPage']):?>
                <p class="pagination-summary">نمایش <?=e(persianNumber($pagi['from']))?> تا <?=e(persianNumber($pagi['to']))?> از <?=e(persianNumber($pagi['total']))?> تصویر</p>
                <?=renderPagination($pagi, url('admin/gallery.php'))?>
            <?php endif;?>
        <?php endif;?>
    </div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>