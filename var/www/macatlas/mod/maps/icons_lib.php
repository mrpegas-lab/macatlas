<?php
// Общие функции иконок карты сети.
// Используются в dataAED.php (загрузка/сброс) и nodeicon.php (картинка узла).

// Встроенная пиктограмма типа (копия glyphs из gen_map_icons.py)
function maps_default_glyph($role, $c) {
    $g = [
        'router' => '<path d="M16 34 V18 M11 23 L16 16 L21 23" stroke="%s" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                  . '<path d="M32 14 V30 M27 25 L32 32 L37 25" stroke="%s" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'switch' => '<path d="M11 18 H34 M28 13 L35 18 L28 23" stroke="%s" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                  . '<path d="M37 30 H14 M20 25 L13 30 L20 35" stroke="%s" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'ap'     => '<circle cx="24" cy="33" r="2.8" fill="%s"/>'
                  . '<path d="M18.5 27 a7.8 7.8 0 0 1 11 0" stroke="%s" stroke-width="2.8" fill="none" stroke-linecap="round"/>'
                  . '<path d="M14 21.5 a14 14 0 0 1 20 0" stroke="%s" stroke-width="2.8" fill="none" stroke-linecap="round"/>'
                  . '<path d="M9.5 16 a20.5 20.5 0 0 1 29 0" stroke="%s" stroke-width="2.8" fill="none" stroke-linecap="round"/>',
        'modem'  => '<path d="M15 33 a6.5 6.5 0 0 1-.5-13 A9 9 0 0 1 31.5 17 a7 7 0 0 1 3.5 16 z" stroke="%s" stroke-width="2.8" fill="none" stroke-linejoin="round"/>',
        'server' => '<rect x="13" y="13" width="22" height="9.5" rx="1.5" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<rect x="13" y="25.5" width="22" height="9.5" rx="1.5" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<circle cx="18.5" cy="17.8" r="1.6" fill="%s"/><circle cx="18.5" cy="30.3" r="1.6" fill="%s"/>'
                  . '<path d="M29 17.8 h3.5 M29 30.3 h3.5" stroke="%s" stroke-width="2.2" stroke-linecap="round"/>',
        'pc'     => '<rect x="12" y="13" width="24" height="16" rx="2" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<path d="M24 29 v5 M19 34.5 h10" stroke="%s" stroke-width="2.8" stroke-linecap="round"/>',
        'printer' => '<path d="M16 20 v-6.5 h16 v6.5" stroke="%s" stroke-width="2.8" fill="none" stroke-linejoin="round"/>'
                  . '<rect x="11" y="20" width="26" height="11" rx="2" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<path d="M16 26.5 v9.5 h16 v-9.5" stroke="%s" stroke-width="2.8" fill="none" stroke-linejoin="round"/>'
                  . '<path d="M19 31.5 h10" stroke="%s" stroke-width="2.2" stroke-linecap="round"/>',
        'camera' => '<rect x="9" y="16" width="23" height="16" rx="2.5" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<path d="M32 21.5 L39.5 17 V31 L32 26.5 Z" stroke="%s" stroke-width="2.8" fill="none" stroke-linejoin="round"/>'
                  . '<circle cx="20.5" cy="24" r="3.2" stroke="%s" stroke-width="2.4" fill="none"/>',
        'intercom' => '<rect x="15" y="9" width="18" height="30" rx="2" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<circle cx="24" cy="17" r="3.5" stroke="%s" stroke-width="2.4" fill="none"/>'
                  . '<path d="M19.5 26 h9 M19.5 30 h9" stroke="%s" stroke-width="2.2" stroke-linecap="round"/>'
                  . '<circle cx="24" cy="35" r="1.4" fill="%s"/>',
        'phone'  => '<path d="M13.5 14.5 V33.5" stroke="%s" stroke-width="4.6" stroke-linecap="round" fill="none"/>'
                  . '<rect x="18" y="13" width="19" height="22" rx="2" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<path d="M22 17.5 h11" stroke="%s" stroke-width="2.2" stroke-linecap="round"/>'
                  . '<path d="M22 24 h2.5 M27.5 24 h2.5 M32.5 24 h2.5 M22 28.5 h2.5 M27.5 28.5 h2.5 M32.5 28.5 h2.5" stroke="%s" stroke-width="2.2" stroke-linecap="round"/>',
        // виртуальные объекты (узлы без железа, не мониторятся): пунктирный контур
        'cloud'    => '<path d="M15 33 a6.5 6.5 0 0 1-.5-13 A9 9 0 0 1 31.5 17 a7 7 0 0 1 3.5 16 z" stroke="%s" stroke-width="2.8" fill="none" stroke-linejoin="round" stroke-dasharray="4 3"/>',
        'umswitch' => '<rect x="9" y="17" width="30" height="14" rx="2" stroke="%s" stroke-width="2.8" fill="none" stroke-dasharray="4 3"/>'
                    . '<path d="M14.5 24 H33.5 M30.5 21 L34 24 L30.5 27 M17.5 21 L14 24 L17.5 27" stroke="%s" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'other'  => '<circle cx="24" cy="24" r="10.5" stroke="%s" stroke-width="2.8" fill="none"/>'
                  . '<text x="24" y="29.5" font-family="Segoe UI, Arial" font-size="15" font-weight="bold" text-anchor="middle" fill="%s">?</text>',
    ];
    if (!isset($g[$role])) $role = 'other';
    return vsprintf($g[$role], array_fill(0, substr_count($g[$role], '%s'), $c));
}

// Встраиваем файл картинки как data URI.
// Растровые (PNG/JPG) крупнее $max px ужимаем ОДИН РАЗ, результат кладём
// в подпапку .cache/ рядом с исходником; пересжимаем только если исходник
// изменился. Исходники меньше $max встраиваются как есть.
// Требуется расширение GD; если его нет — встраиваем файл без ужатия.
function maps_icon_embed_file($f, $mime, $max = 96) {
    if (in_array($mime, ['image/png', 'image/jpeg'], true) && function_exists('imagecreatefromstring')) {
        $max = max(48, min(512, (int)$max));
        $cacheDir = dirname($f) . '/.cache';
        $ext = ($mime === 'image/png') ? 'png' : 'jpg';
        $cache = $cacheDir . '/' . pathinfo($f, PATHINFO_FILENAME) . '.' . $max . '.' . $ext;
        // кэш ещё актуален — берём готовый файл
        if (file_exists($cache) && filemtime($cache) >= filemtime($f)) {
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cache));
        }
        $img = @imagecreatefromstring(file_get_contents($f));
        if ($img) {
            $w = imagesx($img); $h = imagesy($img);
            if (max($w, $h) <= $max) {
                // исходник и так компактный — кэш не нужен
                imagedestroy($img);
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($f));
            }
            $k = $max / max($w, $h);
            $nw = max(1, (int)round($w * $k));
            $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($mime === 'image/png') {   // сохраняем прозрачность
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            if ($mime === 'image/png') imagepng($dst, $cache, 9);
            else imagejpeg($dst, $cache, 85);
            imagedestroy($dst);
            imagedestroy($img);
            if (file_exists($cache)) {
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cache));
            }
        }
    }
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($f));
}

// Пиктограмма: конкретный файл из custom/ (если задан и существует),
// иначе встроенная glyph по типу узла.
// Возвращает SVG-разметку для вставки во внутренний <svg ... viewBox="0 0 48 48">.
// $maxImg — до какого размера сжимать растровые иконки (настройка карты).
function maps_icon_inner($role, $iconColor, $iconFile = null, $maxImg = 96) {
    if ($iconFile) {
        $base = __DIR__ . '/../../plugins/maps/icons/custom/';
        $iconFile = basename($iconFile);
        $ext = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION));
        $mimes = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        if (isset($mimes[$ext])) {
            $f = $base . $iconFile;
            if (file_exists($f)) {
                // meet: картинка вписывается в квадрат 48x48 без искажений
                return '<image x="0" y="0" width="48" height="48" preserveAspectRatio="xMidYMid meet" href="'
                     . maps_icon_embed_file($f, $mimes[$ext], $maxImg) . '"/>';
            }
        }
    }
    return maps_default_glyph($role, $iconColor);
}

// Список файлов подложки карты (папка backgrounds/, наполняется вручную)
function maps_background_files() {
    $dir = __DIR__ . '/../../plugins/maps/backgrounds/';
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg'], true)) $out[] = $f;
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    }
    return $out;
}

// Список файлов иконок, доступных для назначения узлам (папка custom/, наполняется вручную)
function maps_icon_files() {
    $dir = __DIR__ . '/../../plugins/maps/icons/custom/';
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['svg', 'png', 'jpg', 'jpeg'], true)) $out[] = $f;
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    }
    return $out;
}

