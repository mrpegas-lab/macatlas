<?php
// Карта сети (vis-network). Узлы — hardware или свободные, линки — map_links.
// Стили модуля — в /plugins/maps.css
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-2 maps-module">
    <div class="maps-layout">

        <!-- === ХОЛСТ КАРТЫ === -->
        <div class="maps-canvas-wrap">
            <div id="maps-canvas"></div>

            <!-- Панель инструментов поверх холста -->
            <div class="maps-toolbar">
                <?php if ($can_edit): ?>
                <button type="button" class="btn btn-sm btn-outline-light" id="maps-btn-addnode" title="Добавить узел">＋ Узел</button>
                <button type="button" class="btn btn-sm btn-outline-light" id="maps-btn-addlink" title="Связать два узла">🔗 Линк</button>
                <button type="button" class="btn btn-sm btn-outline-light" id="maps-btn-addgroup" title="Добавить блок (комната, шкаф)">▭ Блок</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-light" id="maps-btn-fit" title="Показать всю карту (все узлы на экране)">⤢</button>
                <select id="maps-vlan-select" class="form-select form-select-sm d-none"
                        style="width:auto;max-width:220px" title="Подсветить прохождение VLAN по линкам">
                    <option value="">VLAN: все</option>
                </select>
                <span id="maps-status" class="maps-status"></span>
            </div>

            <!-- Контекстное меню узла (ПКМ): открыть веб-интерфейс -->
            <div id="maps-ctxmenu" class="maps-ctxmenu d-none"></div>
        </div>

        <!-- === ПРАВАЯ ПАНЕЛЬ === -->
        <div class="maps-panel panel cmd-panel">

            <!-- Выбор карты -->
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Карта</label>
                <div class="d-flex gap-1">
                    <select id="maps-map-select" class="form-select form-select-sm"></select>
                    <?php if ($can_edit): ?>
                    <button type="button" class="btn btn-sm btn-outline-success px-2" id="maps-btn-addmap" title="Новая карта">＋</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2" id="maps-btn-renmap" title="Переименовать карту">✎</button>
                    <button type="button" class="btn btn-sm btn-outline-danger px-2" id="maps-btn-delmap" title="Удалить карту">🗑</button>
                    <?php endif; ?>
                </div>
                <?php if ($can_edit): ?>
                <div class="d-flex gap-1 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="maps-btn-settings">⚙ Настройки</button>
                </div>
                <div class="form-check form-switch mt-2" title="Глобальный опрос устройств (ping/http/tcp). Если выключить — состояния узлов и линков перестают обновляться">
                    <input class="form-check-input" type="checkbox" id="maps-monitoring">
                    <label class="form-check-label small" for="maps-monitoring">Мониторинг устройств</label>
                </div>
                <div class="form-check form-switch mt-2" title="Запретить перетаскивание узлов, точек излома и блоков (выбор и просмотр остаются)">
                    <input class="form-check-input" type="checkbox" id="maps-lock">
                    <label class="form-check-label small" for="maps-lock">🔒 Блокировка карты</label>
                </div>
                <?php endif; ?>
            </div>

            <!-- Инспектор VLAN на выбранном линке -->
            <div id="maps-link-info" class="d-none mb-3">
                <div class="fw-semibold mb-1">VLAN на линке</div>
                <div id="maps-link-info-body" class="small"></div>
            </div>

            <!-- Добавление узла -->
            <div id="maps-form-node" class="d-none">
                <div class="fw-semibold mb-2">Новый узел</div>
                <div class="mb-2">
                    <label class="form-label small">Устройство из справочника</label>
                    <select id="maps-node-hw" class="form-select form-select-sm">
                        <option value="0">— свободный узел (Интернет и т.п.) —</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Подпись</label>
                    <input type="text" id="maps-node-label" class="form-control form-control-sm" maxlength="200"
                           placeholder="Авто = имя устройства">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Тип</label>
                    <select id="maps-node-role" class="form-select form-select-sm"></select>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="maps-node-save">Добавить</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="mapsHideForms()">Отмена</button>
                </div>
            </div>

            <!-- Редактирование узла -->
            <div id="maps-form-nodeedit" class="d-none">
                <div class="fw-semibold mb-2">Узел</div>
                <div class="mb-2">
                    <label class="form-label small">Подпись</label>
                    <input type="text" id="maps-nodeedit-label" class="form-control form-control-sm" maxlength="200">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Комментарий</label>
                    <textarea id="maps-nodeedit-comment" class="form-control form-control-sm" rows="2" maxlength="255"
                              placeholder="Можно в несколько строк — у облака рисуются внутри него"></textarea>
                </div>
                <div class="mb-2" id="maps-nodeedit-role-wrap">
                    <label class="form-label small">Тип</label>
                    <select id="maps-nodeedit-role" class="form-select form-select-sm"></select>
                </div>
                <div class="mb-2" id="maps-nodeedit-icon-wrap">
                    <label class="form-label small">Иконка</label>
                    <div class="d-flex align-items-center gap-2">
                        <select id="maps-nodeedit-icon" class="form-select form-select-sm">
                            <option value="">— по типу —</option>
                        </select>
                        <img id="maps-nodeedit-icon-preview" src="" alt="" style="width:32px;height:32px;object-fit:contain;display:none;flex:0 0 32px;">
                    </div>
                    <div class="small text-muted mt-1">SVG, PNG или JPG в папке plugins/maps/icons/custom/</div>
                </div>
                <div class="row g-2 mb-2" id="maps-nodeedit-mon-wrap">
                    <div class="col-8">
                        <label class="form-label small">Мониторинг</label>
                        <select id="maps-nodeedit-check" class="form-select form-select-sm">
                            <option value="ping">Ping</option>
                            <option value="http">HTTP</option>
                            <option value="https">HTTPS</option>
                            <option value="tcp">TCP (свой порт)</option>
                            <option value="dns">DNS (порт 53)</option>
                            <option value="ssh">SSH (порт 22)</option>
                            <option value="snmp">SNMP (порт 161)</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Порт</label>
                        <input type="number" id="maps-nodeedit-port" class="form-control form-control-sm"
                               min="1" max="65535" placeholder="8080">
                    </div>
                </div>
                <div class="mb-2" id="maps-nodeedit-hw-wrap">
                    <label class="form-label small">Оборудование</label>
                    <div class="d-flex align-items-center gap-2">
                        <div id="maps-nodeedit-info" class="small text-muted flex-fill"></div>
                        <a id="maps-nodeedit-web" href="#" target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-secondary d-none"
                           title="Открыть веб-интерфейс (http). Выбор http/https — ПКМ по узлу на карте">🌐</a>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="maps-nodeedit-hw"
                                title="Карточка оборудования: создать или привязать устройство">Карточка…</button>
                    </div>
                    <div id="maps-nodeedit-hwwarn" class="small text-warning d-none">не привязано — не мониторится</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="maps-nodeedit-save">Сохранить</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="maps-nodeedit-del">Удалить</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="mapsHideForms()">Отмена</button>
                </div>
            </div>

            <!-- Линк: создание / редактирование -->
            <div id="maps-form-link" class="d-none">
                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-1" id="maps-link-swap"
                        title="Поменять узлы местами: Узел 1 ↔ Узел 2">⇄ Поменять направление</button>
                <div class="fw-semibold mb-2 small text-break" id="maps-link-title">Новый линк</div>
                <div class="row g-2 mb-2" id="maps-link-selects">
                    <div class="col-6">
                        <label class="form-label small">Узел 1</label>
                        <select id="maps-link-src" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Узел 2</label>
                        <select id="maps-link-dst" class="form-select form-select-sm"></select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small" id="maps-link-srcport-label">Порт (узел 1)</label>
                        <select id="maps-link-srcport" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" id="maps-link-dstport-label">Порт (узел 2)</label>
                        <select id="maps-link-dstport" class="form-select form-select-sm"></select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-4">
                        <label class="form-label small">Скорость</label>
                        <select id="maps-link-speed" class="form-select form-select-sm">
                            <option value="">—</option>
                            <option>1G</option><option>10G</option><option>25G</option>
                            <option>40G</option><option>100G</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Цвет</label>
                        <select id="maps-link-color" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Линия</label>
                        <select id="maps-link-line" class="form-select form-select-sm">
                            <option value="smooth">Плавная</option>
                            <option value="elbow">Ортогональная</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2 d-none" id="maps-link-pointwrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="maps-link-addpoint">＋ Точка излома</button>
                    <button type="button" class="btn btn-outline-danger btn-sm w-100 mt-1" id="maps-link-delpoint">－ Удалить выбранную точку</button>
                    <div class="small text-muted mt-1">Точки излома таскаются мышкой, как узлы.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Подпись</label>
                    <input type="text" id="maps-link-label" class="form-control form-control-sm" maxlength="200" placeholder="VLAN, договор...">
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="maps-link-save">Сохранить</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="maps-link-del">Удалить</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="mapsHideForms()">Отмена</button>
                </div>
            </div>

            <!-- Редактирование блока-контейнера -->
            <div id="maps-form-group" class="d-none">
                <div class="fw-semibold mb-2">Блок</div>
                <div class="mb-2">
                    <label class="form-label small">Подпись</label>
                    <input type="text" id="maps-group-label" class="form-control form-control-sm" maxlength="200"
                           placeholder="Кроссовая, шкаф 1...">
                </div>
                <div class="small text-muted mb-2">
                    Перемещение — тащите блок за белую шапку, размер — за правый нижний угол.
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="maps-group-save">Сохранить</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="maps-group-del">Удалить</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="mapsHideForms()">Отмена</button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ================= МОДАЛ: редактор стиля карты ================= -->
<div class="modal fade" id="mapsStyleModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Настройки отображения</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <!-- ================= ЛЕВАЯ КОЛОНКА ================= -->
          <div class="col-md-6">
            <!-- Узлы -->
            <div class="fw-semibold mb-2 border-bottom pb-1">Узлы</div>
            <div class="row g-2 mb-1">
              <div class="col-4">
                <label class="form-label small">Шрифт имени</label>
                <input type="number" id="maps-set-font" class="form-control form-control-sm" min="10" max="20">
              </div>
              <div class="col-4">
                <label class="form-label small">Иконка, px</label>
                <input type="number" id="maps-set-icon" class="form-control form-control-sm" min="14" max="48">
              </div>
              <div class="col-4">
                <label class="form-label small">Сжатие картинок</label>
                <input type="number" id="maps-set-imgmax" class="form-control form-control-sm" min="48" max="512">
              </div>
            </div>
            <div class="small text-muted mb-2">Большие PNG/JPG иконок ужимаются до этого размера (один раз, с кэшем)</div>
            <div class="row g-2">
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-model">
                  <label class="form-check-label small" for="maps-set-model">Строка модели</label>
                </div>
              </div>
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-ip">
                  <label class="form-check-label small" for="maps-set-ip">Строка IP-адреса</label>
                </div>
              </div>
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-firmware">
                  <label class="form-check-label small" for="maps-set-firmware">Строка прошивки</label>
                </div>
              </div>
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-uptime">
                  <label class="form-check-label small" for="maps-set-uptime">Строка uptime</label>
                </div>
              </div>
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-temp">
                  <label class="form-check-label small" for="maps-set-temp">Строка температуры</label>
                </div>
              </div>
              <div class="col-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="maps-set-comment">
                  <label class="form-check-label small" for="maps-set-comment">Строка комментария</label>
                </div>
              </div>
            </div>

            <!-- Блоки -->
            <div class="fw-semibold mb-2 mt-3 border-bottom pb-1">Блоки-комнаты</div>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label small">Шрифт, px</label>
                <input type="number" id="maps-style-blk-font" class="form-control form-control-sm" min="9" max="24">
              </div>
              <div class="col-4">
                <label class="form-label small">Шапка, px</label>
                <input type="number" id="maps-style-blk-head" class="form-control form-control-sm" min="16" max="60">
              </div>
              <div class="col-4">
                <label class="form-label small">Рамка</label>
                <input type="color" id="maps-style-blk-border" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-6">
                <label class="form-label small">Цвет шапки</label>
                <input type="color" id="maps-style-blk-hbg" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-6">
                <label class="form-label small">Цвет текста</label>
                <input type="color" id="maps-style-blk-htext" class="form-control form-control-sm form-control-color w-100">
              </div>
            </div>
          </div>

          <!-- ================= ПРАВАЯ КОЛОНКА ================= -->
          <div class="col-md-6">
            <!-- Выделение -->
            <div class="fw-semibold mb-2 border-bottom pb-1">Выделение объекта</div>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label small">Цвет</label>
                <input type="color" id="maps-style-sel-color" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-4">
                <label class="form-label small">Линия</label>
                <select id="maps-style-sel-dash" class="form-select form-select-sm">
                  <option value="">Сплошная</option>
                  <option value="6,4">Пунктир мелкий</option>
                  <option value="12,6">Пунктир крупный</option>
                  <option value="2,4">Точка-тире</option>
                </select>
              </div>
              <div class="col-4">
                <label class="form-label small">Отступ, px</label>
                <input type="number" id="maps-style-sel-pad" class="form-control form-control-sm" min="0" max="30">
              </div>
            </div>

            <!-- Линки -->
            <div class="fw-semibold mb-2 mt-3 border-bottom pb-1">Линки</div>
            <div class="row g-2">
              <div class="col-3">
                <label class="form-label small">Цвет up</label>
                <input type="color" id="maps-style-lnk-up" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-3">
                <label class="form-label small">Цвет down</label>
                <input type="color" id="maps-style-lnk-down" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-3">
                <label class="form-label small">Шрифт подписи</label>
                <input type="number" id="maps-style-lnk-font" class="form-control form-control-sm" min="6" max="20">
              </div>
              <div class="col-3">
                <label class="form-label small">Точка излома</label>
                <input type="number" id="maps-style-lnk-wp" class="form-control form-control-sm" min="2" max="15">
              </div>
            </div>
            <div class="fw-semibold mb-2 mt-3 border-bottom pb-1">Цвета пиктограмм</div>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label small">Нейтр.</label>
                <input type="color" id="maps-style-ic-neutral" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-4">
                <label class="form-label small">Up</label>
                <input type="color" id="maps-style-ic-up" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-4">
                <label class="form-label small">Down</label>
                <input type="color" id="maps-style-ic-down" class="form-control form-control-sm form-control-color w-100">
              </div>
            </div>

            <!-- Фон и сетка -->
            <div class="fw-semibold mb-2 mt-3 border-bottom pb-1">Фон и сетка</div>
            <div class="row g-2 align-items-end">
              <div class="col-4">
                <label class="form-label small">Цвет фона</label>
                <input type="color" id="maps-style-bg-color" class="form-control form-control-sm form-control-color w-100">
              </div>
              <div class="col-8">
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" id="maps-style-bg-grid">
                  <label class="form-check-label small" for="maps-style-bg-grid">Показывать сетку</label>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label small">Картинка-подложка</label>
                <select id="maps-style-bg-image" class="form-select form-select-sm">
                  <option value="">— нет —</option>
                </select>
                <div class="small text-muted mt-1">Файлы кладутся в plugins/maps/backgrounds/</div>
              </div>
              <div class="col-12">
                <label class="form-label small">Прозрачность подложки: <span id="maps-style-bg-opacity-val">35%</span></label>
                <input type="range" id="maps-style-bg-opacity" class="form-range" min="10" max="100" step="5">
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-success btn-sm" id="maps-set-save">Сохранить</button>
      </div>
    </div>
  </div>
</div>

<style>
/* TomSelect внутри input-group и модалок карты */
.input-group > .ts-wrapper { flex: 1 1 auto; width: 1%; min-width: 0; }
.ts-dropdown { z-index: 1061; }
</style>

<!-- === МОДАЛКА: карточка оборудования узла === -->
<div class="modal fade" id="mapsHwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Карточка оборудования</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="mhw-error" class="alert alert-danger small py-1 px-2 mb-2 d-none"></div>
        <div class="mb-2">
          <label class="form-label small">Устройство</label>
          <select id="mhw-existing" class="form-select form-select-sm">
            <option value="0">— новое устройство —</option>
          </select>
          <div class="form-text small">выберите существующее — поля заполнятся, или заполните для нового</div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Модель *</label>
          <div class="input-group input-group-sm">
            <select id="mhw-model" class="form-select"></select>
            <button type="button" class="btn btn-outline-secondary" id="mhw-model-add" title="Добавить модель в справочник">＋</button>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-7">
            <label class="form-label small">Имя *</label>
            <input type="text" id="mhw-name" class="form-control form-control-sm" maxlength="200">
          </div>
          <div class="col-5">
            <label class="form-label small">IP</label>
            <input type="text" id="mhw-ip" class="form-control form-control-sm" maxlength="45">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Локация</label>
          <div class="input-group input-group-sm">
            <select id="mhw-loc" class="form-select"></select>
            <button type="button" class="btn btn-outline-secondary" id="mhw-loc-add" title="Добавить локацию в справочник">＋</button>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Помещение</label>
          <div class="input-group input-group-sm">
            <select id="mhw-room" class="form-select"></select>
            <button type="button" class="btn btn-outline-secondary" id="mhw-room-add" title="Добавить помещение в выбранную локацию">＋</button>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Креды</label>
          <select id="mhw-cred" class="form-select form-select-sm"></select>
        </div>
        <div class="d-flex gap-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="mhw-poe">
            <label class="form-check-label small" for="mhw-poe">PoE</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="mhw-active" checked>
            <label class="form-check-label small" for="mhw-active">Активно</label>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-success btn-sm" id="mhw-save">Сохранить</button>
      </div>
    </div>
  </div>
</div>

<!-- === МОДАЛКА: быстрое добавление в справочник (модель/локация/помещение) === -->
<div class="modal fade" id="mapsQuickModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="mq-title">Добавить</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="mq-error" class="alert alert-danger small py-1 px-2 mb-2 d-none"></div>
        <div id="mq-model">
          <div class="mb-2">
            <label class="form-label small">Бренд *</label>
            <select id="mq-brand" class="form-select form-select-sm"></select>
          </div>
          <div class="mb-2">
            <label class="form-label small">Тип</label>
            <select id="mq-mtype" class="form-select form-select-sm"></select>
          </div>
          <div class="mb-1">
            <label class="form-label small">Название модели *</label>
            <input type="text" id="mq-model-name" class="form-control form-control-sm" maxlength="128">
          </div>
        </div>
        <div id="mq-location" class="d-none">
          <div class="mb-2">
            <label class="form-label small">Тип локации</label>
            <select id="mq-ltype" class="form-select form-select-sm"></select>
          </div>
          <div class="mb-1">
            <label class="form-label small">Название локации *</label>
            <input type="text" id="mq-loc-name" class="form-control form-control-sm" maxlength="150">
          </div>
          <div class="form-text small">машинное имя (new_name) сгенерируется транслитом автоматически</div>
        </div>
        <div id="mq-room" class="d-none">
          <div class="mb-1">
            <label class="form-label small">Название помещения *</label>
            <input type="text" id="mq-room-name" class="form-control form-control-sm" maxlength="150">
          </div>
          <div class="form-text small">будет добавлено в локацию, выбранную в карточке</div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-success btn-sm" id="mq-save">Добавить</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    // --------------------------------------------------------
    // Типы узлов — иконки в стиле The Dude (SVG, по 3 состояния на тип)
    // --------------------------------------------------------
    var TYPES = {
        router:   { name: 'Маршрутизатор' },
        switch:   { name: 'Коммутатор' },
        ap:       { name: 'Точка доступа' },
        modem:    { name: 'Модем' },
        server:   { name: 'Сервер' },
        pc:       { name: 'Компьютер' },
        printer:  { name: 'Принтер' },
        camera:   { name: 'Видеокамера' },
        intercom: { name: 'Домофон' },
        phone:    { name: 'Телефон' },
        cloud:    { name: 'Облако / Интернет' },
        umswitch: { name: 'Неуправляемый коммутатор' },
        other:    { name: 'Прочее' }
    };
    // model_type_id -> тип узла карты (для узлов с привязанным железом)
    var MTYPE_ROLE = { 1: 'router', 2: 'switch', 3: 'ap' };
    // Эффективный тип узла для иконки:
    // узел с железом — СТРОГО из типа модели (без отката на роль узла:
    // модель без типа -> 'other', т.е. знак вопроса);
    // узел без железа (нарисованный объект) — роль из карточки.
    function effectiveRole(n) {
        if (n.hardware_id) return MTYPE_ROLE[n.mtype] || 'other';
        return TYPES[n.role] ? n.role : 'other';
    }
    var ICON_VER = Date.now();   // бамп для сброса кэша картинок узлов
    var ICON_FILES = [];         // файлы иконок из custom/ (наполняются вручную)
    var SETTINGS = { font_size: 13, icon_size: 24, icon_img_max: 96, show_model: 1, show_ip: 0,
                     show_firmware: 0, show_uptime: 0, show_temp: 0, show_comment: 0 };
    // стиль карты (JSON из map_settings) — умолчания, потом накладываем сохранённое
    var STYLE_DEFAULTS = {
        block:      { font_size: 13, header_h: 24, header_bg: '#FFFFFF', header_text: '#444444', border: '#8a8a8a' },
        selection:  { color: '#0d6efd', dash: '6,4', padding: 6 },
        links:      { up: '#45a049', down: '#c62828', font_size: 10, wp_size: 4 },
        icons:      { neutral: '#4A4A4A', up: '#0E7A33', down: '#A81F14' },
        background: { color: '#B3B3B3', grid: 1, image: '', opacity: 0.35 }
    };
    var STYLE = JSON.parse(JSON.stringify(STYLE_DEFAULTS));
    function resetStyle() { STYLE = JSON.parse(JSON.stringify(STYLE_DEFAULTS)); }
    var BG_IMG = null;   // кэш картинки подложки
    function mergeStyle(s) {
        if (!s) return;
        Object.keys(STYLE).forEach(function (k) {
            if (s[k] && typeof s[k] === 'object') {
                Object.keys(STYLE[k]).forEach(function (f) {
                    // пустая строка тоже валидна для dash — это «сплошная линия»
                    if (s[k][f] !== undefined && (s[k][f] !== '' || (k === 'selection' && f === 'dash'))) {
                        STYLE[k][f] = s[k][f];
                    }
                });
            }
        });
        // старые сохранённые стили: белый означал «без заливки» — меняем на стандартный серый
        var bc = (STYLE.background.color || '').toUpperCase();
        if (bc === '' || bc === '#FFFFFF') STYLE.background.color = STYLE_DEFAULTS.background.color;
    }
    // фон и сетка — через CSS элемента: цвет под сеткой, сетка всегда поверх цвета
    function applyBackground() {
        if (!mapsCanvasEl) return;
        mapsCanvasEl.style.backgroundColor = STYLE.background.color || STYLE_DEFAULTS.background.color;
        mapsCanvasEl.classList.toggle('no-grid', !parseInt(STYLE.background.grid, 10));
    }
    var SPEED_WIDTH = { '1G': 1, '10G': 2, '25G': 3, '40G': 4, '100G': 6 };
    // Авто-цвет линии по скорости (стилистика drawio-схемы)
    var SPEED_COLORS = {
        '1G':   '#1a7a3c',
        '10G':  '#2b4ecb',
        '25G':  '#17a2a8',
        '40G':  '#e07b00',
        '100G': '#e03bb5'
    };
    var LINK_COLORS = {
        '':      { name: 'Серый (по умолчанию)', css: '#6b6b6b' },
        'red':    { name: 'Красный',    css: '#dc3545' },
        'blue':   { name: 'Синий',      css: '#0d6efd' },
        'green':  { name: 'Зелёный',    css: '#198754' },
        'orange': { name: 'Оранжевый',  css: '#fd7e14' },
        'purple': { name: 'Фиолетовый', css: '#6f42c1' }
    };

    var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
    // блокировка карты: узлы/точки/блоки не таскаются, выбор остаётся (хранится в браузере)
    var MAP_LOCK = CAN_EDIT && localStorage.getItem('maps_lock') === '1';
    var AED = '/mod/maps/dataAED.php';
    var MAP_ID = 0;             // текущая карта; 0 = ещё не загружена, -1 = выбрать самую свежую

    var network = null;
    var nodesDS = new vis.DataSet([]);
    var edgesDS = new vis.DataSet([]);
    var rawNodes = {};          // id -> {label, role, ip, sub}
    var rawLinks = {};          // id -> исходные данные линка
    var rawGroups = {};         // id -> блок-контейнер {label, x, y, w, h}
    var wpToLink = {};          // 'wp<ID точки>' -> id линка
    var availHw  = [];          // железо вне карты
    var PORTS    = {};          // hardware_id -> [имена портов из sw_port_info]

    // Заполнить селект портов под узел линка.
    // TomSelect с create:true — список всегда открывается целиком,
    // свой вариант (порт, которого нет в sw_port_info) можно вписать вручную.
    function fillPortSelect(nodeId, selectId, keepVal) {
        var sel = document.getElementById(selectId);
        var cur = keepVal !== undefined ? keepVal : sel.value;
        // ВАЖНО: сначала destroy старого TomSelect, потом правим <option>!
        // destroy() откатывает innerHTML селекта к состоянию на момент создания
        // инстанса — если поменять опции до destroy, они будут затёрты списком
        // от предыдущего открытого линка («застывший» список портов).
        if (TS[selectId]) { TS[selectId].destroy(); delete TS[selectId]; }
        var n = rawNodes[nodeId];
        var list = (n && n.hardware_id && PORTS[n.hardware_id]) || [];
        sel.innerHTML = '<option value=""></option>';
        list.forEach(function (p) {
            var o = document.createElement('option');
            o.value = p; o.textContent = p;
            sel.appendChild(o);
        });
        if (window.TomSelect) {
            TS[selectId] = new TomSelect(sel, { maxOptions: null, create: true, persist: false });
        }
        setPortVal(selectId, cur);
    }

    function setPortVal(selectId, v) {
        v = v || '';
        var sel = document.getElementById(selectId);
        if (v) {
            var found = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === v) { found = true; break; }
            }
            if (!found) {
                var o = document.createElement('option');
                o.value = v; o.textContent = v;
                sel.appendChild(o);
                if (TS[selectId]) TS[selectId].addOption({ value: v, text: v });
            }
        }
        if (TS[selectId]) TS[selectId].setValue(v, true);
        else sel.value = v;
    }

    // --------------------------------------------------------
    // Хелперы
    // --------------------------------------------------------
    function setStatus(text, cls) {
        var el = document.getElementById('maps-status');
        el.textContent = text || '';
        el.className = 'maps-status ' + (cls || '');
        if (text && cls === 'ok') {
            setTimeout(function () { el.textContent = ''; }, 3000);
        }
    }

    function post(params, onOk) {
        // все действия привязываем к текущей карте
        if (params.map_id === undefined) params.map_id = MAP_ID > 0 ? MAP_ID : 1;
        fetch(AED, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        })
        .then(function (r) { return r.text(); })
        .then(function (text) {
            text = (text || '').trim();
            if (text !== '') {
                setStatus('⚠️ ' + text.replace(/^Ошибка:\s*/, ''), 'err');
                return;
            }
            setStatus('✅ Сохранено', 'ok');
            if (onOk) onOk();
        })
        .catch(function () { setStatus('⚠️ Ошибка сети', 'err'); });
    }

    // вариант post() для action'ов, отвечающих JSON ({ok:1, ...});
    // ошибка — http-код + текст, как у обычных action'ов
    function postJSON(params, onOk, errTarget) {
        if (params.map_id === undefined) params.map_id = MAP_ID > 0 ? MAP_ID : 1;
        fetch(AED, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        })
        .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
        .then(function (res) {
            var d = null;
            try { d = JSON.parse(res.text); } catch (e) {}
            if (!res.ok || !d || d.ok !== 1) {
                hwErr(errTarget, (d && d.error) || res.text.replace(/^Ошибка:\s*/, '') || 'Ошибка запроса');
                return;
            }
            if (onOk) onOk(d);
        })
        .catch(function () { hwErr(errTarget, 'Ошибка сети'); });
    }

    // ошибка внутри модалки (errTarget = id блока) или в статус карты
    function hwErr(errTarget, text) {
        var el = errTarget ? document.getElementById(errTarget) : null;
        if (!el) { setStatus('⚠️ ' + text, 'err'); return; }
        el.textContent = '⚠️ ' + text;
        el.classList.remove('d-none');
    }
    function hwErrClear(elId) {
        var el = document.getElementById(elId);
        if (el) el.classList.add('d-none');
    }

    // --------------------------------------------------------
    // Карточка оборудования узла (создать/привязать hardware)
    // --------------------------------------------------------
    var HW_DICTS = null;   // справочники + список устройств (из action=hw_form)
    var HW_NODE_ID = null; // узел карты, для которого открыта карточка

    function hwSel(el, emptyLabel) {
        el.innerHTML = '';
        var o = document.createElement('option');
        o.value = '0';
        o.textContent = emptyLabel;
        el.appendChild(o);
        return el;
    }
    function hwOpt(el, val, text) {
        var o = document.createElement('option');
        o.value = val;
        o.textContent = text;
        el.appendChild(o);
    }

    var TS = {}; // инстансы TomSelect для длинных списков

    function hwTomify(id) {
        if (!window.TomSelect) return;
        if (TS[id]) TS[id].destroy();
        TS[id] = new TomSelect(document.getElementById(id), { maxOptions: null });
    }
    function hwSetVal(id, val) {
        if (TS[id]) TS[id].setValue(String(val || '0'), true);
        else document.getElementById(id).value = val || 0;
    }

    // локации — группами по типу (порядок из БД: sort типа, потом имя)
    function hwFillLoc(selected) {
        var l = hwSel(document.getElementById('mhw-loc'), '— нет —');
        var groups = {};
        (HW_DICTS.locations || []).forEach(function (x) {
            var g = x.type_name || 'Без типа';
            if (!groups[g]) {
                groups[g] = document.createElement('optgroup');
                groups[g].label = g;
                l.appendChild(groups[g]);
            }
            hwOpt(groups[g], x.id, x.name);
        });
        hwSetVal('mhw-loc', selected || 0);
    }

    // заполнить все селекты карточки из справочников
    function hwFillDicts() {
        var d = HW_DICTS;
        var ex = hwSel(document.getElementById('mhw-existing'), '— новое устройство —');
        d.hardware.forEach(function (h) {
            hwOpt(ex, h.id, (h.switch_name || ('hw-' + h.id)) + (h.hardware_ip ? ' · ' + h.hardware_ip : ''));
        });
        var m = hwSel(document.getElementById('mhw-model'), '— выберите модель —');
        d.models.forEach(function (x) { hwOpt(m, x.id, x.brand_name + ' — ' + x.model_name); });
        hwFillLoc(0);
        var c = hwSel(document.getElementById('mhw-cred'), '— нет —');
        d.creds.forEach(function (x) { hwOpt(c, x.id, x.name); });
        hwFillRooms(0, 0);
        // поиск по длинным спискам (устройства, локации) — TomSelect
        hwTomify('mhw-existing');
        hwTomify('mhw-loc');
    }

    // помещения — только выбранной локации
    function hwFillRooms(locId, selected) {
        var r = hwSel(document.getElementById('mhw-room'), '— нет —');
        (HW_DICTS.rooms || []).forEach(function (x) {
            if (parseInt(x.locations_id, 10) === parseInt(locId, 10)) hwOpt(r, x.id, x.name);
        });
        r.value = selected || 0;
    }

    // при выборе существующего устройства поля заполняются его данными
    function hwFillFromExisting() {
        var id = parseInt(document.getElementById('mhw-existing').value, 10);
        var nameEl = document.getElementById('mhw-name');
        if (!id) {
            // новое: имя по умолчанию = подпись узла
            var n = rawNodes[HW_NODE_ID];
            nameEl.value = n ? (n.label || '') : '';
            document.getElementById('mhw-ip').value = '';
            document.getElementById('mhw-model').value = 0;
            hwSetVal('mhw-loc', 0);
            hwFillRooms(0, 0);
            document.getElementById('mhw-cred').value = 0;
            document.getElementById('mhw-poe').checked = false;
            document.getElementById('mhw-active').checked = true;
            return;
        }
        var h = null;
        HW_DICTS.hardware.forEach(function (x) { if (parseInt(x.id, 10) === id) h = x; });
        if (!h) return;
        nameEl.value = h.switch_name || '';
        document.getElementById('mhw-ip').value = h.hardware_ip || '';
        document.getElementById('mhw-model').value = h.id_model || 0;
        hwSetVal('mhw-loc', h.locations_id || 0);
        hwFillRooms(h.locations_id || 0, h.rooms_id || 0);
        document.getElementById('mhw-cred').value = h.cred_id || 0;
        document.getElementById('mhw-poe').checked = parseInt(h.poe, 10) === 1;
        document.getElementById('mhw-active').checked = parseInt(h.active, 10) === 1;
    }

    function openHwCard(nodeId) {
        HW_NODE_ID = nodeId;
        postJSON({ action: 'hw_form' }, function (d) {
            HW_DICTS = d;
            hwFillDicts();
            var n = rawNodes[HW_NODE_ID];
            hwSetVal('mhw-existing', (n && n.hardware_id) ? n.hardware_id : 0);
            hwFillFromExisting();
            hwErrClear('mhw-error');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mapsHwModal')).show();
        });
    }

    document.getElementById('maps-nodeedit-hw').addEventListener('click', function () {
        if (this.dataset.node) openHwCard(this.dataset.node);
    });
    document.getElementById('mhw-existing').addEventListener('change', hwFillFromExisting);
    document.getElementById('mhw-loc').addEventListener('change', function () {
        hwFillRooms(this.value, 0);
    });

    document.getElementById('mhw-save').addEventListener('click', function () {
        postJSON({
            action: 'hw_save',
            node_id: HW_NODE_ID,
            hardware_id: document.getElementById('mhw-existing').value,
            id_model: document.getElementById('mhw-model').value,
            switch_name: document.getElementById('mhw-name').value,
            hardware_ip: document.getElementById('mhw-ip').value,
            locations_id: document.getElementById('mhw-loc').value,
            rooms_id: document.getElementById('mhw-room').value,
            cred_id: document.getElementById('mhw-cred').value,
            poe: document.getElementById('mhw-poe').checked ? 1 : 0,
            active: document.getElementById('mhw-active').checked ? 1 : 0
        }, function () {
            bootstrap.Modal.getInstance(document.getElementById('mapsHwModal')).hide();
            setStatus('✅ Оборудование сохранено и привязано', 'ok');
            loadData();
        }, 'mhw-error');
    });

    // --------------------------------------------------------
    // Быстрое добавление в справочники (модель / локация / помещение)
    // --------------------------------------------------------
    var MQ_TYPE = null; // 'model' | 'location' | 'room'

    function openQuick(type) {
        MQ_TYPE = type;
        hwErrClear('mq-error');
        ['model', 'location', 'room'].forEach(function (t) {
            document.getElementById('mq-' + t).classList.toggle('d-none', t !== type);
        });
        if (type === 'model') {
            document.getElementById('mq-title').textContent = 'Новая модель';
            var b = hwSel(document.getElementById('mq-brand'), '— бренд —');
            HW_DICTS.brands.forEach(function (x) { hwOpt(b, x.id, x.brand_name); });
            var t2 = hwSel(document.getElementById('mq-mtype'), '— тип —');
            HW_DICTS.model_types.forEach(function (x) { hwOpt(t2, x.id, x.name); });
            document.getElementById('mq-model-name').value = '';
        } else if (type === 'location') {
            document.getElementById('mq-title').textContent = 'Новая локация';
            var lt = hwSel(document.getElementById('mq-ltype'), '— тип —');
            HW_DICTS.loc_types.forEach(function (x) { hwOpt(lt, x.id, x.name); });
            document.getElementById('mq-loc-name').value = '';
        } else {
            if (!parseInt(document.getElementById('mhw-loc').value, 10)) {
                hwErr('mhw-error', 'Сначала выберите локацию в карточке');
                return;
            }
            document.getElementById('mq-title').textContent = 'Новое помещение';
            document.getElementById('mq-room-name').value = '';
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mapsQuickModal')).show();
    }

    document.getElementById('mhw-model-add').addEventListener('click', function () { openQuick('model'); });
    document.getElementById('mhw-loc-add').addEventListener('click', function () { openQuick('location'); });
    document.getElementById('mhw-room-add').addEventListener('click', function () { openQuick('room'); });

    document.getElementById('mq-save').addEventListener('click', function () {
        if (MQ_TYPE === 'model') {
            var brandId = parseInt(document.getElementById('mq-brand').value, 10);
            var brandName = '';
            HW_DICTS.brands.forEach(function (x) { if (parseInt(x.id, 10) === brandId) brandName = x.brand_name; });
            postJSON({
                action: 'model_add',
                brand_id: brandId,
                model_type_id: document.getElementById('mq-mtype').value,
                model_name: document.getElementById('mq-model-name').value
            }, function (d) {
                HW_DICTS.models.push({ id: d.id, model_name: d.name, id_brand: brandId, brand_name: brandName });
                var m = document.getElementById('mhw-model');
                hwOpt(m, d.id, brandName + ' — ' + d.name);
                m.value = d.id;
                bootstrap.Modal.getInstance(document.getElementById('mapsQuickModal')).hide();
            }, 'mq-error');
        } else if (MQ_TYPE === 'location') {
            var ltId = parseInt(document.getElementById('mq-ltype').value, 10);
            var ltName = '';
            HW_DICTS.loc_types.forEach(function (x) { if (parseInt(x.id, 10) === ltId) ltName = x.name; });
            postJSON({
                action: 'location_add',
                type_id: ltId,
                name: document.getElementById('mq-loc-name').value
            }, function (d) {
                HW_DICTS.locations.push({ id: d.id, name: d.name, type_name: ltName });
                // пересобираем список локаций (группы по типам) и TomSelect
                if (TS['mhw-loc']) { TS['mhw-loc'].destroy(); delete TS['mhw-loc']; }
                hwFillLoc(d.id);
                hwTomify('mhw-loc');
                hwSetVal('mhw-loc', d.id);
                hwFillRooms(d.id, 0);
                bootstrap.Modal.getInstance(document.getElementById('mapsQuickModal')).hide();
            }, 'mq-error');
        } else if (MQ_TYPE === 'room') {
            var locId = parseInt(document.getElementById('mhw-loc').value, 10);
            postJSON({
                action: 'room_add',
                locations_id: locId,
                name: document.getElementById('mq-room-name').value
            }, function (d) {
                HW_DICTS.rooms.push({ id: d.id, locations_id: locId, name: d.name });
                hwFillRooms(locId, d.id);
                bootstrap.Modal.getInstance(document.getElementById('mapsQuickModal')).hide();
            }, 'mq-error');
        }
    });

    window.mapsHideForms = function () {
        ['maps-form-node', 'maps-form-nodeedit', 'maps-form-link', 'maps-form-group', 'maps-link-info']
            .forEach(function (id) {
                document.getElementById(id).classList.add('d-none');
            });
        if (network) network.unselectAll();
    };

    function showForm(id) {
        ['maps-form-node', 'maps-form-nodeedit', 'maps-form-link', 'maps-form-group']
            .forEach(function (fid) {
                document.getElementById(fid).classList.toggle('d-none', fid !== id);
            });
    }

    // --------------------------------------------------------
    // Построение графа
    // --------------------------------------------------------
    // цвета статусов линков теперь в STYLE.links (редактор в модальном окне)

    var nodeToVis = function (n) {
        // Картинка узла в стиле The Dude: прямоугольник с иконкой и текстом внутри,
        // фон по состоянию ping. Генерирует /mod/maps/nodeicon.php
        var state = '';
        if (n.state === true)  state = 'up';
        if (n.state === false) state = 'down';
        return {
            id: n.id,
            x: n.x, y: n.y,
            shape: 'image',
            image: '/mod/maps/nodeicon.php'
                 + '?role=' + encodeURIComponent(effectiveRole(n))
                 + '&state=' + state
                 + '&n=' + encodeURIComponent(n.label)
                 + '&s=' + encodeURIComponent(n.sub || '')
                 + '&cm=' + encodeURIComponent(n.comment || '')
                 + '&ip=' + encodeURIComponent(n.ip || '')
                 + '&fw=' + encodeURIComponent(n.firmware || '')
                 + '&up=' + encodeURIComponent(n.uptime || '')
                 + '&tp=' + encodeURIComponent(n.temperature !== null ? n.temperature : '')
                 + '&icon=' + encodeURIComponent(n.icon || '')
                 + '&map=' + MAP_ID
                 + '&v=' + ICON_VER
        };
    };

    // Подпись линка, построчно (пустых строк нет — линия просто не выводится):
    //   1: Узел1 → Узел2     2: порт → порт     3: скорость     4: подпись
    function linkParts(l) {
        var lines = [];
        var a = rawNodes[l.src], b = rawNodes[l.dst];
        if (a && b) lines.push(a.label + ' → ' + b.label);
        if (l.src_port || l.dst_port) {
            lines.push((l.src_port || '?') + ' → ' + (l.dst_port || '?'));
        }
        if (l.speed) lines.push(l.speed);
        if (l.label) lines.push(l.label);
        return lines.join('\n');
    }

    // Цвет линка: статус мониторинга > ручной цвет > цвет по скорости > серый
    function linkColor(l) {
        if (l.status === 'up' || l.status === 'down') return STYLE.links[l.status];
        if (l.color && LINK_COLORS[l.color]) return LINK_COLORS[l.color].css;
        if (l.speed && SPEED_COLORS[l.speed]) return SPEED_COLORS[l.speed];
        return LINK_COLORS[''].css;
    }

    // --- VLAN-подсветка линков (из конфигов свичей) ---
    var HL_VLAN = null;   // выбранный в селекторе VLAN; null — подсветка выключена

    function endpointHasVlan(ep, vid) {
        if (!ep) return false;
        if (ep.pvid === vid) return true;
        if (ep.tagged === 'ALL') return true;
        if (ep.tagged && ep.tagged.indexOf(vid) >= 0) return true;
        if (ep.untagged && ep.untagged.indexOf(vid) >= 0) return true;
        return false;
    }

    // Цвет линка при активной VLAN-подсветке; null — подсветка выключена
    function linkVlanHl(l) {
        if (!HL_VLAN) return null;
        var s = endpointHasVlan(l.src_vlan, HL_VLAN);
        var d = endpointHasVlan(l.dst_vlan, HL_VLAN);
        if (s && d) return { color: '#198754', add: 2 };  // VLAN есть с двух сторон
        if (s || d) return { color: '#fd7e14', add: 1 };  // только с одной — рассинхрон
        return { color: '#e4e7eb', add: 0 };              // линк не участвует
    }

    // Перерисовать все линки (при смене VLAN-подсветки)
    function refreshLinks() {
        var wpIds = [];
        nodesDS.forEach(function (n) { if (typeof n.id === 'string') wpIds.push(n.id); });
        nodesDS.remove(wpIds);
        wpToLink = {};
        edgesDS.clear();
        Object.keys(rawLinks).forEach(function (id) {
            var r = renderLink(rawLinks[id]);
            r.nodes.forEach(function (n) { nodesDS.add(n); });
            r.edges.forEach(function (e) { edgesDS.add(e); });
        });
    }

    // Порядковый номер линка среди всех линков той же пары узлов (по id).
    // Нужен, чтобы развести параллельные линки разной кривизной.
    function linkPairPos(l) {
        var idx = 0, count = 0;
        var a = Math.min(l.src, l.dst), b = Math.max(l.src, l.dst);
        Object.keys(rawLinks).forEach(function (id) {
            var o = rawLinks[id];
            if (Math.min(o.src, o.dst) === a && Math.max(o.src, o.dst) === b) {
                count++;
                if (parseInt(id, 10) < l.id) idx++;
            }
        });
        return { idx: idx, count: count };
    }

    // Рендер линка -> { nodes: [...], edges: [...] }
    // Ортогональный линк рисуем цепочкой сегментов через точки излома.
    function renderLink(l) {
        var out = { nodes: [], edges: [] };
        var hl = linkVlanHl(l);
        var color = hl ? hl.color : linkColor(l);
        var width = (SPEED_WIDTH[l.speed] || 1) + (hl ? hl.add : 0);
        var label = linkParts(l);

        if (l.line !== 'elbow' || !l.points.length) {
            // обычный линк — одна линия; параллельные линки той же пары
            // разводим возрастающей кривизной, иначе лягут друг на друга
            var pair = linkPairPos(l);
            var smooth;
            if (pair.count > 1) {
                smooth = { enabled: true, type: 'curvedCW', roundness: 0.18 * (pair.idx + 1) };
            } else if (l.line === 'straight') {
                smooth = { enabled: true, type: 'straightCross', roundness: 0 };
            } else {
                smooth = { enabled: true, type: 'continuous' };
            }
            out.edges.push({
                id: l.id,
                from: l.src,
                to: l.dst,
                label: label,
                width: width,
                color: { color: color, highlight: '#0d6efd', hover: '#0d6efd' },
                font: { size: parseInt(STYLE.links.font_size, 10) || 10, color: '#333333', background: 'rgba(179,179,179,0.8)', strokeWidth: 0 },
                smooth: smooth
            });
            return out;
        }

        // elbow: точки излома как маленькие узлы + прямые сегменты
        var chain = [l.src];
        l.points.forEach(function (p) {
            var wpId = 'wp' + p.id;
            wpToLink[wpId] = l.id;
            out.nodes.push({
                id: wpId,
                x: p.x, y: p.y,
                shape: 'dot',
                size: parseInt(STYLE.links.wp_size, 10) || 4,
                color: { background: '#333333', border: '#FFFFFF' },
                borderWidth: 1,
                label: ' ',
                title: 'Точка излома (тащите мышкой)'
            });
            chain.push(wpId);
        });
        chain.push(l.dst);

        var midSeg = Math.floor((chain.length - 1) / 2); // подпись на средний сегмент
        for (var i = 0; i < chain.length - 1; i++) {
            out.edges.push({
                id: 'l' + l.id + 's' + i,
                from: chain[i],
                to: chain[i + 1],
                label: i === midSeg ? label : '',
                width: width,
                color: { color: color, highlight: '#0d6efd', hover: '#0d6efd' },
                font: { size: parseInt(STYLE.links.font_size, 10) || 10, color: '#333333', background: 'rgba(179,179,179,0.8)', strokeWidth: 0 },
                smooth: false
            });
        }
        return out;
    }

    function fillLinkNodeSelects(preSrc, preDst) {
        ['maps-link-src', 'maps-link-dst'].forEach(function (id) {
            var sel = document.getElementById(id);
            sel.innerHTML = '<option value="">— выберите —</option>';
        });
        Object.keys(rawNodes).forEach(function (id) {
            ['maps-link-src', 'maps-link-dst'].forEach(function (sid) {
                var o = document.createElement('option');
                o.value = id;
                o.textContent = rawNodes[id].label;
                document.getElementById(sid).appendChild(o);
            });
        });
        if (preSrc) document.getElementById('maps-link-src').value = preSrc;
        if (preDst) document.getElementById('maps-link-dst').value = preDst;
    }

    // в форме нового линка списки портов следуют за выбранными узлами
    [['maps-link-src', 'maps-link-srcport'],
     ['maps-link-dst', 'maps-link-dstport']].forEach(function (pair) {
        document.getElementById(pair[0]).addEventListener('change', function () {
            fillPortSelect(this.value, pair[1], '');
        });
    });

    function fillMapSelect(maps, currentId) {
        var sel = document.getElementById('maps-map-select');
        sel.innerHTML = '';
        maps.forEach(function (m) {
            var o = document.createElement('option');
            o.value = m.id;
            o.textContent = m.name;
            sel.appendChild(o);
        });
        sel.value = currentId;
    }

    function loadData(onLoaded) {
        fetch('/mod/maps/data.php?map_id=' + (MAP_ID > 0 ? MAP_ID : 0), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                // только что создали карту — переключаемся на неё и грузим заново
                if (MAP_ID === -1) {
                    MAP_ID = 0;
                    (d.maps || []).forEach(function (m) { if (m.id > MAP_ID) MAP_ID = m.id; });
                    loadData(onLoaded);
                    return;
                }
                MAP_ID = d.map_id;
                if (d.settings) SETTINGS = d.settings;
                // глобальный выключатель мониторинга (галочка есть только у редакторов)
                var monChk = document.getElementById('maps-monitoring');
                if (monChk) monChk.checked = d.monitoring !== false;
                // период автообновления из общих настроек (settings.maps_refresh_sec)
                if (d.refresh_sec) REFRESH_SEC = d.refresh_sec;
                LAST_LOAD = Date.now();
                resetStyle();
                mergeStyle(SETTINGS.style);
                applyBackground();
                BG_IMG = null;
                // список картинок подложки
                if (d.backgrounds) {
                    var bsel = document.getElementById('maps-style-bg-image');
                    var curBg = bsel.value;
                    bsel.innerHTML = '<option value="">— нет —</option>';
                    d.backgrounds.forEach(function (f) {
                        var o = document.createElement('option');
                        o.value = f;
                        o.textContent = f;
                        bsel.appendChild(o);
                    });
                    bsel.value = curBg || STYLE.background.image || '';
                }
                if (d.icons) {
                    ICON_FILES = d.icons;
                    // список иконок в форме редактирования узла
                    var isel = document.getElementById('maps-nodeedit-icon');
                    isel.innerHTML = '<option value="">— по типу —</option>';
                    ICON_FILES.forEach(function (f) {
                        var o = document.createElement('option');
                        o.value = f;
                        o.textContent = f;
                        isel.appendChild(o);
                    });
                    updateIconPreview();
                }
                fillMapSelect(d.maps || [], MAP_ID);

                // Селектор VLAN-подсветки: наполняем и восстанавливаем выбор
                var vsel = document.getElementById('maps-vlan-select');
                if (vsel) {
                    var curV = vsel.value;
                    vsel.innerHTML = '<option value="">VLAN: все</option>';
                    (d.vlans || []).forEach(function (v) {
                        var o = document.createElement('option');
                        o.value = v.id;
                        o.textContent = 'VLAN ' + v.id + (v.name ? ' — ' + v.name : '');
                        vsel.appendChild(o);
                    });
                    vsel.classList.toggle('d-none', !(d.vlans || []).length);
                    vsel.value = curV;
                    if (vsel.value !== curV) vsel.value = '';   // VLAN пропал из списка
                    HL_VLAN = vsel.value ? parseInt(vsel.value, 10) : null;
                }

                nodesDS.clear();
                edgesDS.clear();
                rawNodes = {};
                rawLinks = {};
                rawGroups = {};
                wpToLink = {};
                d.nodes.forEach(function (n) {
                    rawNodes[n.id] = n;
                    nodesDS.add(nodeToVis(n));
                });
                d.links.forEach(function (l) {
                    rawLinks[l.id] = l;
                    var r = renderLink(l);
                    r.nodes.forEach(function (n) { nodesDS.add(n); });
                    r.edges.forEach(function (e) { edgesDS.add(e); });
                });
                // блоки-контейнеры: рамка рисуется на канвасе, без узлов-ручек
                (d.groups || []).forEach(function (g) {
                    rawGroups[g.id] = g;
                });

                availHw = d.avail || [];
                PORTS = d.ports || {};
                var sel = document.getElementById('maps-node-hw');
                if (sel) {
                    sel.innerHTML = '<option value="0">— свободный узел (Интернет и т.п.) —</option>';
                    availHw.forEach(function (h) {
                        var o = document.createElement('option');
                        o.value = h.id;
                        o.textContent = h.name + (h.ip ? ' (' + h.ip + ')' : '');
                        sel.appendChild(o);
                    });
                }

                // новым узлам без координат — раскидать, чтобы не лежали кучей
                var needLayout = d.nodes.some(function (n) { return n.x === null && n.y === null; });
                if (needLayout && network) {
                    network.stabilize(50);
                    saveAllPositions();
                }

                if (onLoaded) onLoaded();
            })
            .catch(function () { setStatus('⚠️ Не удалось загрузить карту', 'err'); });
    }

    function saveAllPositions() {
        if (!CAN_EDIT) return;
        var pos = network.getPositions();
        Object.keys(pos).forEach(function (id) {
            post({ action: 'move_node', id: id, x: pos[id].x, y: pos[id].y });
        });
    }

    // --------------------------------------------------------
    // Инициализация
    // --------------------------------------------------------
    // заполняем списки типов
    ['maps-node-role', 'maps-nodeedit-role'].forEach(function (id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        Object.keys(TYPES).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = TYPES[k].name;
            sel.appendChild(o);
        });
    });

    // список цветов линий (с цветными маркерами)
    var selColor = document.getElementById('maps-link-color');
    Object.keys(LINK_COLORS).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = '● ' + LINK_COLORS[k].name;
        o.style.color = LINK_COLORS[k].css;
        selColor.appendChild(o);
    });

    // легенда убрана — типы различимы по иконкам узлов

    network = new vis.Network(
        document.getElementById('maps-canvas'),
        { nodes: nodesDS, edges: edgesDS },
        {
            physics: false,
            interaction: {
                dragNodes: CAN_EDIT && !MAP_LOCK,
                dragView: true,
                zoomView: true,
                multiselect: true,          // Ctrl+клик — выбрать два узла для линка
                selectConnectedEdges: false
            },
            edges: { arrows: { to: { enabled: false } } }
        }
    );

    // --- Блокировка карты: запрет перетаскивания (выбор и просмотр остаются) ---
    var lockEl = document.getElementById('maps-lock');
    if (lockEl) {
        lockEl.checked = MAP_LOCK;
        lockEl.addEventListener('change', function () {
            MAP_LOCK = this.checked;
            localStorage.setItem('maps_lock', MAP_LOCK ? '1' : '0');
            network.setOptions({ interaction: { dragNodes: CAN_EDIT && !MAP_LOCK } });
            setStatus(MAP_LOCK ? '🔒 Карта заблокирована: перетаскивание выключено'
                               : '🔓 Карта разблокирована', 'ok');
        });
    }

    // --- Ширина правой панели: тянем за левую кромку, значение хранится в браузере ---
    (function () {
        var layout = document.querySelector('.maps-layout');
        var panel = document.querySelector('.maps-panel');
        if (!layout || !panel) return;
        var grip = document.createElement('div');
        grip.className = 'maps-resize';
        grip.title = 'Тяните, чтобы изменить ширину панели';
        layout.appendChild(grip);
        function setW(w, save) {
            w = Math.max(240, Math.min(480, Math.round(w)));
            panel.style.width = w + 'px';
            panel.style.flexBasis = w + 'px';
            grip.style.right = (w - 5) + 'px';
            if (save) localStorage.setItem('maps_panel_w', String(w));
        }
        setW(parseInt(localStorage.getItem('maps_panel_w') || '320', 10), false);
        grip.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var startX = e.clientX, startW = panel.offsetWidth;
            function mv(ev) { setW(startW + (startX - ev.clientX), false); }
            function up() {
                document.removeEventListener('mousemove', mv);
                document.removeEventListener('mouseup', up);
                setW(panel.offsetWidth, true);
                if (network) network.redraw();
            }
            document.addEventListener('mousemove', mv);
            document.addEventListener('mouseup', up);
        });
    })();

    // Подложка и рамки блоков рисуем на канвасе ПОД узлами и линками.
    // Все параметры — из STYLE (редактор в модальном окне).
    network.on('beforeDrawing', function (ctx) {
        // подложка: опционально картинка (файл из plugins/maps/backgrounds/).
        // Цвет фона и сетка — через CSS элемента (applyBackground), не здесь.
        if (STYLE.background.image) {
            if (!BG_IMG) {
                BG_IMG = new Image();
                BG_IMG.onload = function () { network.redraw(); };
                BG_IMG.src = '/plugins/maps/backgrounds/' + STYLE.background.image;
            } else if (BG_IMG.complete && BG_IMG.naturalWidth) {
                ctx.save();
                ctx.globalAlpha = Math.max(0.05, Math.min(1, parseFloat(STYLE.background.opacity) || 0.35));
                // кладём картинку тайлами, чтобы покрыть весь видимый холст
                var view = network.getViewPosition();
                var scale = network.getScale();
                var cw = mapsCanvasEl.clientWidth / scale;
                var ch = mapsCanvasEl.clientHeight / scale;
                var x0 = view.x - cw / 2, y0 = view.y - ch / 2;
                var tw = BG_IMG.naturalWidth, th = BG_IMG.naturalHeight;
                for (var ty = Math.floor(y0 / th) * th; ty < y0 + ch; ty += th) {
                    for (var tx = Math.floor(x0 / tw) * tw; tx < x0 + cw; tx += tw) {
                        ctx.drawImage(BG_IMG, tx, ty);
                    }
                }
                ctx.restore();
            }
        }

        Object.keys(rawGroups).forEach(function (id) {
            var g = rawGroups[id];
            var hh = STYLE.block.header_h;
            // рамка
            ctx.strokeStyle = STYLE.block.border;
            ctx.lineWidth = 1.5;
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.rect(g.x, g.y, g.w, g.h);
            ctx.stroke();
            // шапка
            ctx.fillStyle = STYLE.block.header_bg;
            ctx.globalAlpha = 0.92;
            ctx.fillRect(g.x + 0.75, g.y + 0.75, g.w - 1.5, hh);
            ctx.globalAlpha = 1;
            ctx.strokeStyle = STYLE.block.border;
            ctx.beginPath();
            ctx.moveTo(g.x, g.y + hh + 0.75);
            ctx.lineTo(g.x + g.w, g.y + hh + 0.75);
            ctx.stroke();
            if (g.label) {
                ctx.fillStyle = STYLE.block.header_text;
                ctx.font = 'bold ' + STYLE.block.font_size + 'px "Segoe UI", sans-serif';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText(g.label, g.x + 8, g.y + hh / 2 + 1);
            }
            // уголок-гrip в правом нижнем углу (намёк на растягивание)
            if (CAN_EDIT) {
                ctx.strokeStyle = '#b0b0b0';
                ctx.lineWidth = 1.5;
                for (var i = 0; i < 3; i++) {
                    var off = 6 + i * 5;
                    ctx.beginPath();
                    ctx.moveTo(g.x + g.w - off, g.y + g.h - 2);
                    ctx.lineTo(g.x + g.w - 2, g.y + g.h - off);
                    ctx.stroke();
                }
            }
        });
    });

    // сохранение позиции после перетаскивания (узлы и точки излома)
    network.on('dragEnd', function (params) {
        if (!CAN_EDIT || !params.nodes.length) return;
        var pos = network.getPositions(params.nodes);
        params.nodes.forEach(function (id) {
            if (typeof id === 'string' && id.indexOf('wp') === 0) {
                post({ action: 'move_point', id: id.substring(2), x: pos[id].x, y: pos[id].y });
            } else {
                post({ action: 'move_node', id: id, x: pos[id].x, y: pos[id].y });
            }
        });
    });

    // Подсветка выбранного узла/линка — цвет, пунктир и отступ из STYLE
    network.on('afterDrawing', function (ctx) {
        var sel = network.getSelection();
        if (!sel.nodes.length) return;
        var dashStr = STYLE.selection.dash || '';   // пусто = сплошная линия
        var dash = dashStr ? dashStr.split(',').map(function (v) { return parseFloat(v) || 4; }) : [];
        var pad = STYLE.selection.padding;
        sel.nodes.forEach(function (id) {
            // точки излома не рамим квадратом — они и так подсвечиваются
            if (typeof id === 'string' && id.indexOf('wp') === 0) return;
            var bb = network.getBoundingBox(id);
            if (!bb) return;
            ctx.strokeStyle = STYLE.selection.color;
            ctx.lineWidth = 2;
            ctx.setLineDash(dash);
            ctx.beginPath();
            ctx.rect(bb.left - pad, bb.top - pad,
                     (bb.right - bb.left) + pad * 2, (bb.bottom - bb.top) + pad * 2);
            ctx.stroke();
            ctx.setLineDash([]);
        });
    });

    // выбор узла — форма редактирования (или точка излома -> форма линка)
    network.on('selectNode', function (params) {
        network.redraw();
        var id = params.nodes[0];
        // выбрали узел (не точку излома) — инспектор VLAN линка больше не актуален
        if (!(typeof id === 'string' && id.indexOf('wp') === 0)) {
            document.getElementById('maps-link-info').classList.add('d-none');
        }
        if (!CAN_EDIT) return;

        // точка излома — открываем редактирование её линка
        if (typeof id === 'string' && id.indexOf('wp') === 0) {
            var linkId = wpToLink[id];
            if (linkId && rawLinks[linkId]) openLinkEdit(rawLinks[linkId]);
            return;
        }

        var n = rawNodes[id];
        if (!n) return;
        document.getElementById('maps-nodeedit-label').value = n.label;
        document.getElementById('maps-nodeedit-comment').value = n.comment || '';
        document.getElementById('maps-nodeedit-role').value =
            TYPES[n.role] ? n.role : 'other';
        // Узел с железом: тип задаётся моделью устройства (меняется через «Оборудование») —
        // строку «Тип» в карточке прячем целиком; узел без железа — селектор доступен.
        // Виртуальные узлы (облако, неуправляемый свич): тип НЕ меняем (захотел другой —
        // удали и создай новый), мониторинга и привязки железа у них нет по определению.
        var virt = !n.hardware_id && (n.role === 'cloud' || n.role === 'umswitch');
        document.getElementById('maps-nodeedit-role-wrap').classList.toggle('d-none', !!n.hardware_id);
        document.getElementById('maps-nodeedit-role').disabled = virt;
        document.getElementById('maps-nodeedit-mon-wrap').classList.toggle('d-none', virt);
        document.getElementById('maps-nodeedit-hw-wrap').classList.toggle('d-none', virt);
        // у облака своя отрисовка — селектор иконки на него не действует
        document.getElementById('maps-nodeedit-icon-wrap').classList.toggle('d-none',
            !n.hardware_id && n.role === 'cloud');
        document.getElementById('maps-nodeedit-icon').value = n.icon || '';
        updateIconPreview();
        document.getElementById('maps-nodeedit-check').value = n.check_type || 'ping';
        document.getElementById('maps-nodeedit-port').value = n.check_port || '';
        updatePortVisibility();
        document.getElementById('maps-nodeedit-info').textContent =
            n.hardware_id ? ([n.sub, n.ip].filter(Boolean).join(' · ') || '—') : '—';
        // кнопка веб-интерфейса устройства (http; выбор http/https — ПКМ по узлу)
        var webBtn = document.getElementById('maps-nodeedit-web');
        if (n.ip) {
            webBtn.href = 'http://' + n.ip;
            webBtn.title = 'Открыть веб-интерфейс: http://' + n.ip + ' (ПКМ по узлу — выбор http/https)';
            webBtn.classList.remove('d-none');
        } else {
            webBtn.classList.add('d-none');
        }
        document.getElementById('maps-nodeedit-hwwarn').classList.toggle('d-none', !!n.hardware_id);
        document.getElementById('maps-nodeedit-hw').dataset.node = id;
        document.getElementById('maps-nodeedit-save').dataset.id = id;
        document.getElementById('maps-nodeedit-del').dataset.id = id;
        showForm('maps-form-nodeedit');
    });

    // Открытие формы редактирования линка
    function openLinkEdit(l) {
        var a = rawNodes[l.src], b = rawNodes[l.dst];
        var names = (a && b) ? (a.label + ' → ' + b.label) : '';
        document.getElementById('maps-link-title').textContent = names || 'Линк';
        // при редактировании узлы не меняем — прячем селекты
        document.getElementById('maps-link-selects').classList.add('d-none');
        // подсказки портов конкретных устройств на концах линка
        fillPortSelect(l.src, 'maps-link-srcport', l.src_port);
        fillPortSelect(l.dst, 'maps-link-dstport', l.dst_port);
        document.getElementById('maps-link-speed').value = l.speed;
        document.getElementById('maps-link-color').value = l.color;
        document.getElementById('maps-link-line').value = l.line || 'smooth';
        document.getElementById('maps-link-label').value = l.label;
        document.getElementById('maps-link-pointwrap').classList.toggle('d-none', l.line !== 'elbow');
        document.getElementById('maps-link-save').dataset.mode = 'update';
        document.getElementById('maps-link-del').classList.remove('d-none');
        document.getElementById('maps-link-swap').classList.remove('d-none');
        document.getElementById('maps-link-swap').dataset.id = l.id;
        document.getElementById('maps-link-save').dataset.id = l.id;
        showForm('maps-form-link');
    }

    // id ребра -> id линка (у elbow-сегментов id вида 'l<линк>s<N>')
    function edgeToLinkId(eid) {
        if (typeof eid === 'string' && eid.charAt(0) === 'l') {
            var m = eid.match(/^l(\d+)s\d+$/);
            if (m) return parseInt(m[1], 10);
        }
        return eid;
    }

    // --- Инспектор VLAN на линке ---
    // множество VLAN конца линка: Set чисел или 'ALL'
    function epVlanSet(ep) {
        if (!ep) return null;
        if (ep.tagged === 'ALL') return 'ALL';
        var s = new Set(ep.tagged || []);
        (ep.untagged || []).forEach(function (v) { s.add(v); });
        if (ep.pvid) s.add(ep.pvid);
        return s;
    }

    function fmtVlanList(arr, warn) {
        if (!arr.length) return '<span class="text-muted">—</span>';
        return '<span class="vlan-chips' + (warn ? ' warn' : '') + '">'
             + arr.map(function (v) { return '<span class="vlan-chip">' + v + '</span>'; }).join('')
             + '</span>';
    }

    function fmtEndpoint(title, ep, nb) {
        var h = '<div class="vlan-ep">';
        h += '<div class="mb-1"><span class="fw-semibold">' + title + '</span> ';
        if (!ep) {
            h += '<span class="text-muted">нет данных harvest</span></div>';
        } else {
            var mode = ep.mode === 'trunk' ? '<span class="badge text-bg-primary">trunk</span>'
                     : ep.mode === 'access' ? '<span class="badge text-bg-secondary">access</span>'
                     : '<span class="badge text-bg-light border">' + (ep.mode || '?') + '</span>';
            h += '<span class="text-muted">' + (ep.port || '') + '</span> ' + mode;
            if (ep.pvid) h += ' <span class="text-muted">pvid</span> ' + ep.pvid;
            h += '</div>';
            var tg = ep.tagged === 'ALL' ? '<span class="badge text-bg-info">ALL</span>' : fmtVlanList(ep.tagged || []);
            h += '<div><span class="text-muted">tagged:</span> ' + tg + '</div>';
        }
        if (nb && (nb.name || nb.nport)) {
            h += '<div class="mt-1"><span class="text-muted">сосед (LLDP):</span> '
               + '<span class="fw-semibold">' + (nb.name || '?') + '</span>'
               + (nb.nport ? ' · ' + nb.nport : '') + '</div>';
        }
        h += '</div>';
        return h;
    }

    // Конец линка в инспекторе: для объектов без железа и точек доступа
    // полный блок бессмыслен — harvest их порты не собирает, показываем одну строку.
    // Свичи (mtype=2) и роутеры (mtype=1) — с данными: harvest собирает им порты/VLAN/LLDP.
    function fmtEndpointSmart(n, port, ep, nb) {
        var title = n ? n.label : '?';
        if (n && !n.hardware_id) {
            return '<div class="vlan-ep text-muted">' + title
                 + ' — объект без привязки к оборудованию</div>';
        }
        if (n && n.mtype === 3) {
            return '<div class="vlan-ep text-muted">' + title
                 + ' — точка доступа (конечное устройство), VLAN-данных не будет</div>';
        }
        // свитч, но порт на линке не указан — VLAN определить нельзя
        if (!ep && !port) {
            return '<div class="vlan-ep"><span class="fw-semibold">' + title + '</span> '
                 + '<span class="text-muted">порт не указан на линке — VLAN определить нельзя</span></div>';
        }
        return fmtEndpoint(title + ' · ' + (port || '?'), ep, nb);
    }

    function showLinkInfo(l) {
        var box = document.getElementById('maps-link-info');
        var body = document.getElementById('maps-link-info-body');
        var a = rawNodes[l.src], b = rawNodes[l.dst];
        var h = '<div class="mb-2 text-muted">' + (a ? a.label : '?') + ' ↔ ' + (b ? b.label : '?') + '</div>';
        h += fmtEndpointSmart(a, l.src_port, l.src_vlan, l.src_lldp);
        h += fmtEndpointSmart(b, l.dst_port, l.dst_vlan, l.dst_lldp);

        var s = epVlanSet(l.src_vlan), d = epVlanSet(l.dst_vlan);
        if (s && d) {
            // пересечение и расхождения (ALL считается «содержит всё»)
            var common = [], onlyS = [], onlyD = [];
            var sAll = s === 'ALL', dAll = d === 'ALL';
            var union = new Set();
            if (!sAll) s.forEach(function (v) { union.add(v); });
            if (!dAll) d.forEach(function (v) { union.add(v); });
            union.forEach(function (v) {
                var inS = sAll || s.has(v), inD = dAll || d.has(v);
                if (inS && inD) common.push(v);
                else if (inS) onlyS.push(v);
                else onlyD.push(v);
            });
            var cmp = function (x, y) { return x - y; };
            common.sort(cmp); onlyS.sort(cmp); onlyD.sort(cmp);
            if (common.length || sAll || dAll) {
                h += '<div class="mt-1"><span class="text-muted">Общие:</span> '
                   + (common.length ? fmtVlanList(common) : '<span class="badge text-bg-info">ALL</span>') + '</div>';
            }
            if (onlyS.length) {
                h += '<div class="mt-1 text-warning-emphasis"><span class="text-muted">Только у ' + (a ? a.label : 'src') + ':</span> ' + fmtVlanList(onlyS, true) + '</div>';
            }
            if (onlyD.length) {
                h += '<div class="mt-1 text-warning-emphasis"><span class="text-muted">Только у ' + (b ? b.label : 'dst') + ':</span> ' + fmtVlanList(onlyD, true) + '</div>';
            }
        }
        body.innerHTML = h;
        box.classList.remove('d-none');
    }

    // Селектор VLAN-подсветки
    var vlanSel = document.getElementById('maps-vlan-select');
    if (vlanSel) vlanSel.addEventListener('change', function () {
        HL_VLAN = this.value ? parseInt(this.value, 10) : null;
        refreshLinks();
        setStatus(HL_VLAN ? 'Подсветка VLAN ' + HL_VLAN
            + ': зелёный — с двух сторон, оранжевый — только с одной' : '', 'ok');
    });

    // выбор линка — инспектор VLAN (всем) + форма редактирования (редакторам)
    network.on('selectEdge', function (params) {
        network.redraw();
        if (params.nodes.length) return;
        var l = rawLinks[edgeToLinkId(params.edges[0])];
        if (!l) return;
        showLinkInfo(l);
        if (!CAN_EDIT) return;
        openLinkEdit(l);
    });

    network.on('deselectNode', function () {
        network.redraw();
        // клик по пустому месту — снятие выделения, прячем форму редактирования
        var sel = network.getSelection();
        if (!sel.nodes.length && !sel.edges.length) {
            ['maps-form-node', 'maps-form-nodeedit', 'maps-form-link', 'maps-form-group', 'maps-link-info']
                .forEach(function (id) { document.getElementById(id).classList.add('d-none'); });
        }
    });
    network.on('deselectEdge', function () {
        network.redraw();
        var sel = network.getSelection();
        if (!sel.nodes.length && !sel.edges.length) {
            ['maps-form-node', 'maps-form-nodeedit', 'maps-form-link', 'maps-form-group', 'maps-link-info']
                .forEach(function (id) { document.getElementById(id).classList.add('d-none'); });
        }
    });

    // ПКМ по узлу — меню «открыть веб-интерфейс» (http/https)
    var ctxMenu = document.getElementById('maps-ctxmenu');
    function hideCtxMenu() { ctxMenu.classList.add('d-none'); }
    network.on('oncontext', function (params) {
        params.event.preventDefault();
        hideCtxMenu();
        var nid = network.getNodeAt(params.pointer.DOM);
        if (nid === undefined || nid === null) return;
        if (typeof nid === 'string' && nid.indexOf('wp') === 0) return; // точка излома
        var n = rawNodes[nid];
        if (!n || !n.ip) return;
        ctxMenu.innerHTML =
            '<a class="maps-ctxitem" target="_blank" rel="noopener" href="http://'  + n.ip + '">🌐 http://'  + n.ip + '</a>'
          + '<a class="maps-ctxitem" target="_blank" rel="noopener" href="https://' + n.ip + '">🔒 https://' + n.ip + '</a>';
        ctxMenu.style.left = params.pointer.DOM.x + 'px';
        ctxMenu.style.top  = params.pointer.DOM.y + 'px';
        ctxMenu.classList.remove('d-none');
    });
    network.on('click', hideCtxMenu);
    network.on('dragStart', hideCtxMenu);
    network.on('zoom', hideCtxMenu);
    document.addEventListener('click', function (e) {
        if (!ctxMenu.contains(e.target)) hideCtxMenu();
    });

    // --------------------------------------------------------
    // Кнопки
    // --------------------------------------------------------
    var btnAddNode = document.getElementById('maps-btn-addnode');
    if (btnAddNode) btnAddNode.addEventListener('click', function () {
        showForm('maps-form-node');
        setStatus('', '');
    });

    // «Показать всё» — вся карта на экране (спасение от потерянных узлов)
    document.getElementById('maps-btn-fit').addEventListener('click', function () {
        if (network) network.fit({ animation: { duration: 300, easingFunction: 'easeInOutQuad' } });
    });

    // --------------------------------------------------------
    // Настройки отображения карты (модальное окно)
    // --------------------------------------------------------
    var styleModal = new bootstrap.Modal(document.getElementById('mapsStyleModal'));

    function fillStyleForm() {
        // базовые
        document.getElementById('maps-set-font').value = SETTINGS.font_size;
        document.getElementById('maps-set-icon').value = SETTINGS.icon_size;
        document.getElementById('maps-set-imgmax').value = SETTINGS.icon_img_max || 96;
        document.getElementById('maps-set-model').checked = !!SETTINGS.show_model;
        document.getElementById('maps-set-ip').checked = !!SETTINGS.show_ip;
        document.getElementById('maps-set-firmware').checked = !!SETTINGS.show_firmware;
        document.getElementById('maps-set-uptime').checked = !!SETTINGS.show_uptime;
        document.getElementById('maps-set-temp').checked = !!SETTINGS.show_temp;
        document.getElementById('maps-set-comment').checked = !!SETTINGS.show_comment;
        // стиль
        document.getElementById('maps-style-sel-color').value = STYLE.selection.color;
        document.getElementById('maps-style-sel-dash').value = STYLE.selection.dash;
        document.getElementById('maps-style-sel-pad').value = STYLE.selection.padding;
        document.getElementById('maps-style-blk-font').value = STYLE.block.font_size;
        document.getElementById('maps-style-blk-head').value = STYLE.block.header_h;
        document.getElementById('maps-style-blk-border').value = STYLE.block.border;
        document.getElementById('maps-style-blk-hbg').value = STYLE.block.header_bg;
        document.getElementById('maps-style-blk-htext').value = STYLE.block.header_text;
        document.getElementById('maps-style-lnk-up').value = STYLE.links.up;
        document.getElementById('maps-style-lnk-down').value = STYLE.links.down;
        document.getElementById('maps-style-lnk-font').value = STYLE.links.font_size || 10;
        document.getElementById('maps-style-lnk-wp').value = STYLE.links.wp_size || 4;
        document.getElementById('maps-style-ic-neutral').value = STYLE.icons.neutral;
        document.getElementById('maps-style-ic-up').value = STYLE.icons.up;
        document.getElementById('maps-style-ic-down').value = STYLE.icons.down;
        document.getElementById('maps-style-bg-color').value = STYLE.background.color || STYLE_DEFAULTS.background.color;
        document.getElementById('maps-style-bg-grid').checked = !!parseInt(STYLE.background.grid, 10);
        document.getElementById('maps-style-bg-image').value = STYLE.background.image || '';
        var op = Math.round((parseFloat(STYLE.background.opacity) || STYLE_DEFAULTS.background.opacity) * 100);
        document.getElementById('maps-style-bg-opacity').value = op;
        document.getElementById('maps-style-bg-opacity-val').textContent = op + '%';
    }

    // Превью выбранной иконки рядом со списком
    function updateIconPreview() {
        var img = document.getElementById('maps-nodeedit-icon-preview');
        var v = document.getElementById('maps-nodeedit-icon').value;
        if (v) {
            img.src = '/plugins/maps/icons/custom/' + v + '?v=' + ICON_VER;
            img.style.display = 'block';
        } else {
            img.src = '';
            img.style.display = 'none';
        }
    }
    document.getElementById('maps-nodeedit-icon').addEventListener('change', updateIconPreview);

    document.getElementById('maps-style-bg-opacity').addEventListener('input', function () {
        document.getElementById('maps-style-bg-opacity-val').textContent = this.value + '%';
    });

    var btnSettings = document.getElementById('maps-btn-settings');
    if (btnSettings) btnSettings.addEventListener('click', function () {
        fillStyleForm();
        styleModal.show();
    });

    // глобальный выключатель мониторинга (галочка есть только у редакторов)
    var monChkEl = document.getElementById('maps-monitoring');
    if (monChkEl) monChkEl.addEventListener('change', function () {
        var on = this.checked;
        post({ action: 'save_monitoring', enabled: on ? 1 : 0 }, function () {
            setStatus(on ? '' : '⏸ Мониторинг остановлен', on ? '' : 'err');
            loadData();
        });
    });

    document.getElementById('maps-set-save').addEventListener('click', function () {
        var style = {
            selection: {
                color: document.getElementById('maps-style-sel-color').value,
                dash: document.getElementById('maps-style-sel-dash').value,
                padding: document.getElementById('maps-style-sel-pad').value
            },
            block: {
                font_size: document.getElementById('maps-style-blk-font').value,
                header_h: document.getElementById('maps-style-blk-head').value,
                border: document.getElementById('maps-style-blk-border').value,
                header_bg: document.getElementById('maps-style-blk-hbg').value,
                header_text: document.getElementById('maps-style-blk-htext').value
            },
            links: {
                up: document.getElementById('maps-style-lnk-up').value,
                down: document.getElementById('maps-style-lnk-down').value,
                font_size: document.getElementById('maps-style-lnk-font').value,
                wp_size: document.getElementById('maps-style-lnk-wp').value
            },
            icons: {
                neutral: document.getElementById('maps-style-ic-neutral').value,
                up: document.getElementById('maps-style-ic-up').value,
                down: document.getElementById('maps-style-ic-down').value
            },
            background: {
                color: document.getElementById('maps-style-bg-color').value,
                grid: document.getElementById('maps-style-bg-grid').checked ? 1 : 0,
                image: document.getElementById('maps-style-bg-image').value,
                opacity: (parseInt(document.getElementById('maps-style-bg-opacity').value, 10) || 35) / 100
            }
        };
        post({
            action: 'save_settings',
            font_size: document.getElementById('maps-set-font').value,
            icon_size: document.getElementById('maps-set-icon').value,
            icon_img_max: document.getElementById('maps-set-imgmax').value,
            show_model: document.getElementById('maps-set-model').checked ? 1 : 0,
            show_ip: document.getElementById('maps-set-ip').checked ? 1 : 0,
            show_firmware: document.getElementById('maps-set-firmware').checked ? 1 : 0,
            show_uptime: document.getElementById('maps-set-uptime').checked ? 1 : 0,
            show_temp: document.getElementById('maps-set-temp').checked ? 1 : 0,
            show_comment: document.getElementById('maps-set-comment').checked ? 1 : 0
        }, function () {
            post({ action: 'save_style', style: JSON.stringify(style) }, function () {
                ICON_VER = Date.now();
                loadData();
                styleModal.hide();
            });
        });
    });

    // Новый блок: создаём по центру видимой области, открываем на редактирование
    var btnAddGroup = document.getElementById('maps-btn-addgroup');
    if (btnAddGroup) btnAddGroup.addEventListener('click', function () {
        var c = network.getViewPosition();
        post({ action: 'add_group', x: c.x - 150, y: c.y - 100 }, function () {
            loadData(function () {
                // свежесозданный блок — с максимальным id
                var maxId = 0;
                Object.keys(rawGroups).forEach(function (id) {
                    if (parseInt(id, 10) > maxId) maxId = parseInt(id, 10);
                });
                if (maxId) openGroupEdit(maxId);
            });
        });
    });

    function openGroupEdit(gid) {
        var g = rawGroups[gid];
        if (!g) return;
        document.getElementById('maps-group-label').value = g.label;
        document.getElementById('maps-group-save').dataset.id = gid;
        document.getElementById('maps-group-del').dataset.id = gid;
        showForm('maps-form-group');
    }

    // сохранение подписи блока
    document.getElementById('maps-group-save').addEventListener('click', function () {
        post({
            action: 'update_group',
            id: this.dataset.id,
            label: document.getElementById('maps-group-label').value
        }, function () { loadData(); mapsHideForms(); });
    });

    // удаление блока
    document.getElementById('maps-group-del').addEventListener('click', function () {
        if (!confirm('Удалить блок? Устройства на карте останутся.')) return;
        post({ action: 'del_group', id: this.dataset.id }, function () {
            loadData(); mapsHideForms();
        });
    });

    // --------------------------------------------------------
    // Управление картами
    // --------------------------------------------------------
    document.getElementById('maps-map-select').addEventListener('change', function () {
        MAP_ID = parseInt(this.value, 10);
        mapsHideForms();
        loadData();
    });

    var btnAddMap = document.getElementById('maps-btn-addmap');
    if (btnAddMap) btnAddMap.addEventListener('click', function () {
        var name = prompt('Название новой карты:');
        if (!name || !name.trim()) return;
        post({ action: 'add_map', name: name.trim() }, function () {
            MAP_ID = -1;   // выбрать самую свежую карту после загрузки
            loadData();
        });
    });

    var btnRenMap = document.getElementById('maps-btn-renmap');
    if (btnRenMap) btnRenMap.addEventListener('click', function () {
        var sel = document.getElementById('maps-map-select');
        var cur = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
        var name = prompt('Название карты:', cur);
        if (!name || !name.trim()) return;
        post({ action: 'rename_map', id: MAP_ID, name: name.trim() }, function () {
            loadData();
        });
    });

    var btnDelMap = document.getElementById('maps-btn-delmap');
    if (btnDelMap) btnDelMap.addEventListener('click', function () {
        var sel = document.getElementById('maps-map-select');
        var cur = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
        if (!confirm('Удалить карту «' + cur + '» со всеми узлами, линками и блоками?')) return;
        post({ action: 'del_map', id: MAP_ID }, function () {
            MAP_ID = 0;    // после удаления — первая доступная карта
            loadData();
        });
    });

    // --------------------------------------------------------
    // Классическое перетаскивание блоков:
    // тащим за шапку/рамку — перемещение, за правый нижний угол — размер
    // --------------------------------------------------------
    var mapsCanvasEl = document.getElementById('maps-canvas');
    var grpDrag = null;   // { mode: 'move'|'resize', id, dx, dy }
    var GRIP = 18;        // зона угла для растягивания

    // попадание точки (canvas-координаты) в блок; верхние блоки в приоритете.
    // перемещение — только за белую шапку, растягивание — за правый нижний угол;
    // внутренность блока мышь «проваливается» к устройствам
    function groupHit(cp) {
        var ids = Object.keys(rawGroups);
        for (var i = ids.length - 1; i >= 0; i--) {
            var g = rawGroups[ids[i]];
            if (cp.x < g.x || cp.x > g.x + g.w || cp.y < g.y || cp.y > g.y + g.h) continue;
            if (CAN_EDIT && !MAP_LOCK && cp.x >= g.x + g.w - GRIP && cp.y >= g.y + g.h - GRIP) {
                return { id: ids[i], mode: 'resize' };
            }
            if (cp.y <= g.y + STYLE.block.header_h) {
                return { id: ids[i], mode: 'move' };
            }
        }
        return null;
    }

    function toCanvas(e) {
        var rect = mapsCanvasEl.getBoundingClientRect();
        return network.DOMtoCanvas({ x: e.clientX - rect.left, y: e.clientY - rect.top });
    }

    mapsCanvasEl.addEventListener('mousedown', function (e) {
        if (!CAN_EDIT || MAP_LOCK || e.button !== 0) return;
        var hit = groupHit(toCanvas(e));
        if (!hit) return;
        var g = rawGroups[hit.id];
        grpDrag = { mode: hit.mode, id: hit.id, dx: 0, dy: 0 };
        if (hit.mode === 'move') {
            var cp = toCanvas(e);
            grpDrag.dx = cp.x - g.x;
            grpDrag.dy = cp.y - g.y;
        }
        // блокируем панорамирование карты на время перетаскивания блока
        network.setOptions({ interaction: { dragView: false } });
    });

    mapsCanvasEl.addEventListener('mousemove', function (e) {
        var cp = toCanvas(e);
        if (!grpDrag) {
            // курсор-подсказка над блоком
            if (CAN_EDIT && !MAP_LOCK) {
                var hit = groupHit(cp);
                mapsCanvasEl.style.cursor = hit ? (hit.mode === 'resize' ? 'nwse-resize' : 'move') : '';
            }
            return;
        }
        var g = rawGroups[grpDrag.id];
        if (!g) { grpDrag = null; return; }
        if (grpDrag.mode === 'move') {
            g.x = cp.x - grpDrag.dx;
            g.y = cp.y - grpDrag.dy;
        } else {
            g.w = Math.max(80, cp.x - g.x);
            g.h = Math.max(50, cp.y - g.y);
        }
        network.redraw();
    });

    window.addEventListener('mouseup', function () {
        if (!grpDrag) return;
        var g = rawGroups[grpDrag.id];
        if (g) {
            post({ action: 'geom_group', id: grpDrag.id, x: g.x, y: g.y, w: g.w, h: g.h });
            grpDragEndedAt = Date.now();
        }
        grpDrag = null;
        network.setOptions({ interaction: { dragView: true } });
    });

    // клик по блоку (не по устройству) — редактирование блока;
    // игнорируем клик сразу после перетаскивания
    var grpDragEndedAt = 0;
    network.on('click', function (params) {
        if (!CAN_EDIT || params.nodes.length || params.edges.length) return;
        if (Date.now() - grpDragEndedAt < 250) return;
        var hit = groupHit(params.pointer.canvas);
        if (hit) openGroupEdit(hit.id);
    });

    // Новый линк: если выбраны 2 узла — создаём сразу и открываем на редактирование.
    // Иначе — форма с двумя списками узлов.
    var btnAddLink = document.getElementById('maps-btn-addlink');
    if (btnAddLink) btnAddLink.addEventListener('click', function () {
        var sel = network.getSelection();

        if (sel.nodes.length === 2) {
            var a = sel.nodes[0], b = sel.nodes[1];
            post({ action: 'add_link', src_node: a, dst_node: b }, function () {
                loadData(function () {
                    // находим свежесозданный линк между этой парой и открываем его
                    var created = null;
                    Object.keys(rawLinks).forEach(function (id) {
                        var l = rawLinks[id];
                        if ((l.src == a && l.dst == b) || (l.src == b && l.dst == a)) created = l;
                    });
                    if (created) {
                        network.setSelection({ nodes: [], edges: [created.id] });
                        openLinkEdit(created);
                    }
                });
            });
            return;
        }

        var preSrc = sel.nodes.length === 1 ? sel.nodes[0] : null;
        fillLinkNodeSelects(preSrc, null);
        // подсказки портов под уже выбранные узлы (если есть)
        fillPortSelect(document.getElementById('maps-link-src').value, 'maps-link-srcport', '');
        fillPortSelect(document.getElementById('maps-link-dst').value, 'maps-link-dstport', '');

        document.getElementById('maps-link-title').textContent = 'Новый линк';
        document.getElementById('maps-link-selects').classList.remove('d-none');
        document.getElementById('maps-link-speed').value = '';
        document.getElementById('maps-link-color').value = '';
        document.getElementById('maps-link-line').value = 'smooth';
        document.getElementById('maps-link-pointwrap').classList.add('d-none');
        document.getElementById('maps-link-label').value = '';
        document.getElementById('maps-link-save').dataset.mode = 'add';
        document.getElementById('maps-link-del').classList.add('d-none');
        document.getElementById('maps-link-swap').classList.add('d-none');
        showForm('maps-form-link');
        setStatus('', '');
    });

    // удаление узла — из его формы редактирования
    document.getElementById('maps-nodeedit-del').addEventListener('click', function () {
        if (!confirm('Удалить узел и все его линки?')) return;
        post({ action: 'del_node', id: this.dataset.id }, function () {
            loadData(); mapsHideForms();
        });
    });

    // удаление линка — из его формы редактирования
    document.getElementById('maps-link-del').addEventListener('click', function () {
        if (!confirm('Удалить линк?')) return;
        post({ action: 'del_link', id: document.getElementById('maps-link-save').dataset.id }, function () {
            loadData(); mapsHideForms();
        });
    });

    // удаление выбранной точки излома — из формы линка
    document.getElementById('maps-link-delpoint').addEventListener('click', function () {
        var sel = network.getSelection();
        var nid = sel.nodes.length ? sel.nodes[0] : null;
        if (!nid || typeof nid !== 'string' || nid.indexOf('wp') !== 0) {
            setStatus('⚠️ Сначала выберите точку излома на карте', 'err');
            return;
        }
        post({ action: 'del_point', id: nid.substring(2) }, function () {
            loadData();
        });
    });

    // сохранение нового узла: ставим в центр ТЕКУЩЕГО вида (не в 0,0 —
    // иначе узел терялся в углу за пределами экрана), форму после add чистим
    document.getElementById('maps-node-save').addEventListener('click', function () {
        var pos = network ? network.getViewPosition() : { x: 0, y: 0 };
        post({
            action: 'add_node',
            hardware_id: document.getElementById('maps-node-hw').value,
            label: document.getElementById('maps-node-label').value,
            role: document.getElementById('maps-node-role').value,
            x: Math.round(pos.x),
            y: Math.round(pos.y)
        }, function () {
            document.getElementById('maps-node-label').value = '';
            document.getElementById('maps-node-role').value = 'other';
            document.getElementById('maps-node-hw').value = 0;
            loadData();
            mapsHideForms();
        });
    });

    // поле «Порт» — только для TCP-проверки
    function updatePortVisibility() {
        var isTcp = document.getElementById('maps-nodeedit-check').value === 'tcp';
        document.getElementById('maps-nodeedit-port').disabled = !isTcp;
    }
    document.getElementById('maps-nodeedit-check').addEventListener('change', updatePortVisibility);
    updatePortVisibility();

    // сохранение правок узла
    document.getElementById('maps-nodeedit-save').addEventListener('click', function () {
        post({
            action: 'update_node',
            id: this.dataset.id,
            label: document.getElementById('maps-nodeedit-label').value,
            comment: document.getElementById('maps-nodeedit-comment').value,
            role: document.getElementById('maps-nodeedit-role').value,
            icon: document.getElementById('maps-nodeedit-icon').value,
            check_type: document.getElementById('maps-nodeedit-check').value,
            check_port: document.getElementById('maps-nodeedit-check').value === 'tcp'
                        ? document.getElementById('maps-nodeedit-port').value : ''
        }, function () { ICON_VER = Date.now(); loadData(); mapsHideForms(); });
    });

    // сохранение линка
    document.getElementById('maps-link-save').addEventListener('click', function () {
        var p = {
            src_port: document.getElementById('maps-link-srcport').value,
            dst_port: document.getElementById('maps-link-dstport').value,
            speed: document.getElementById('maps-link-speed').value,
            color: document.getElementById('maps-link-color').value,
            line: document.getElementById('maps-link-line').value,
            label: document.getElementById('maps-link-label').value
        };
        if (this.dataset.mode === 'add') {
            p.action = 'add_link';
            p.src_node = document.getElementById('maps-link-src').value;
            p.dst_node = document.getElementById('maps-link-dst').value;
            // для ортогональной линии передаём середину — сервер поставит первую точку излома
            if (p.line === 'elbow' && p.src_node && p.dst_node) {
                var pos = network.getPositions([parseInt(p.src_node, 10), parseInt(p.dst_node, 10)]);
                var a = pos[p.src_node], b = pos[p.dst_node];
                if (a && b) {
                    p.px = (a.x + b.x) / 2;
                    p.py = (a.y + b.y) / 2;
                }
            }
        } else {
            p.action = 'update_link';
            p.id = this.dataset.id;
        }
        post(p, function () { loadData(); mapsHideForms(); });
    });

    // поменять направление линка: Узел 1 ↔ Узел 2 (и их порты)
    document.getElementById('maps-link-swap').addEventListener('click', function () {
        var linkId = this.dataset.id;
        post({ action: 'swap_link', id: linkId }, function () {
            loadData(function () {
                if (rawLinks[linkId]) {
                    network.setSelection({ nodes: [], edges: [parseInt(linkId, 10)] });
                    openLinkEdit(rawLinks[linkId]);
                }
            });
        });
    });

    // добавить точку излома — в середину сегмента от последней точки до узла 2
    document.getElementById('maps-link-addpoint').addEventListener('click', function () {
        var linkId = document.getElementById('maps-link-save').dataset.id;
        var l = rawLinks[linkId];
        if (!l) return;

        var ids = [l.src, l.dst];
        l.points.forEach(function (pt) { ids.push('wp' + pt.id); });
        var pos = network.getPositions(ids);

        var prev = pos[l.src];
        if (l.points.length) prev = pos['wp' + l.points[l.points.length - 1].id];
        var last = pos[l.dst];
        if (!prev || !last) return;

        post({
            action: 'add_point',
            link_id: linkId,
            x: (prev.x + last.x) / 2,
            y: (prev.y + last.y) / 2
        }, function () { loadData(); });
    });

    loadData();

    // автообновление: период из общих настроек (settings.maps_refresh_sec, по умолчанию 60 сек);
    // не трогаем карту, пока открыта форма редактирования
    var REFRESH_SEC = 60;
    var LAST_LOAD = Date.now();
    setInterval(function () {
        if (Date.now() - LAST_LOAD < REFRESH_SEC * 1000) return;
        var editing = ['maps-form-node', 'maps-form-nodeedit', 'maps-form-link', 'maps-form-group']
            .some(function (id) { return !document.getElementById(id).classList.contains('d-none'); });
        if (!editing) loadData();
    }, 5000);
})();
</script>
