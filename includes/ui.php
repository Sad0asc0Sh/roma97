<?php
declare(strict_types=1);

/**
 * Roma — public site UI helpers.
 *
 * Centralises the inline SVG icon set and small presentational helpers that
 * were previously duplicated (often several times per page) across the
 * templates. Keeping the paths in one place means an icon is fixed once.
 */

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Stroke-style ("line") icon path data, drawn on a 24×24 grid.
 *
 * @return array<string, string>
 */
function roomaIconPaths(): array
{
    static $paths = null;

    if ($paths !== null) {
        return $paths;
    }

    $paths = [
        'home'        => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'news'        => '<path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>',
        'cap'         => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/>',
        'phone'       => '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>',
        'mail'        => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'pin'         => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
        'user'        => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users'       => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'login'       => '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'logout'      => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'moon'        => '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>',
        'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'award'       => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>',
        'camera'      => '<path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/>',
        'heart'       => '<path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>',
        'check'       => '<polyline points="20 6 9 17 4 12"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check-circle'=> '<circle cx="12" cy="12" r="10"/><polyline points="16 10 11 15 8 12"/>',
        'chevron-down'=> '<polyline points="6 9 12 15 18 9"/>',
        'chevron-up'  => '<polyline points="18 15 12 9 6 15"/>',
        'arrow-left'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'book'        => '<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>',
        'star'        => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'smile'       => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>',
        'brain'       => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'coffee'      => '<path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>',
        'book-open'   => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="9" y1="10" x2="15" y2="10"/>',
        'music'       => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'palette'     => '<circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="14" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="15" r="2.5"/><path d="M12 22a10 10 0 110-20"/>',
        'file-text'   => '<path d="M14.5 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'baby'        => '<circle cx="12" cy="12" r="10"/><path d="M9 15s1.2 1.5 3 1.5 3-1.5 3-1.5"/><path d="M8.5 9.5h.01M15.5 9.5h.01"/>',
        'sparkles'    => '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M18.5 15.5l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9z"/>',
        'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'external'    => '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'menu'        => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'close'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'chevron-left'=> '<polyline points="15 18 9 12 15 6"/>',
        'chevron-right'=> '<polyline points="9 18 15 12 9 6"/>',
        'target'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'leaf'        => '<path d="M11 20A7 7 0 019.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'sun-cloud'   => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>',
        'message'     => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
        'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'puzzle'      => '<path d="M4 7h3a2 2 0 002-2V4a2 2 0 114 0v1a2 2 0 002 2h3a1 1 0 011 1v3a2 2 0 000 4v3a1 1 0 01-1 1h-3a2 2 0 01-2-2 2 2 0 10-4 0 2 2 0 01-2 2H4a1 1 0 01-1-1v-3a2 2 0 000-4V8a1 1 0 011-1z"/>',
        'utensils'    => '<path d="M3 2v7a3 3 0 003 3v10M9 2v7a3 3 0 01-3 3"/><path d="M6 2v6"/><path d="M18 2v20"/><path d="M15 2c0 4 1.5 6 3 6"/>',
        'bed'         => '<path d="M2 18v-6a2 2 0 012-2h16a2 2 0 012 2v6"/><path d="M2 18h20"/><path d="M6 10V7a1 1 0 011-1h4a1 1 0 011 1v3"/>',
        'trending'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    ];

    return $paths;
}

/**
 * Filled ("brand") icon path data, drawn on a 24×24 grid.
 *
 * @return array<string, string>
 */
function roomaBrandIconPaths(): array
{
    static $paths = null;

    if ($paths !== null) {
        return $paths;
    }

    $paths = [
        'instagram' => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>',
        'telegram'  => '<path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>',
        'whatsapp'  => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>',
        'twitter'   => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
        'star'      => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'play'      => '<path d="M8 5v14l11-7z"/>',
        'pause'     => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'quote'     => '<path d="M9.5 4C6 4 3 7 3 10.5V20h7v-8H6.5c0-2.2 1.3-3.5 3-3.5zM20.5 4C17 4 14 7 14 10.5V20h7v-8h-3.5c0-2.2 1.3-3.5 3-3.5z"/>',
    ];

    return $paths;
}

/**
 * Render a stroke ("line") icon.
 *
 * @param string $name  Key from roomaIconPaths()
 * @param int    $size  Rendered px size (both axes)
 * @param array  $attrs Extra attributes, e.g. ['class' => 'foo']
 */
function icon(string $name, int $size = 20, array $attrs = []): string
{
    $paths = roomaIconPaths();

    if (!isset($paths[$name])) {
        return '';
    }

    $extra = '';
    foreach ($attrs as $key => $value) {
        $extra .= ' ' . $key . '="' . e((string) $value) . '"';
    }

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false"' . $extra . '>'
        . $paths[$name]
        . '</svg>';
}

/**
 * Render a filled ("brand") icon.
 */
function brandIcon(string $name, int $size = 18, array $attrs = []): string
{
    $paths = roomaBrandIconPaths();

    if (!isset($paths[$name])) {
        return '';
    }

    $extra = '';
    foreach ($attrs as $key => $value) {
        $extra .= ' ' . $key . '="' . e((string) $value) . '"';
    }

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
        . ' fill="currentColor" stroke="none"'
        . ' aria-hidden="true" focusable="false"' . $extra . '>'
        . $paths[$name]
        . '</svg>';
}

/**
 * Inline Bargoo mascot (public empty states / CTA).
 *
 * Rendered in the page DOM instead of via external <use href="bargoo.svg#id">
 * so the logo cannot vanish when fragment resolution or sprite loading fails.
 *
 * @param array<string, mixed> $attrs
 */
function bargooSvg(string $pose = 'happy', array $attrs = []): string
{
    static $poses = [
        'happy'     => '<path d="M50,5 C75,5 92,30 90,60 C88,85 68,95 50,95 C32,95 12,85 10,60 C8,30 25,5 50,5 Z" fill="#3D8B63"/><path d="M50,15 Q55,45 50,85" stroke="#2E6B4C" stroke-width="2.5" fill="none" stroke-linecap="round"/><circle cx="32" cy="56" r="6" fill="#C4724A" opacity="0.4"/><circle cx="68" cy="56" r="6" fill="#C4724A" opacity="0.4"/><path d="M30,48 Q35,42 40,48" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round"/><path d="M60,48 Q65,42 70,48" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round"/><path d="M38,60 Q50,72 62,60" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round"/><path d="M50,5 C48,-2 52,-5 54,-3" stroke="#2E6B4C" stroke-width="3" fill="none" stroke-linecap="round"/>',
        'sleeping'  => '<path d="M50,5 C75,5 92,30 90,60 C88,85 68,95 50,95 C32,95 12,85 10,60 C8,30 25,5 50,5 Z" fill="#3D8B63"/><path d="M50,15 Q55,45 50,85" stroke="#2E6B4C" stroke-width="2" fill="none"/><circle cx="32" cy="56" r="7" fill="#E8A838" opacity="0.4"/><circle cx="68" cy="56" r="7" fill="#E8A838" opacity="0.4"/><path d="M30,48 Q35,53 40,48" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M60,48 Q65,53 70,48" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M42,62 Q50,67 58,62" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/><text x="72" y="30" fill="#E8A838" font-family="sans-serif" font-weight="bold" font-size="14">z</text><text x="82" y="20" fill="#E8A838" font-family="sans-serif" font-weight="bold" font-size="11">z</text>',
        'surprised' => '<path d="M50,5 C75,5 92,30 90,60 C88,85 68,95 50,95 C32,95 12,85 10,60 C8,30 25,5 50,5 Z" fill="#3D8B63"/><path d="M50,15 Q55,45 50,85" stroke="#2E6B4C" stroke-width="2" fill="none"/><circle cx="30" cy="55" r="5" fill="#C4724A" opacity="0.4"/><circle cx="70" cy="55" r="5" fill="#C4724A" opacity="0.4"/><circle cx="36" cy="46" r="6" fill="#FFFFFF"/><circle cx="36" cy="46" r="3" fill="#1F3B32"/><circle cx="64" cy="46" r="6" fill="#FFFFFF"/><circle cx="64" cy="46" r="3" fill="#1F3B32"/><circle cx="50" cy="64" r="6" fill="#FFFFFF"/>',
        'waving'    => '<path d="M78,45 Q94,30 88,20 C82,15 74,32 72,40 Z" fill="#3D8B63"/><path d="M50,5 C75,5 92,30 90,60 C88,85 68,95 50,95 C32,95 12,85 10,60 C8,30 25,5 50,5 Z" fill="#3D8B63"/><path d="M50,15 Q55,45 50,85" stroke="#2E6B4C" stroke-width="2" fill="none"/><circle cx="32" cy="56" r="6" fill="#C4724A" opacity="0.4"/><circle cx="68" cy="56" r="6" fill="#C4724A" opacity="0.4"/><circle cx="35" cy="46" r="4" fill="#FFFFFF"/><circle cx="65" cy="46" r="4" fill="#FFFFFF"/><path d="M36,58 Q50,75 64,58 Z" fill="#FFFFFF"/>',
    ];

    if (!isset($poses[$pose])) {
        $pose = 'happy';
    }

    $class = 'empty__art';
    if (isset($attrs['class'])) {
        $class = (string) $attrs['class'];
        unset($attrs['class']);
    }

    $extra = '';
    foreach ($attrs as $key => $value) {
        $extra .= ' ' . $key . '="' . e((string) $value) . '"';
    }

    return '<svg class="' . e($class) . '" viewBox="0 0 100 100"'
        . ' aria-hidden="true" focusable="false"' . $extra . '>'
        . $poses[$pose]
        . '</svg>';
}

/**
 * The Roma monogram/logo mark.
 */
function roomaLogoMark(int $size = 40, string $gradientId = 'roomaLogoGrad'): string
{
    $g = e($gradientId);

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 44 44"'
        . ' fill="none" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="' . $g . '" x1="0" y1="0" x2="44" y2="44">'
        . '<stop stop-color="#3D8B63"/><stop offset=".55" stop-color="#5BA97E"/>'
        . '<stop offset="1" stop-color="#C4724A"/></linearGradient></defs>'
        // Rounded "leaf + face" mark
        . '<rect x="1.5" y="1.5" width="41" height="41" rx="13" stroke="url(#' . $g . ')" stroke-width="2.4"/>'
        . '<path d="M22 9.5c-3.9 0-7 2.2-7 5.6 0 2.1 1.1 3.6 2.9 4.6-.8.5-1.8 1.6-2.3 2.7-.5 1.2-.3 2.3.4 3.1.8.8 1.9.8 2.8.5.9-.3 1.5-1.2 1.8-1.8h1.1c.3.6.9 1.5 1.8 1.8.9.3 2 .3 2.8-.5.7-.8.9-1.9.4-3.1-.5-1.1-1.5-2.2-2.3-2.7 1.8-1 2.9-2.5 2.9-4.6 0-3.4-3.1-5.6-7-5.6z" fill="url(#' . $g . ')"/>'
        . '<circle cx="18.6" cy="15.4" r="1.5" fill="#fff"/>'
        . '<circle cx="25.4" cy="15.4" r="1.5" fill="#fff"/>'
        . '<path d="M18.6 19.6s1.4 1.9 3.4 1.9 3.4-1.9 3.4-1.9" stroke="#fff" stroke-width="1.2" stroke-linecap="round" fill="none"/>'
        // little sprout
        . '<path d="M22 8.4c0-1.5.9-2.6 2.3-3-.2 1.6-1 2.6-2.3 3z" fill="#5BA97E"/>'
        . '</svg>';
}

/**
 * Build the public navigation model once so the header, drawer and bottom
 * bar can never drift out of sync.
 *
 * @return list<array{key:string,label:string,href:string,icon:string,active:bool}>
 */
function roomaPublicNav(string $currentPage, string $currentSlug): array
{
    $items = [
        ['key' => 'home',    'label' => 'خانه',      'href' => 'index.php',              'icon' => 'home', 'short' => 'خانه'],
        ['key' => 'about',   'label' => 'درباره روما', 'href' => 'page.php?slug=about',   'icon' => 'info', 'short' => 'درباره'],
        ['key' => 'classes', 'label' => 'کلاس‌ها',    'href' => 'page.php?slug=classes',  'icon' => 'cap',  'short' => 'کلاس‌ها'],
        ['key' => 'news',    'label' => 'اخبار',      'href' => 'news.php',               'icon' => 'news', 'short' => 'اخبار'],
        ['key' => 'contact', 'label' => 'تماس با ما',  'href' => 'page.php?slug=contact', 'icon' => 'phone', 'short' => 'تماس'],
    ];

    foreach ($items as &$item) {
        $item['active'] = match ($item['key']) {
            'home'    => $currentPage === 'index.php',
            'news'    => $currentPage === 'news.php',
            'about'   => $currentPage === 'page.php' && $currentSlug === 'about',
            'classes' => $currentPage === 'page.php' && $currentSlug === 'classes',
            'contact' => $currentPage === 'page.php' && $currentSlug === 'contact',
            default   => false,
        };
    }
    unset($item);

    return $items;
}

/**
 * Aliases for backwards compatibility during identity transition.
 */
function romaIconPaths(): array { return roomaIconPaths(); }
function romaBrandIconPaths(): array { return roomaBrandIconPaths(); }
function romaLogoMark(int $size = 40, string $gradientId = 'romaLogoGrad'): string { return roomaLogoMark($size, $gradientId); }
function romaPublicNav(string $currentPage, string $currentSlug): array { return roomaPublicNav($currentPage, $currentSlug); }

/**
 * Normalise a phone number for a tel: href.
 */
function telHref(string $phone): string
{
    // Persian digits → Latin so the dialler receives something usable
    $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
    $latin = strtr($phone, $map);

    return (string) preg_replace('/[^0-9+]/', '', $latin);
}

/**
 * Normalise a number for a wa.me link.
 */
function whatsappHref(string $number): string
{
    $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
    $latin = strtr($number, $map);

    return (string) preg_replace('/[^0-9]/', '', $latin);
}

/**
 * Persian label for a news category slug.
 */
function newsCategoryLabel(?string $category): string
{
    return match ($category) {
        'events'        => 'رویدادها و جشن‌ها',
        'announcements' => 'اطلاعیه‌ها',
        'education'     => 'آموزشی و تربیتی',
        'health'        => 'سلامت و تغذیه',
        default         => 'اخبار روما',
    };
}

/**
 * Badge modifier class for a news category.
 */
function newsCategoryBadge(?string $category): string
{
    return match ($category) {
        'events'        => 'badge-secondary',
        'announcements' => 'badge-primary',
        'education'     => 'badge-info',
        'health'        => 'badge-accent',
        default         => 'badge-outline',
    };
}
