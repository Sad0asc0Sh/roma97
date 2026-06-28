$filePath = 'c:\xampp\htdocs\roma\templates\header.php'
$content = [System.IO.File]::ReadAllText($filePath, [System.Text.Encoding]::UTF8)

# Find the exact content around mobile-menu-toggle
$idx = $content.IndexOf('mobile-menu-toggle')
Write-Output "Index: $idx"
$surrounding = $content.Substring([Math]::Max(0, $idx - 20), 400)
Write-Output "--- SURROUNDING ---"
Write-Output $surrounding
Write-Output "--- END ---"

# Also show hex of chars around the aria-label
$ariaIdx = $content.IndexOf('aria-label="')
if ($ariaIdx -ge 0) {
    Write-Output "aria-label at: $ariaIdx"
    $chunk = $content.Substring($ariaIdx, 80)
    Write-Output "Chunk: $chunk"
}
