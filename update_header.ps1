$filePath = 'c:\xampp\htdocs\roma\templates\header.php'
$content = [System.IO.File]::ReadAllText($filePath, [System.Text.Encoding]::UTF8)

# Find button start and nav start positions
$buttonStart = $content.IndexOf('<button class="mobile-menu-toggle"')
$navStart = $content.IndexOf('<nav class="site-nav"')

if ($buttonStart -lt 0) { Write-Output "ERROR: button not found"; exit 1 }
if ($navStart -lt 0) { Write-Output "ERROR: nav not found"; exit 1 }

Write-Output "Button at: $buttonStart, Nav at: $navStart"

# Build replacement using index-based approach
$newBlock = "        <div class=`"mobile-header-actions`">`r`n"
$newBlock += "            <?php if (isParentLoggedIn()): ?>`r`n"
$newBlock += "                <a href=`"<?php echo e(url('parent/index.php')); ?>`" class=`"btn btn-primary btn-mobile-auth`">`r`n"
$newBlock += '                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' + "`r`n"
$newBlock += "                    <span>پنل والدین</span>`r`n"
$newBlock += "                </a>`r`n"
$newBlock += "            <?php else: ?>`r`n"
$newBlock += "                <a href=`"<?php echo e(url('login.php')); ?>`" class=`"btn btn-primary btn-mobile-auth`">`r`n"
$newBlock += '                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>' + "`r`n"
$newBlock += "                    <span>ورود</span>`r`n"
$newBlock += "                </a>`r`n"
$newBlock += "            <?php endif; ?>`r`n"
$newBlock += "            <?php if (isLoggedIn()): ?>`r`n"
$newBlock += "                <form class=`"logout-form mobile-logout-form`" method=`"post`" action=`"<?php echo e(url('admin/logout.php')); ?>`">`r`n"
$newBlock += "                    <input type=`"hidden`" name=`"csrf_token`" value=`"<?php echo e(generateCsrfToken()); ?>`">`r`n"
$newBlock += '                    <button type="submit" class="btn btn-outline btn-mobile-logout" title="خروج">' + "`r`n"
$newBlock += '                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>' + "`r`n"
$newBlock += "                    </button>`r`n"
$newBlock += "                </form>`r`n"
$newBlock += "            <?php endif; ?>`r`n"
$newBlock += "        </div>`r`n"
$newBlock += "`r`n"
$newBlock += "        <nav class=`"site-nav`" aria-label=`"منوی اصلی`">`r`n"

# Find end of nav opening tag
$navEndIdx = $content.IndexOf('>', $navStart) + 1

$newContent = $content.Substring(0, $buttonStart) + $newBlock + $content.Substring($navEndIdx)

[System.IO.File]::WriteAllText($filePath, $newContent, [System.Text.Encoding]::UTF8)
Write-Output 'SUCCESS: Header updated'
