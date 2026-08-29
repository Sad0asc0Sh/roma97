<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ui.php';

sendSecurityHeaders();

$logo            = siteLogo();
$currentPage     = basename($_SERVER['PHP_SELF']);
$currentSlug     = (string) ($_GET['slug'] ?? '');
$siteNameValue   = siteName();
$contactPhone    = siteContactPhone();
$contactEmail    = siteContactEmail();
$siteAddressV    = siteAddress();
$workingHoursV   = siteWorkingHours();
$instagramV      = siteInstagram();
$telegramV       = siteTelegram();
$whatsappV       = siteWhatsApp();

$pageTitleValue       = isset($pageTitle) ? e($pageTitle) : e($siteNameValue);
$pageDescriptionValue = isset($pageDescription) ? e($pageDescription) : e(siteDescription());

$navItems = roomaPublicNav($currentPage, $currentSlug);

// Canonical URL — strip the base path already present in SITE_URL so a
// sub-folder install does not end up doubled.
$canonicalScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$siteUrlPath     = basePath();
if ($siteUrlPath !== '' && str_starts_with($canonicalScript, $siteUrlPath)) {
    $canonicalScript = substr($canonicalScript, strlen($siteUrlPath));
}
$canonicalUrl = absoluteUrl(ltrim($canonicalScript, '/'));

$ogImage = absoluteUrl(
    is_file(__DIR__ . '/../assets/img/hero-classroom.jpg')
        ? 'assets/img/hero-classroom.jpg'
        : 'assets/img/about-story.jpg'
);

$topbarEnabled = siteTopbarEnabled();
$topbarText    = siteTopbarText();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php // Apply the stored theme before first paint so there is no flash of the wrong theme.
          // The same pass marks the document as script-capable ('js-head'), which lets the
          // floating header start hidden from the very first frame. rooma.js only adds
          // 'js-enabled' after it is parsed (defer), which is far too late to prevent a flash
          // of the revealed header on a slow connection. ?>
    <script>(function(){var r=document.documentElement;try{var t=localStorage.getItem('roma_theme');if(!t){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}r.setAttribute('data-theme',t);}catch(e){r.setAttribute('data-theme','light');}r.className+=(r.className?' ':'')+'js-head';})();</script>

    <title><?php echo $pageTitleValue; ?></title>
    <meta name="description" content="<?php echo $pageDescriptionValue; ?>">
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <meta name="theme-color" content="#3D8B63" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0B1712" media="(prefers-color-scheme: dark)">
    <meta name="format-detection" content="telephone=no">

    <?php // Font preloads must match the exact URLs fonts.css requests (unversioned)
          // or the browser downloads each face twice. ?>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Regular.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Medium.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Bold.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo e(url('assets/fonts/BalooBhaijaan2-arabic.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo e(url('assets/css/fonts.css')); ?>">

    <link rel="icon" href="<?php echo e(asset('favicon.ico')); ?>" sizes="32x32">
    <link rel="icon" href="<?php echo e(asset('assets/img/favicon.svg')); ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?php echo e(asset('assets/img/favicon.svg')); ?>">

    <link rel="stylesheet" href="<?php echo e(asset('assets/css/rooma-core.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/rooma-components.css')); ?>">

    <!-- Open Graph / Twitter -->
    <meta property="og:title" content="<?php echo $pageTitleValue; ?>">
    <meta property="og:description" content="<?php echo $pageDescriptionValue; ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:site_name" content="<?php echo e($siteNameValue); ?>">
    <meta property="og:image" content="<?php echo e($ogImage); ?>">
    <meta property="og:image:width" content="1920">
    <meta property="og:image:height" content="1072">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $pageTitleValue; ?>">
    <meta name="twitter:description" content="<?php echo $pageDescriptionValue; ?>">
    <meta name="twitter:image" content="<?php echo e($ogImage); ?>">

    <?php // Chromium prerenders public pages on hover for instant navigation. ?>
    <script type="speculationrules">
    {
      "prerender": [{
        "where": { "and": [
          { "href_matches": "/*" },
          { "not": { "href_matches": "/*/admin/*" } },
          { "not": { "href_matches": "/*/parent/*" } },
          { "not": { "href_matches": "/*/teacher/*" } },
          { "not": { "href_matches": "/*logout*" } }
        ]},
        "eagerness": "moderate"
      }]
    }
    </script>

    <!-- Structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "ChildCare",
      "name": "<?php echo e($siteNameValue); ?>",
      "description": "<?php echo $pageDescriptionValue; ?>",
      "url": "<?php echo e($canonicalUrl); ?>",
      "image": "<?php echo e($ogImage); ?>",
<?php $schemaSocial = array_values(array_filter([$instagramV, $telegramV], static fn ($v) => $v !== '')); ?>
<?php if ($schemaSocial !== []): ?>
      "sameAs": <?php echo json_encode($schemaSocial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
<?php endif; ?>
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "<?php echo e($siteAddressV); ?>",
        "addressLocality": "تهران",
        "addressCountry": "IR"
      },
      "telephone": "<?php echo e(telHref($contactPhone)); ?>",
      "openingHoursSpecification": [
        { "@type": "OpeningHoursSpecification", "dayOfWeek": ["Saturday","Sunday","Monday","Tuesday","Wednesday"], "opens": "07:00", "closes": "16:30" },
        { "@type": "OpeningHoursSpecification", "dayOfWeek": "Thursday", "opens": "07:00", "closes": "13:00" }
      ],
      "priceRange": "$$"
    }
    </script>
</head>
<body>

<a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>

<?php /* ── Animated background canvas ─────────────────────────────────── */ ?>
<div class="bg-canvas" aria-hidden="true">
    <span class="bg-blob bg-blob-1"></span>
    <span class="bg-blob bg-blob-2"></span>
    <span class="bg-blob bg-blob-3"></span>
    <span class="bg-blob bg-blob-4"></span>
    <span class="bg-grain"></span>
    <span class="bg-motes">
        <span class="bg-mote"></span><span class="bg-mote"></span><span class="bg-mote"></span>
        <span class="bg-mote"></span><span class="bg-mote"></span><span class="bg-mote"></span>
    </span>
</div>

<?php /* ── Scroll progress ─────────────────────────────────────────────── */ ?>
<div class="scroll-progress" aria-hidden="true">
    <div class="scroll-progress__bar" data-progress-bar></div>
</div>

<?php /* ── Top bar ─────────────────────────────────────────────────────── */ ?>
<?php if ($topbarEnabled === '1'): ?>
<div class="topbar">
    <div class="container topbar__inner">
        <?php if ($topbarText !== ''): ?>
            <div class="topbar__marquee"><?php echo e($topbarText); ?></div>
        <?php else: ?>
            <div class="topbar__group">
                <?php if ($contactPhone !== ''): ?>
                    <a class="topbar__item" href="tel:<?php echo e(telHref($contactPhone)); ?>">
                        <?php echo icon('phone', 14); ?>
                        <span><?php echo e(persianNumber($contactPhone)); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($contactEmail !== ''): ?>
                    <a class="topbar__item topbar__item--email" href="mailto:<?php echo e($contactEmail); ?>">
                        <?php echo icon('mail', 14); ?>
                        <span><?php echo e($contactEmail); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($workingHoursV !== ''): ?>
                    <span class="topbar__item topbar__item--hours">
                        <?php echo icon('clock', 14); ?>
                        <span><?php echo e(persianNumber($workingHoursV)); ?></span>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($instagramV !== '' || $telegramV !== '' || $whatsappV !== ''): ?>
            <div class="topbar__socials">
                <?php if ($instagramV !== ''): ?>
                    <a class="topbar__social" href="<?php echo e($instagramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="اینستاگرام روما"><?php echo brandIcon('instagram', 14); ?></a>
                <?php endif; ?>
                <?php if ($telegramV !== ''): ?>
                    <a class="topbar__social" href="<?php echo e($telegramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="تلگرام روما"><?php echo brandIcon('telegram', 14); ?></a>
                <?php endif; ?>
                <?php if ($whatsappV !== ''): ?>
                    <a class="topbar__social" href="https://wa.me/<?php echo e(whatsappHref($whatsappV)); ?>" target="_blank" rel="noopener noreferrer" aria-label="واتس‌اپ روما"><?php echo brandIcon('whatsapp', 14); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ── Header ──────────────────────────────────────────────────────── */ ?>
<header class="site-header" id="siteHeader" data-header>
  <div class="site-header__shell" data-header-shell>
    <span class="site-header__glow" aria-hidden="true"></span>
    <div class="site-header__inner">

        <a class="brand" href="<?php echo e(url('index.php')); ?>" aria-label="<?php echo e($siteNameValue); ?> — صفحه اصلی">
            <?php if ($logo !== ''): ?>
                <img class="brand__logo" src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" width="150" height="40">
            <?php else: ?>
                <span class="brand__mark"><?php echo roomaLogoMark(40); ?></span>
                <span class="brand__text">
                    <span class="brand__name"><?php echo e($siteNameValue); ?></span>
                    <span class="brand__tag">مهدکودک و پیش‌دبستانی</span>
                </span>
            <?php endif; ?>
        </a>

        <nav class="nav" aria-label="منوی اصلی" data-nav>
            <span class="nav__pill" data-nav-pill aria-hidden="true"></span>
            <?php foreach ($navItems as $item): ?>
                <a class="nav__link<?php echo $item['active'] ? ' is-active' : ''; ?>"
                   href="<?php echo e(url($item['href'])); ?>"
                   <?php echo $item['active'] ? 'aria-current="page"' : ''; ?>>
                    <?php echo icon($item['icon'], 17); ?>
                    <span><?php echo e($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="header-actions">
            <button type="button" class="icon-btn" data-theme-toggle aria-pressed="false" aria-label="تغییر حالت شب/روز">
                <span class="theme-toggle__moon"><?php echo icon('moon', 19); ?></span>
                <span class="theme-toggle__sun"><?php echo icon('sun', 19); ?></span>
            </button>

            <?php if (isParentLoggedIn()): ?>
                <a class="btn btn-primary btn-sm hide-mobile" href="<?php echo e(url('parent/index.php')); ?>">
                    <?php echo icon('user', 16); ?>
                    <span>پنل والدین</span>
                </a>
            <?php elseif (isLoggedIn()): ?>
                <a class="btn btn-primary btn-sm hide-mobile" href="<?php echo e(url('admin/index.php')); ?>">
                    <?php echo icon('grid', 16); ?>
                    <span>پنل مدیریت</span>
                </a>
            <?php else: ?>
                <a class="btn btn-primary btn-sm hide-mobile" href="<?php echo e(url('login.php')); ?>">
                    <?php echo icon('login', 16); ?>
                    <span>ورود والدین</span>
                </a>
            <?php endif; ?>

            <button type="button" class="icon-btn burger" data-drawer-open aria-expanded="false" aria-controls="roomaDrawer" aria-label="باز کردن منو">
                <span class="burger__box">
                    <span class="burger__line"></span>
                    <span class="burger__line"></span>
                    <span class="burger__line"></span>
                </span>
            </button>
        </div>
    </div>
  </div>
</header>

<?php /* ── Mobile drawer ───────────────────────────────────────────────── */ ?>
<div class="drawer-overlay" data-drawer-overlay aria-hidden="true"></div>

<aside class="drawer" id="roomaDrawer" data-drawer aria-hidden="true" aria-label="منوی موبایل">
    <div class="drawer__head">
        <span class="brand">
            <span class="brand__mark"><?php echo roomaLogoMark(34, 'drawerLogoGrad'); ?></span>
            <span class="brand__text">
                <span class="brand__name"><?php echo e($siteNameValue); ?></span>
                <span class="brand__tag">مهدکودک و پیش‌دبستانی</span>
            </span>
        </span>
        <button type="button" class="icon-btn" data-drawer-close aria-label="بستن منو">
            <?php echo icon('close', 19); ?>
        </button>
    </div>

    <div class="drawer__body">
        <nav class="drawer__nav" aria-label="پیوندهای اصلی">
            <?php foreach ($navItems as $i => $item): ?>
                <a class="drawer__link<?php echo $item['active'] ? ' is-active' : ''; ?>"
                   href="<?php echo e(url($item['href'])); ?>"
                   style="--i: <?php echo (int) $i; ?>"
                   <?php echo $item['active'] ? 'aria-current="page"' : ''; ?>>
                    <?php echo icon($item['icon'], 20); ?>
                    <span><?php echo e($item['label']); ?></span>
                    <span class="drawer__link-arrow"><?php echo icon('chevron-left', 16); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="drawer__divider"></div>

        <div class="drawer__section-title">حساب کاربری</div>
        <nav class="drawer__nav" aria-label="حساب کاربری">
            <?php if (isParentLoggedIn()): ?>
                <a class="drawer__link" href="<?php echo e(url('parent/index.php')); ?>" style="--i: 5">
                    <?php echo icon('user', 20); ?>
                    <span>پنل والدین</span>
                    <span class="drawer__link-arrow"><?php echo icon('chevron-left', 16); ?></span>
                </a>
            <?php elseif (isLoggedIn()): ?>
                <a class="drawer__link" href="<?php echo e(url('admin/index.php')); ?>" style="--i: 5">
                    <?php echo icon('grid', 20); ?>
                    <span>پنل مدیریت</span>
                    <span class="drawer__link-arrow"><?php echo icon('chevron-left', 16); ?></span>
                </a>
            <?php else: ?>
                <a class="drawer__link" href="<?php echo e(url('login.php')); ?>" style="--i: 5">
                    <?php echo icon('login', 20); ?>
                    <span>ورود والدین</span>
                    <span class="drawer__link-arrow"><?php echo icon('chevron-left', 16); ?></span>
                </a>
                <a class="drawer__link" href="<?php echo e(url('register.php')); ?>" style="--i: 6">
                    <?php echo icon('user', 20); ?>
                    <span>ثبت‌نام والدین</span>
                    <span class="drawer__link-arrow"><?php echo icon('chevron-left', 16); ?></span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="drawer__foot">
        <?php if ($contactPhone !== ''): ?>
            <a class="drawer__contact" href="tel:<?php echo e(telHref($contactPhone)); ?>">
                <?php echo icon('phone', 15); ?>
                <span><?php echo e(persianNumber($contactPhone)); ?></span>
            </a>
        <?php endif; ?>
        <?php if ($workingHoursV !== ''): ?>
            <span class="drawer__contact">
                <?php echo icon('clock', 15); ?>
                <span><?php echo e(persianNumber($workingHoursV)); ?></span>
            </span>
        <?php endif; ?>

        <?php if ($instagramV !== '' || $telegramV !== '' || $whatsappV !== ''): ?>
        <div class="drawer__socials">
            <?php if ($instagramV !== ''): ?>
                <a class="drawer__social" href="<?php echo e($instagramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="اینستاگرام روما"><?php echo brandIcon('instagram', 16); ?></a>
            <?php endif; ?>
            <?php if ($telegramV !== ''): ?>
                <a class="drawer__social" href="<?php echo e($telegramV); ?>" target="_blank" rel="noopener noreferrer" aria-label="تلگرام روما"><?php echo brandIcon('telegram', 16); ?></a>
            <?php endif; ?>
            <?php if ($whatsappV !== ''): ?>
                <a class="drawer__social" href="https://wa.me/<?php echo e(whatsappHref($whatsappV)); ?>" target="_blank" rel="noopener noreferrer" aria-label="واتس‌اپ روما"><?php echo brandIcon('whatsapp', 16); ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <a class="btn btn-primary btn-block" href="<?php echo e(url('page.php?slug=contact')); ?>">
            <?php echo icon('calendar', 17); ?>
            <span>رزرو بازدید حضوری</span>
        </a>
    </div>
</aside>

<main class="site-main" id="main-content">
