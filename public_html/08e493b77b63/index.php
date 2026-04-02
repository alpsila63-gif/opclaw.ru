<?php
/**
 * CRM — Альпинисты Сочи
 * Скрытая панель управления заявками
 * Размещается на opclaw.ru/08e493b77b63/
 */

session_start();

define('LEADS_FILE', '/var/www/leads_data/leads.json');
define('TG_CONFIG',  '/var/www/leads_data/tg_config.json');
define('ADMIN_PASS', '$2y$10$' . 'Kv3mN9pQwRtYuIoAsD5fGhJkLzXcVbNm'); // заменяется при первом сохранении

// --- Пароль по умолчанию: alpsila2026 ---
// hash('SHA-256'): можно сменить через форму настроек
$defaultPassHash = '$2y$10$UdQzY1Wm3Kx8Pv2Tn0R4eNjS6oF9gBcHqIlEm7aD5kVwXyZ.uLpO'; // alpsila2026

$passFile = '/var/www/leads_data/.adminpass';
if (file_exists($passFile)) {
    $passHash = trim(file_get_contents($passFile));
} else {
    $passHash = $defaultPassHash;
}

$error = '';
$success = '';

// --- Логин ---
if (isset($_POST['login_password'])) {
    if (password_verify($_POST['login_password'], $passHash)) {
        $_SESSION['crm_auth'] = true;
        $_SESSION['crm_ts']   = time();
    } else {
        $error = 'Неверный пароль';
    }
}

// --- Выход ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

// --- Сессия истекает через 2 часа ---
if (!empty($_SESSION['crm_auth']) && (time() - ($_SESSION['crm_ts'] ?? 0)) > 7200) {
    session_destroy();
    header('Location: ./');
    exit;
}

$authed = !empty($_SESSION['crm_auth']);

// --- Смена пароля ---
if ($authed && isset($_POST['new_password']) && strlen($_POST['new_password']) >= 6) {
    $newHash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    file_put_contents($passFile, $newHash, LOCK_EX);
    $passHash = $newHash;
    $success = 'Пароль успешно изменён';
}

// --- Настройка Telegram ---
if ($authed && isset($_POST['tg_token'])) {
    $tgData = [
        'token'   => trim($_POST['tg_token'] ?? ''),
        'chat_id' => trim($_POST['tg_chat_id'] ?? ''),
    ];
    file_put_contents(TG_CONFIG, json_encode($tgData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    $success = 'Настройки Telegram сохранены';
}

// --- Смена статуса заявки ---
if ($authed && isset($_POST['set_status']) && isset($_POST['lead_id'])) {
    $leads = loadLeads();
    foreach ($leads as &$l) {
        if ($l['id'] === $_POST['lead_id']) {
            $l['status'] = in_array($_POST['set_status'], ['new', 'in_work', 'done', 'cancelled'])
                ? $_POST['set_status'] : 'new';
        }
    }
    saveLeads($leads);
    header('Location: ./?tab=leads');
    exit;
}

// --- Удаление заявки ---
if ($authed && isset($_POST['delete_lead'])) {
    $leads = loadLeads();
    $leads = array_filter($leads, fn($l) => $l['id'] !== $_POST['delete_lead']);
    saveLeads(array_values($leads));
    header('Location: ./?tab=leads');
    exit;
}

// --- Функции ---
function loadLeads(): array {
    if (!file_exists(LEADS_FILE)) return [];
    $data = @json_decode(file_get_contents(LEADS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveLeads(array $leads): void {
    file_put_contents(LEADS_FILE, json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function statusLabel(string $s): string {
    return match($s) {
        'new'       => '<span class="badge badge-new">Новая</span>',
        'in_work'   => '<span class="badge badge-work">В работе</span>',
        'done'      => '<span class="badge badge-done">Готово</span>',
        'cancelled' => '<span class="badge badge-cancel">Отменена</span>',
        default     => '<span class="badge badge-new">Новая</span>',
    };
}

$tab = $_GET['tab'] ?? 'leads';
$leads = $authed ? loadLeads() : [];
$tgConfig = file_exists(TG_CONFIG) ? json_decode(file_get_contents(TG_CONFIG), true) : [];

// Статистика
$countNew    = count(array_filter($leads, fn($l) => ($l['status'] ?? 'new') === 'new'));
$countWork   = count(array_filter($leads, fn($l) => ($l['status'] ?? '') === 'in_work'));
$countDone   = count(array_filter($leads, fn($l) => ($l['status'] ?? '') === 'done'));

// Фильтр
$filterStatus = $_GET['status'] ?? 'all';
$filteredLeads = $filterStatus === 'all'
    ? $leads
    : array_filter($leads, fn($l) => ($l['status'] ?? 'new') === $filterStatus);

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>CRM — Альпинисты Сочи</title>
<style>
:root {
  --bg: #0f1117;
  --surface: #1a1d27;
  --border: #2a2d3a;
  --text: #e2e8f0;
  --muted: #64748b;
  --accent: #3b82f6;
  --green: #22c55e;
  --red: #ef4444;
  --orange: #f59e0b;
  --purple: #a855f7;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 14px; min-height: 100vh; }

/* Login */
.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 40px; width: 360px; }
.login-box h1 { font-size: 20px; margin-bottom: 6px; }
.login-box p { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
.form-group input { width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; color: var(--text); font-size: 14px; outline: none; }
.form-group input:focus { border-color: var(--accent); }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; font-weight: 500; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-danger  { background: var(--red); color: #fff; font-size: 12px; padding: 5px 10px; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
.alert-error { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
.alert-success { background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.3); color: #86efac; }

/* Layout */
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: var(--surface); border-right: 1px solid var(--border); padding: 20px 0; flex-shrink: 0; display: flex; flex-direction: column; }
.sidebar-logo { padding: 0 20px 20px; border-bottom: 1px solid var(--border); margin-bottom: 10px; }
.sidebar-logo .logo-text { font-size: 15px; font-weight: 700; }
.sidebar-logo .logo-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }
.sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: var(--muted); text-decoration: none; font-size: 13px; transition: all .15s; }
.sidebar-nav a:hover, .sidebar-nav a.active { color: var(--text); background: rgba(255,255,255,.05); }
.sidebar-nav a.active { border-right: 2px solid var(--accent); }
.sidebar-bottom { margin-top: auto; padding: 20px; border-top: 1px solid var(--border); }
.sidebar-bottom a { color: var(--muted); font-size: 12px; text-decoration: none; }

.main { flex: 1; padding: 28px 32px; overflow-x: auto; }
.page-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.page-sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }

/* Stats */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px; }
.stat-card .num { font-size: 28px; font-weight: 700; }
.stat-card .lbl { font-size: 12px; color: var(--muted); margin-top: 4px; }
.stat-new  { border-top: 3px solid var(--accent); }
.stat-work { border-top: 3px solid var(--orange); }
.stat-done { border-top: 3px solid var(--green); }
.stat-all  { border-top: 3px solid var(--purple); }

/* Table */
.filter-bar { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-btn { padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); cursor: pointer; font-size: 12px; text-decoration: none; transition: all .15s; }
.filter-btn:hover, .filter-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

.table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { background: rgba(255,255,255,.03); padding: 10px 14px; text-align: left; font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: top; font-size: 13px; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,.02); }

.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-new    { background: rgba(59,130,246,.2); color: #93c5fd; }
.badge-work   { background: rgba(245,158,11,.2); color: #fcd34d; }
.badge-done   { background: rgba(34,197,94,.2);  color: #86efac; }
.badge-cancel { background: rgba(100,116,139,.2); color: #94a3b8; }

.actions { display: flex; gap: 6px; flex-wrap: wrap; }
select.status-sel { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 4px 8px; border-radius: 6px; font-size: 12px; cursor: pointer; }

/* Settings */
.settings-section { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 24px; margin-bottom: 20px; max-width: 560px; }
.settings-section h3 { font-size: 15px; margin-bottom: 16px; }
.empty { text-align: center; padding: 48px; color: var(--muted); }
.empty .icon { font-size: 40px; margin-bottom: 12px; }

.phone-link { color: var(--accent); text-decoration: none; font-weight: 600; }
.name-cell { font-weight: 600; }
.dt-cell { color: var(--muted); font-size: 12px; white-space: nowrap; }
.page-cell { color: var(--muted); font-size: 11px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
</head>
<body>

<?php if (!$authed): ?>
<!-- === LOGIN === -->
<div class="login-wrap">
  <div class="login-box">
    <h1>🔒 CRM-панель</h1>
    <p>Управление заявками Альпинисты Сочи</p>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif ?>
    <form method="post">
      <div class="form-group">
        <label>Пароль</label>
        <input type="password" name="login_password" autofocus required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Войти</button>
    </form>
    <p style="margin-top:16px;color:var(--muted);font-size:12px">По умолчанию: <b>alpsila2026</b> (сменить в настройках)</p>
  </div>
</div>

<?php else: ?>
<!-- === DASHBOARD === -->
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-text">Альпинисты Сочи</div>
      <div class="logo-sub">CRM-панель</div>
    </div>
    <nav class="sidebar-nav">
      <a href="./?tab=leads" class="<?= $tab==='leads' ? 'active' : '' ?>">📋 Заявки
        <?php if ($countNew > 0): ?><span style="margin-left:auto;background:var(--accent);color:#fff;border-radius:20px;padding:1px 7px;font-size:11px"><?= $countNew ?></span><?php endif ?>
      </a>
      <a href="./?tab=settings" class="<?= $tab==='settings' ? 'active' : '' ?>">⚙️ Настройки</a>
    </nav>
    <div class="sidebar-bottom">
      <a href="./?logout=1">← Выйти</a>
    </div>
  </aside>

  <main class="main">
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif ?>

    <?php if ($tab === 'leads'): ?>
    <!-- === ЗАЯВКИ === -->
    <div class="page-title">Заявки</div>
    <div class="page-sub">Все обращения с сайта sochi-alp.ru</div>

    <div class="stats-row">
      <div class="stat-card stat-new">
        <div class="num"><?= $countNew ?></div>
        <div class="lbl">Новые</div>
      </div>
      <div class="stat-card stat-work">
        <div class="num"><?= $countWork ?></div>
        <div class="lbl">В работе</div>
      </div>
      <div class="stat-card stat-done">
        <div class="num"><?= $countDone ?></div>
        <div class="lbl">Завершены</div>
      </div>
      <div class="stat-card stat-all">
        <div class="num"><?= count($leads) ?></div>
        <div class="lbl">Всего</div>
      </div>
    </div>

    <div class="filter-bar">
      <a href="./?tab=leads&status=all"       class="filter-btn <?= $filterStatus==='all'       ? 'active' : '' ?>">Все</a>
      <a href="./?tab=leads&status=new"       class="filter-btn <?= $filterStatus==='new'       ? 'active' : '' ?>">Новые</a>
      <a href="./?tab=leads&status=in_work"   class="filter-btn <?= $filterStatus==='in_work'   ? 'active' : '' ?>">В работе</a>
      <a href="./?tab=leads&status=done"      class="filter-btn <?= $filterStatus==='done'      ? 'active' : '' ?>">Готово</a>
      <a href="./?tab=leads&status=cancelled" class="filter-btn <?= $filterStatus==='cancelled' ? 'active' : '' ?>">Отменены</a>
    </div>

    <?php if (empty($filteredLeads)): ?>
    <div class="empty"><div class="icon">📭</div><div>Заявок пока нет</div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Дата</th>
            <th>Имя</th>
            <th>Телефон</th>
            <th>Услуга</th>
            <th>Сообщение</th>
            <th>Источник</th>
            <th>Статус</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($filteredLeads as $l): ?>
          <tr>
            <td class="dt-cell"><?= htmlspecialchars($l['dt'] ?? '') ?></td>
            <td class="name-cell"><?= htmlspecialchars($l['name'] ?? '—') ?></td>
            <td><a href="tel:<?= htmlspecialchars($l['phone'] ?? '') ?>" class="phone-link"><?= htmlspecialchars($l['phone'] ?? '—') ?></a></td>
            <td><?= htmlspecialchars($l['service'] ?? '—') ?></td>
            <td style="max-width:200px"><?= htmlspecialchars(mb_substr($l['message'] ?? '', 0, 80)) ?><?= mb_strlen($l['message'] ?? '') > 80 ? '…' : '' ?></td>
            <td class="page-cell" title="<?= htmlspecialchars($l['page'] ?? '') ?>"><?= htmlspecialchars(basename(parse_url($l['page'] ?? '', PHP_URL_PATH) ?: '/')) ?></td>
            <td><?= statusLabel($l['status'] ?? 'new') ?></td>
            <td>
              <div class="actions">
                <form method="post" style="display:inline">
                  <input type="hidden" name="lead_id" value="<?= htmlspecialchars($l['id']) ?>">
                  <select name="set_status" class="status-sel" onchange="this.form.submit()">
                    <option value="new"       <?= ($l['status']??'new')==='new'       ? 'selected' : '' ?>>Новая</option>
                    <option value="in_work"   <?= ($l['status']??'')==='in_work'      ? 'selected' : '' ?>>В работе</option>
                    <option value="done"      <?= ($l['status']??'')==='done'         ? 'selected' : '' ?>>Готово</option>
                    <option value="cancelled" <?= ($l['status']??'')==='cancelled'    ? 'selected' : '' ?>>Отменена</option>
                  </select>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить заявку?')">
                  <input type="hidden" name="delete_lead" value="<?= htmlspecialchars($l['id']) ?>">
                  <button type="submit" class="btn btn-danger">✕</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <?php elseif ($tab === 'settings'): ?>
    <!-- === НАСТРОЙКИ === -->
    <div class="page-title">Настройки</div>
    <div class="page-sub">Telegram-уведомления и безопасность</div>

    <div class="settings-section">
      <h3>📱 Telegram-уведомления</h3>
      <p style="color:var(--muted);font-size:12px;margin-bottom:16px">При новой заявке придёт сообщение в указанный чат.<br>Создайте бота через <b>@BotFather</b>, получите chat_id через <b>@userinfobot</b></p>
      <form method="post">
        <div class="form-group">
          <label>Bot Token</label>
          <input type="text" name="tg_token" value="<?= htmlspecialchars($tgConfig['token'] ?? '') ?>" placeholder="123456789:AAFxxxxxxxxxxxxxxxx">
        </div>
        <div class="form-group">
          <label>Chat ID</label>
          <input type="text" name="tg_chat_id" value="<?= htmlspecialchars($tgConfig['chat_id'] ?? '') ?>" placeholder="-100xxxxxxxxxx или ваш ID">
        </div>
        <button type="submit" class="btn btn-primary">Сохранить</button>
      </form>
    </div>

    <div class="settings-section">
      <h3>🔒 Смена пароля</h3>
      <form method="post">
        <div class="form-group">
          <label>Новый пароль (минимум 6 символов)</label>
          <input type="password" name="new_password" placeholder="Новый пароль" minlength="6">
        </div>
        <button type="submit" class="btn btn-primary">Сменить пароль</button>
      </form>
    </div>

    <div class="settings-section">
      <h3>ℹ️ Информация</h3>
      <p style="color:var(--muted);font-size:13px;line-height:1.8">
        URL панели: <b>https://opclaw.ru/08e493b77b63/</b><br>
        Файл заявок: <b>/var/www/leads_data/leads.json</b><br>
        PHP-обработчик: <b>https://sochi-alp.ru/scripts/send.php</b>
      </p>
    </div>

    <?php endif ?>
  </main>
</div>
<?php endif ?>
</body>
</html>
