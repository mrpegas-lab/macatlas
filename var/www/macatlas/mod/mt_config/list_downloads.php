<?php
include_once("../../loadinc.php");

if (!$can_view) {
    http_response_code(403);
    exit('Нет доступа');
}

$root = rtrim($config['FILES']['DOWNLOAD_DIR'] ?? '', '/');
$url  = rtrim($config['FILES']['URL_PREFIX']  ?? '', '/');

if (!$root || !is_dir($root)) {
    http_response_code(500);
    exit('Директория не найдена');
}

$items = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = substr($f->getPathname(), strlen($root) + 1);
    $items[] = [
        'rel'  => $rel,
        'url'  => $url . '/' . $rel,
        'time' => date('d.m.Y H:i', $f->getMTime()),
    ];
}

usort($items, fn($a, $b) => strcmp($a['rel'], $b['rel']));

header('Content-Type: application/json');
echo json_encode($items);