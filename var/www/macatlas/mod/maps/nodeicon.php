<?php
// Картинка узла карты в стиле The Dude:
// прямоугольник со статусным фоном, внутри — пиктограмма типа и текст (имя + опц. модель/IP).
// Размеры и видимость строк берутся из map_settings текущей карты.
// Вызывается как <img> из vis-network: nodeicon.php?map=1&role=switch&state=up&n=...&s=...&ip=...
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23)
if (!$can_view) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/icons_lib.php';

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: max-age=300');

$ROLES = ['router', 'switch', 'ap', 'modem', 'server', 'pc', 'printer', 'camera', 'intercom', 'phone', 'cloud', 'umswitch', 'other'];
$role  = $_GET['role'] ?? 'other';
if (!in_array($role, $ROLES, true)) $role = 'other';

$state = $_GET['state'] ?? '';           // '' | 'up' | 'down'
if (!in_array($state, ['', 'up', 'down'], true)) $state = '';

$mapId = intval($_GET['map'] ?? 1);

// настройки карты (если таблицы/записи нет — значения по умолчанию)
$font_size = 13; $icon_size = 24; $show_model = 1; $show_ip = 0;
$show_firmware = 0; $show_uptime = 0; $show_temp = 0; $show_comment = 0;
$stmt = $mysqli->prepare("SELECT font_size, icon_size, show_model, show_ip, show_firmware, show_uptime, show_temp, show_comment FROM map_settings WHERE map_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $mapId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $font_size     = (int)$row['font_size'];
        $icon_size     = (int)$row['icon_size'];
        $show_model    = (int)$row['show_model'];
        $show_ip       = (int)$row['show_ip'];
        $show_firmware = (int)$row['show_firmware'];
        $show_uptime   = (int)$row['show_uptime'];
        $show_temp     = (int)$row['show_temp'];
        $show_comment  = (int)($row['show_comment'] ?? 0);
    }
}
$font_size  = max(10, min(20, $font_size));
$icon_size  = max(14, min(48, $icon_size));

// до какого размера сжимать растровые иконки (настройка карты; колонка
// появляется миграцией maps_icon_max.sql — пока её нет, берём 96)
$img_max = 96;
$stmt = $mysqli->prepare("SELECT icon_img_max FROM map_settings WHERE map_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $mapId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $img_max = (int)$row['icon_img_max'];
}
$img_max = max(48, min(512, $img_max));

$name = trim((string)($_GET['n'] ?? ''));
$sub  = trim((string)($_GET['s'] ?? ''));
$ip   = trim((string)($_GET['ip'] ?? ''));
$icon = basename(trim((string)($_GET['icon'] ?? '')));
if ($icon !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $icon)) $icon = '';
if ($name === '') $name = '—';

// дополнительные строки узла по настройкам
$fw = trim((string)($_GET['fw'] ?? ''));
$up = trim((string)($_GET['up'] ?? ''));
$tp = trim((string)($_GET['tp'] ?? ''));
$cm = trim((string)($_GET['cm'] ?? ''));

$lines = [];
if ($show_comment && $cm !== '')  $lines[] = $cm;
if ($show_model && $sub !== '') $lines[] = $sub;
if ($show_ip && $ip !== '')     $lines[] = $ip;
if ($show_firmware && $fw !== '') $lines[] = 'v' . $fw;
if ($show_uptime && $up !== '')   $lines[] = 'up ' . $up;
if ($show_temp && $tp !== '')     $lines[] = $tp . '°C';

// стиль карты (JSON): цвета иконок и прочее
$iconColors = ['neutral' => '#4A4A4A', 'up' => '#0E7A33', 'down' => '#A81F14'];
$stmt = $mysqli->prepare("SELECT style FROM map_settings WHERE map_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $mapId);
    $stmt->execute();
    $styleRow = $stmt->get_result()->fetch_assoc();
    if ($styleRow && $styleRow['style']) {
        $styleJson = json_decode($styleRow['style'], true);
        if (is_array($styleJson) && !empty($styleJson['icons']) && is_array($styleJson['icons'])) {
            foreach ($iconColors as $k => $def) {
                if (!empty($styleJson['icons'][$k]) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $styleJson['icons'][$k])) {
                    $iconColors[$k] = $styleJson['icons'][$k];
                }
            }
        }
    }
}

// фон — градиент как в The Dude: сверху почти белый, книзу яркий цвет;
// рамки нет, иконка тёмная, в тон состоянию (цвет можно поменять в стиле карты)
$iconColor = $iconColors['neutral'];
$styles = [
    ''     => ['#FFFFFF', '#DADADA', 'neutral'],   // нейтральный
    'up'   => ['#F0FAF1', '#20D253', 'up'],        // ping ok
    'down' => ['#FDF0EF', '#F0422F', 'down'],      // не отвечает
];
[$bgTop, $bgBottom, $iconKey] = $styles[$state];
$iconColor = $iconColors[$iconKey];

// геометрия: иконка сверху по центру, строки текста под ней
$pad   = 8;
$gap   = 4;
$fs    = $font_size;                 // шрифт имени
$fs2   = max(9, $fs - 1);            // шрифт доп. строк
// примерная ширина текста (Segoe UI, обычное начертание): ~0.55*размер на символ
$textW = (int)ceil(mb_strlen($name) * $fs * 0.55);
foreach ($lines as $ln) {
    $textW = max($textW, (int)ceil(mb_strlen($ln) * $fs2 * 0.55));
}
$W = max($textW, $icon_size) + 2 * $pad;
$lineH = (int)round($fs * 1.28);
$H = $pad + $icon_size + $gap + $lineH * (1 + count($lines)) + $pad - 6;

$ix = (int)round(($W - $icon_size) / 2);   // x иконки (по центру)
$tx = (int)round($W / 2);                  // x текста (центр, anchor=middle)
$y  = $pad + $icon_size + $gap + $fs - 2;  // baseline первой строки

$esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

// --- Облако: сам УЗЕЛ в форме облака (не плитка с иконкой внутри) ---
// Виртуальный объект (выход в Интернет и т.п.): контур облака с заливкой,
// имя внутри, доп. строки (комментарий) — под облаком.
if ($role === 'cloud') {
    // комментарий облака — многострочный и показывается ВСЕГДА (в этом его смысл),
    // настройка show_comment на облако не влияет
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $cm) as $l) {
        $l = trim($l);
        if ($l !== '') $lines[] = $l;
    }
    $textW = (int)ceil(mb_strlen($name) * $fs * 0.55);
    foreach ($lines as $ln) {
        $textW = max($textW, (int)ceil(mb_strlen($ln) * $fs2 * 0.55));
    }
    $txtH = $lineH * (1 + count($lines));
    $cw = max($textW + 6 * $pad, 120);          // ширина облака (по самой длинной строке)
    $ch = max((int)round($cw * 0.58), $txtH + 4 * $pad);  // высота — чтобы влезали все строки
    $W  = $cw;
    $H  = $ch + 4;
    // глиф облака из viewBox 48x48 — на весь узел. Границы фигуры измерены
    // численно (x:8.25..41.44, y:12..33): «на глаз» обрезало правый пузырь.
    // Плюс запас $m под контур (non-scaling-stroke рисует в экранных px).
    $m   = 3;
    $bx = 8.25; $by = 12.0; $bw = 33.2; $bh = 21.0;
    $sx  = ($cw - 2 * $m) / $bw;
    $sy  = ($ch - 2 * $m) / $bh;
    $txc = $m - $bx * $sx;
    $tyc = $m - $by * $sy;
    // блок текста (имя + доп. строки) центрируем ВНУТРИ облака
    $txtH = $lineH * (1 + count($lines));
    $ty   = (int)round(($ch - $txtH) / 2 + $fs);
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $W ?>" height="<?= $H ?>" viewBox="0 0 <?= $W ?> <?= $H ?>">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="<?= $bgTop ?>"/>
      <stop offset="1" stop-color="<?= $bgBottom ?>"/>
    </linearGradient>
  </defs>
  <g transform="translate(<?= round($txc, 2) ?>,<?= round($tyc, 2) ?>) scale(<?= round($sx, 3) ?>,<?= round($sy, 3) ?>)">
    <path d="M15 33 a6.5 6.5 0 0 1-.5-13 A9 9 0 0 1 31.5 17 a7 7 0 0 1 3.5 16 z"
          fill="url(#bg)" stroke="<?= $iconColor ?>" stroke-width="2.6"
          stroke-dasharray="4 3" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
  </g>
  <text x="<?= (int)round($W / 2) ?>" y="<?= $ty ?>" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif"
        font-size="<?= $fs ?>" fill="#000000"><?= $esc($name) ?></text>
<?php foreach ($lines as $ln): $ty += $lineH; ?>
  <text x="<?= (int)round($W / 2) ?>" y="<?= $ty ?>" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif"
        font-size="<?= $fs2 ?>" fill="#000000" fill-opacity="0.72"><?= $esc($ln) ?></text>
<?php endforeach; ?>
</svg>
<?php
    exit;
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $W ?>" height="<?= $H ?>" viewBox="0 0 <?= $W ?> <?= $H ?>">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="<?= $bgTop ?>"/>
      <stop offset="1" stop-color="<?= $bgBottom ?>"/>
    </linearGradient>
  </defs>
  <rect x="1" y="1" width="<?= $W - 2 ?>" height="<?= $H - 2 ?>" rx="9"
        fill="url(#bg)"/>
  <svg x="<?= $ix ?>" y="<?= $pad ?>" width="<?= $icon_size ?>" height="<?= $icon_size ?>" viewBox="0 0 48 48"><?= maps_icon_inner($role, $iconColor, $icon !== '' ? $icon : null, $img_max) ?></svg>
  <text x="<?= $tx ?>" y="<?= $y ?>" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif"
        font-size="<?= $fs ?>" fill="#000000"><?= $esc($name) ?></text>
<?php foreach ($lines as $ln): $y += $lineH; ?>
  <text x="<?= $tx ?>" y="<?= $y ?>" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif"
        font-size="<?= $fs2 ?>" fill="#000000" fill-opacity="0.72"><?= $esc($ln) ?></text>
<?php endforeach; ?>
</svg>
