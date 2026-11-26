<?php
// Настройки системы — универсальный редактор таблицы settings.
// Каждая настройка описана в реестре registry.php: тип контрола, подпись, подсказка.
// Все сохранения — AJAX через dataAED.php (без перезагрузки страницы).
include_once("../../loadinc.php");
include_once("registry.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к настройкам</div>');
}

// ---------- Задачи планировщика (для отрисовки) ----------
$JOBS_OK = false;
$chk = $mysqli->query("SHOW TABLES LIKE 'collector_jobs'");
if ($chk && $chk->num_rows) $JOBS_OK = true;

// ---------- Чтение текущих значений ----------
$vals = [];
$res = $mysqli->query("SELECT `key`, `value` FROM settings");
if ($res) {
    while ($r = $res->fetch_assoc()) $vals[$r['key']] = $r['value'];
}
$val = function ($k, $def = '') use ($vals, $REG) {
    return $vals[$k] ?? ($REG[$k]['default'] ?? $def);
};

// список ролей для select_roles
$roles = [];
$res = $mysqli->query("SELECT id, name FROM acl_roles ORDER BY name");
if ($res) {
    while ($r = $res->fetch_assoc()) $roles[] = $r;
}

$jobs = [];
if ($JOBS_OK) {
    $res = $mysqli->query("SELECT * FROM collector_jobs ORDER BY job_key");
    if ($res) {
        while ($r = $res->fetch_assoc()) $jobs[] = $r;
    }
    // флаги включения одним запросом
    $flags = [];
    $res = $mysqli->query("SELECT name, value FROM app_settings WHERE name LIKE 'job_enabled_%' OR name = 'monitoring_enabled'");
    if ($res) {
        while ($r = $res->fetch_assoc()) $flags[$r['name']] = $r['value'];
    }
    // последний ручной запуск каждой задачи
    $lastRun = [];
    $res = $mysqli->query("SELECT r1.* FROM job_run_requests r1
                           JOIN (SELECT job_key, MAX(id) mid FROM job_run_requests GROUP BY job_key) r2
                             ON r1.id = r2.mid");
    if ($res) {
        while ($r = $res->fetch_assoc()) $lastRun[$r['job_key']] = $r;
    }
    foreach ($jobs as &$j) {
        $fk = $j['flag_key'] !== '' ? $j['flag_key'] : 'job_enabled_' . $j['job_key'];
        $j['_flag'] = $fk;
        $j['_enabled'] = !isset($flags[$fk]) || trim((string)$flags[$fk]) !== '0';
        $j['_last'] = $lastRun[$j['job_key']] ?? null;
    }
    unset($j);
}
?>

<div class="container-fluid py-3">
    <style>
        /* masonry: карточки заполняют колонки без «ступенек» — высота не
           выравнивается по самой высокой карточке в ряду (2026-11-25) */
        .set-masonry { columns: 2 430px; column-gap: 1rem; }
        .set-mas-item { break-inside: avoid; -webkit-column-break-inside: avoid; }
    </style>

    <div class="panel mb-2" style="padding: 8px 12px;">
        <h5 class="mb-0 fw-bold">⚙️ Настройки системы</h5>
    </div>

    <div id="settingsMsg" class="mb-2"></div>

    <div class="set-masonry">
    <?php foreach ($SECTIONS as $sec): ?>
        <div class="set-mas-item">
        <div class="panel p-4 mb-3">
            <div class="fw-semibold mb-3 border-bottom pb-1">
                <i class="bi <?= htmlspecialchars($sec['icon']) ?>"></i> <?= htmlspecialchars($sec['title']) ?>
            </div>

            <div class="row">
            <?php foreach ($sec['keys'] as $k => $def): $v = $val($k); ?>
                <?php
                // half => поле в половину ширины карточки (компактные секции вроде LDAP)
                $colClass = !empty($def['half']) ? 'col-md-6' : 'col-12';
                ?>
                <div class="<?= $colClass ?>">
                <?php if ($def['type'] === 'bool'): ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input set-key" type="checkbox" data-key="<?= $k ?>"
                               id="<?= $k ?>" value="1" <?= ((int)$v === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= $k ?>"><b><?= htmlspecialchars($def['label']) ?></b></label>
                        <?php if (!empty($def['hint'])): ?>
                            <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($def['type'] === 'select_roles'): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($def['label']) ?></label>
                        <select class="form-select form-select-sm set-key" data-key="<?= $k ?>">
                            <option value="0">— не назначать (вход запрещён до выдачи роли)</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$v === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['name']) ?> (ID <?= (int)$r['id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($def['hint'])): ?>
                            <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($def['type'] === 'choice'): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($def['label']) ?></label>
                        <select class="form-select form-select-sm set-key" data-key="<?= $k ?>">
                            <?php foreach (($def['options'] ?? []) as $ov => $olabel): ?>
                                <option value="<?= htmlspecialchars($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($olabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($def['hint'])): ?>
                            <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($def['type'] === 'password'): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($def['label']) ?></label>
                        <input type="password" class="form-control form-control-sm set-key" data-key="<?= $k ?>"
                               value="" autocomplete="new-password"
                               placeholder="<?= $v !== '' ? '•••••••• (сохранён)' : '' ?>">
                        <?php if (!empty($def['hint'])): ?>
                            <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($def['type'] === 'number'): ?>
                    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                        <label class="form-label mb-0" style="min-width: 240px;"><?= htmlspecialchars($def['label']) ?></label>
                        <input type="number" class="form-control form-control-sm set-key" data-key="<?= $k ?>" style="width: 110px;"
                               value="<?= !empty($def['float']) ? htmlspecialchars((string)$v) : (int)$v ?>"
                               <?= isset($def['min']) ? 'min="' . (!empty($def['float']) ? $def['min'] : (int)$def['min']) . '"' : '' ?>
                               <?= isset($def['max']) ? 'max="' . (!empty($def['float']) ? $def['max'] : (int)$def['max']) . '"' : '' ?>
                               <?= !empty($def['step']) ? 'step="' . $def['step'] . '"' : '' ?>>
                        <?php if (!empty($def['hint'])): ?>
                            <span class="form-text mt-0"><?= htmlspecialchars($def['hint']) ?></span>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($def['label']) ?></label>
                        <input type="text" class="form-control form-control-sm set-key" data-key="<?= $k ?>"
                               value="<?= htmlspecialchars($v) ?>"
                               <?= !empty($def['placeholder']) ? 'placeholder="' . htmlspecialchars($def['placeholder']) . '"' : '' ?>>
                        <?php if (!empty($def['hint'])): ?>
                            <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div><?php // col ?>
            <?php endforeach; ?>
            </div><?php // row ?>
        </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- ================= Задачи планировщика ================= -->
    <?php if ($JOBS_OK && $jobs): ?>
    <div class="panel p-4 mt-3">
        <div class="fw-semibold mb-1 border-bottom pb-1">
            <i class="bi bi-clock-history"></i> Задачи планировщика
        </div>
        <div class="small text-muted mb-3">
            Вкл/выкл, расписание (формат cron: «мин час день месяц день_недели») и ручной запуск.
            Изменения попадают в crontab в течение минуты. Ручной запуск работает, даже если задача выключена.
        </div>

        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Задача</th>
                    <th style="width: 340px;">Расписание (cron)</th>
                    <th style="width: 90px;">Включена</th>
                    <th>Последний ручной запуск</th>
                    <th style="width: 130px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $j): $lr = $j['_last']; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($j['name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($j['description']) ?></div>
                        <div class="small text-muted font-monospace"><?= htmlspecialchars($j['command']) ?></div>
                    </td>
                    <td>
                        <?php if ($can_edit): ?>
                        <div class="d-flex gap-1 align-items-center">
                            <select class="form-select form-select-sm job-preset" style="width: 150px;">
                                <option value="">Шаблон…</option>
                                <option value="*/15 * * * *">каждые 15 мин</option>
                                <option value="*/30 * * * *">каждые 30 мин</option>
                                <option value="0 * * * *">каждый час</option>
                                <option value="0 1 * * *">ежедневно 01:00</option>
                                <option value="0 1 * * 1-5">будни 01:00</option>
                            </select>
                            <input type="text" class="form-control form-control-sm font-monospace job-cron"
                                   data-job="<?= htmlspecialchars($j['job_key']) ?>"
                                   value="<?= htmlspecialchars($j['cron'] ?? '') ?>" style="width: 140px;"
                                   placeholder="* * * * *">
                            <button type="button" class="btn btn-outline-primary btn-sm job-schedule"
                                    data-job="<?= htmlspecialchars($j['job_key']) ?>" title="Применить расписание">✓</button>
                        </div>
                        <div class="small text-muted mt-1"><?= htmlspecialchars($j['schedule']) ?></div>
                        <?php else: ?>
                            <span class="small font-monospace"><?= htmlspecialchars($j['cron'] ?? '') ?></span>
                            <div class="small text-muted"><?= htmlspecialchars($j['schedule']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($can_edit): ?>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input job-toggle" type="checkbox"
                                   data-job="<?= htmlspecialchars($j['job_key']) ?>"
                                   <?= $j['_enabled'] ? 'checked' : '' ?>>
                        </div>
                        <?php else: ?>
                            <span class="badge <?= $j['_enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $j['_enabled'] ? 'да' : 'нет' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="small job-statuscell"
                        data-job="<?= htmlspecialchars($j['job_key']) ?>"
                        <?= ($lr && in_array($lr['status'], ['pending', 'running'], true)) ? 'data-live="1"' : '' ?>>
                        <?php if (!$lr): ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($lr['status'] === 'pending'): ?>
                            <span class="badge bg-warning text-dark">в очереди</span>
                        <?php elseif ($lr['status'] === 'running'): ?>
                            <span class="badge bg-info text-dark">
                                <?php if (!empty($lr['progress_total'])): ?>
                                    выполняется: <?= (int)$lr['progress_done'] ?>/<?= (int)$lr['progress_total'] ?>
                                    (<?= round($lr['progress_done'] / $lr['progress_total'] * 100) ?>%)
                                <?php else: ?>
                                    выполняется…
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($lr['progress_current'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars($lr['progress_current']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge <?= $lr['status'] === 'done' ? 'bg-success' : 'bg-danger' ?>">
                                <?= $lr['status'] === 'done' ? 'успех' : 'ошибка' ?>
                                <?= $lr['exit_code'] !== null ? '(код ' . (int)$lr['exit_code'] . ')' : '' ?>
                            </span>
                            <div class="text-muted"><?= htmlspecialchars((string)$lr['finished_at']) ?>,
                                <?= htmlspecialchars($lr['requested_by']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($can_edit || $can_exec): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm job-run"
                                data-job="<?= htmlspecialchars($j['job_key']) ?>"
                                <?= ($lr && in_array($lr['status'], ['pending', 'running'], true)) ? 'disabled' : '' ?>>
                            ▶ Запустить
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var AED = '/mod/settings/dataAED.php';
    var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;

    function showMsg(text, ok) {
        var el = document.getElementById('settingsMsg');
        el.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') +
                       ' py-2 mb-0"></div>';
        el.firstChild.textContent = text;
        clearTimeout(showMsg._t);
        showMsg._t = setTimeout(function () { el.innerHTML = ''; }, 5000);
        window.scrollTo(0, 0);
    }

    // opts.silent — не показывать всплывающее сообщение (ручной запуск задач:
    // там вся обратная связь живёт в ячейке статуса, алерт только мешает)
    function post(params, onOk, opts) {
        opts = opts || {};
        fetch(AED, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        })
        .then(function (r) {
            return r.text().then(function (t) { return { ok: r.ok, text: t }; });
        })
        .then(function (res) {
            var text = (res.text || '').trim();
            if (!opts.silent || !res.ok) showMsg(text || 'Готово', res.ok);
            if (res.ok && typeof onOk === 'function') onOk();
        })
        .catch(function (e) { showMsg('Ошибка сети: ' + e, false); });
    }

    // Автосохранение настроек: каждое поле сохраняется сразу при изменении,
    // кнопки «Сохранить» нет (2026-11-25). change у текстовых полей срабатывает
    // на blur/Enter — запрос не летит на каждое нажатие клавиши.
    function markSaved(el, ok, errText) {
        var tag = el.parentElement.querySelector('.set-saved-tag');
        if (!tag) {
            tag = document.createElement('span');
            el.parentElement.appendChild(tag);
        }
        tag.className = 'set-saved-tag small ms-2 ' + (ok ? 'text-success' : 'text-danger');
        tag.textContent = ok ? '✓ сохранено' : ('✗ ' + (errText || 'ошибка сохранения'));
        clearTimeout(tag._t);
        if (ok) tag._t = setTimeout(function () { tag.remove(); }, 2500);
    }

    if (CAN_EDIT) {
        document.querySelectorAll('.set-key').forEach(function (el) {
            el.addEventListener('change', function () {
                // пустой пароль = «не менять» — просто не отправляем
                if (el.type === 'password' && el.value === '') return;
                var params = { oper: 'save_key', key: el.getAttribute('data-key') };
                if (el.type === 'checkbox') {
                    if (el.checked) params.value = '1';
                } else {
                    params.value = el.value;
                }
                el.disabled = true;
                fetch(AED, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(params).toString()
                })
                .then(function (r) {
                    return r.text().then(function (t) { return { ok: r.ok, text: t }; });
                })
                .then(function (res) {
                    el.disabled = false;
                    markSaved(el, res.ok, (res.text || '').trim());
                })
                .catch(function (e) {
                    el.disabled = false;
                    markSaved(el, false, 'сеть: ' + e);
                });
            });
        });
    } else {
        // просмотр без прав редактирования — поля только для чтения
        document.querySelectorAll('.set-key').forEach(function (el) { el.disabled = true; });
    }

    // Вкл/выкл задачи
    document.querySelectorAll('.job-toggle').forEach(function (el) {
        el.addEventListener('change', function () {
            post({ oper: 'job_toggle', job_key: el.getAttribute('data-job'),
                   enabled: el.checked ? '1' : '' });
        });
    });

    // Пресет подставляет cron в поле
    document.querySelectorAll('.job-preset').forEach(function (el) {
        el.addEventListener('change', function () {
            var inp = el.parentElement.querySelector('.job-cron');
            if (inp && el.value) inp.value = el.value;
            el.value = '';
        });
    });

    // Применить расписание
    document.querySelectorAll('.job-schedule').forEach(function (el) {
        el.addEventListener('click', function () {
            var job = el.getAttribute('data-job');
            var inp = document.querySelector('.job-cron[data-job="' + job + '"]');
            post({ oper: 'job_schedule', job_key: job, cron: inp ? inp.value : '' });
        });
    });

    // ---------- Живой статус задач: поллер обновляет КОЛОНКУ статуса ----------
    // Работает и после F5: при загрузке страницы поллер стартует для всех
    // ячеек с data-live="1" (pending/running по данным сервера).

    function statusCell(job) {
        return document.querySelector('.job-statuscell[data-job="' + job + '"]');
    }

    // перерисовать содержимое ячейки статуса по данным job_status
    function renderStatus(job, st) {
        var cell = statusCell(job);
        if (!cell) return;
        cell.innerHTML = '';
        var badge = document.createElement('span');
        if (st.status === 'pending') {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = 'в очереди';
            cell.appendChild(badge);
            return;
        }
        // running
        badge.className = 'badge bg-info text-dark';
        badge.textContent = st.progress_total
            ? 'выполняется: ' + st.progress_done + '/' + st.progress_total +
              ' (' + Math.round(st.progress_done / st.progress_total * 100) + '%)'
            : 'выполняется…';
        cell.appendChild(badge);

        var hung = st.progress_age_sec !== null && st.progress_age_sec > 180;
        if (st.progress_current || hung) {
            var info = document.createElement('div');
            info.className = 'small ' + (hung ? 'text-warning' : 'text-muted');
            var s = st.progress_current || '';
            if (hung) {
                s += (s ? ' · ' : '') + '⚠ нет отклика ' +
                     Math.floor(st.progress_age_sec / 60) + ' мин — возможно, завис';
            }
            info.textContent = s;
            cell.appendChild(info);
        }
    }

    function jobButton(job) {
        return document.querySelector('.job-run[data-job="' + job + '"]');
    }

    // финальный статус (успех/ошибка) — прямо в ячейке, без перезагрузки страницы
    function renderFinal(job, st) {
        var cell = statusCell(job);
        if (!cell) return;
        cell.innerHTML = '';
        var ok = st.status === 'done';
        var badge = document.createElement('span');
        badge.className = 'badge ' + (ok ? 'bg-success' : 'bg-danger');
        badge.textContent = (ok ? 'успех' : 'ошибка') +
            (st.exit_code !== null && st.exit_code !== undefined ? ' (код ' + st.exit_code + ')' : '');
        cell.appendChild(badge);
        if (st.finished_at) {
            var d = document.createElement('div');
            d.className = 'text-muted';
            d.textContent = st.finished_at;
            cell.appendChild(d);
        }
        cell.removeAttribute('data-live');
    }

    function pollJob(job) {
        if (pollJob._timers[job]) return;   // уже опрашиваем
        pollJob._timers[job] = setInterval(function () {
            fetch(AED, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ oper: 'job_status', job_key: job }).toString()
            })
            .then(function (r) {
                return r.text().then(function (t) {
                    try { return JSON.parse(t); }
                    catch (e) {
                        // ответ не JSON (редирект на логин, PHP-ворнинг) —
                        // показываем в консоли, иначе поллер молча умирает
                        console.warn('job_status: не-JSON ответ:', t.slice(0, 300));
                        throw e;
                    }
                });
            })
            .then(function (st) {
                if (st.status === 'pending' || st.status === 'running') {
                    renderStatus(job, st);
                    return;
                }
                // done / failed — финал: рисуем статус в ячейке, страницу НЕ перезагружаем
                clearInterval(pollJob._timers[job]);
                delete pollJob._timers[job];
                var btn = jobButton(job);
                if (btn) btn.disabled = false;
                renderFinal(job, st);
            })
            .catch(function (e) { console.warn('pollJob:', e); /* ждём следующий тик */ });
        }, 5000);
    }
    pollJob._timers = {};

    // Ручной запуск
    document.querySelectorAll('.job-run').forEach(function (el) {
        el.addEventListener('click', function () {
            var job = el.getAttribute('data-job');
            el.disabled = true;
            renderStatus(job, { status: 'pending' });
            post({ oper: 'job_run', job_key: job }, function () {
                pollJob(job);
            }, { silent: true });
        });
    });

    // Автостарт поллера для задач, активных на момент загрузки страницы
    // (после F5 прогресс продолжает обновляться сам)
    document.querySelectorAll('.job-statuscell[data-live="1"]').forEach(function (cell) {
        pollJob(cell.getAttribute('data-job'));
    });
})();
</script>
