<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
?>
<!-- Custom App Confirm Modal -->
<div class="app-modal-overlay" id="appConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="appConfirmTitle">
    <div class="app-confirm-dialog">
        <div class="app-confirm-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 class="app-confirm-title" id="appConfirmTitle">تأیید عملیات</h3>
        <p class="app-confirm-message" id="appConfirmMessage">آیا از انجام این عملیات اطمینان دارید؟</p>
        <div class="app-confirm-actions">
            <button type="button" class="app-btn app-btn-secondary" id="appConfirmCancel">انصراف</button>
            <button type="button" class="app-btn app-btn-danger" id="appConfirmProceed">تأیید و ادامه</button>
        </div>
    </div>
</div>

<!-- Custom App Drawer -->
<div class="app-drawer-overlay" id="appDrawerOverlay"></div>
<div class="app-drawer" id="appDrawer" role="dialog" aria-modal="true" aria-labelledby="appDrawerTitle">
    <div class="app-drawer-header">
        <h3 class="app-drawer-title" id="appDrawerTitle">فرم مدیریت</h3>
        <button type="button" class="app-drawer-close" id="appDrawerClose" aria-label="بستن">&times;</button>
    </div>
    <div class="app-drawer-body" id="appDrawerBody">
        <!-- Dynamic form content rendered here -->
    </div>
</div>

<!-- Toast Container -->
<div class="app-toast-container" id="appToastContainer"></div>
