<?php
//session_start();
function acl($table, $permission) {
    return $_SESSION['acl'][$table][$permission] ?? 0;
}

$url_parts = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
$name = $_GET['name'] ?? $_POST['name'] ?? '';
$ed = '';

if (isset($url_parts[1])) {
    $table = $url_parts[1];

    // Формируем опции для jqGrid
    $ed_string = [
        'search' => true,
        'refresh' => true,
        'view' => false,
        'add' => (bool)acl($table, 'add'),
        'del' => (bool)acl($table, 'del'),
        'edit' => (bool)acl($table, 'edit')
    ];

    // Преобразуем в строку для jqGrid
    foreach ($ed_string as $key => $value) {
        $ed .= "$key:" . ($value ? 'true' : 'false') . ",";
    }
    $ed = rtrim($ed, ','); // УБИРАЕМ ПОСЛЕДНЮЮ ЗАПЯТУЮ 
    
    // --- Права в виде готовых булевых переменных (для новых модулей) ---
    $can_view = (bool)acl($table, 'view');
    $can_add  = (bool)acl($table, 'add');
    $can_edit = (bool)acl($table, 'edit');
    $can_del  = (bool)acl($table, 'del');
    $can_exec = (bool)acl($table, 'exec');

    // --- Готовый HTML кнопок формы ---
    // Кнопки без прав НЕ скрываем — показываем неактивными (disabled + подсказка),
    // чтобы было видно, что функция есть, но роли недоступна.
    $dis_save = ($can_add || $can_edit) ? '' : 'disabled title="Нет прав на сохранение"';
    $dis_new  = $can_add  ? '' : 'disabled title="Нет прав на добавление"';
    // «Удалить» и так всегда неактивна до выбора записи;
    // без права del остаётся неактивной навсегда
    $dis_del  = $can_del  ? 'disabled' : 'disabled title="Нет прав на удаление"';

    $form_buttons = '<div class="d-flex gap-2 flex-wrap">';

    $form_buttons .= '<button type="submit" class="btn btn-primary" id="btn-save" ' . $dis_save . '>'
                   . '<i class="bi bi-check-lg"></i> Сохранить</button>';

    $form_buttons .= '<button type="button" class="btn btn-outline-secondary" id="btn-new" ' . $dis_new . '>'
                   . '<i class="bi bi-file-earmark-plus"></i> Новый</button>';

    $form_buttons .= '<button type="button" class="btn btn-outline-danger" id="btn-del" ' . $dis_del . '>'
                   . '<i class="bi bi-trash"></i> Удалить</button>';

    $form_buttons .= '</div>';
}

// ============================================================
// Отладка ACL — вызывать вручную в модуле:
//   acl_debug('module: acl_tables');
// ============================================================
function acl_debug($context = '') {
    global $table, $name, $url_parts;
    global $can_view, $can_add, $can_edit, $can_del, $can_exec;

    $data = [
        'context'      => $context,
        'REQUEST_URI'  => $_SERVER['REQUEST_URI'] ?? null,
        'url_parts'    => $url_parts,
        'table'        => $table ?? null,
        'name'         => $name,
        'session_id'   => session_id(),
        'acl_groups_id'=> $_SESSION['acl_groups_id'] ?? null,
        'auth'         => $_SESSION['auth'] ?? null,
        'can' => [
            'view' => $can_view ?? null,
            'add'  => $can_add  ?? null,
            'edit' => $can_edit ?? null,
            'del'  => $can_del  ?? null,
            'exec' => $can_exec ?? null,
        ],
        'acl_for_table'=> $_SESSION['acl'][$table] ?? 'НЕТ КЛЮЧА',
        'all_tables'   => array_keys($_SESSION['acl'] ?? []),
    ];

    echo '<pre style="background:#222;color:#0f0;padding:12px;'
       . 'border:1px solid #0f0;font-size:12px;max-height:400px;overflow:auto;">';
    echo "===== ACL DEBUG ({$context}) =====\n";
    echo htmlspecialchars(print_r($data, true));
    echo '</pre>';
}

//acl_debug();