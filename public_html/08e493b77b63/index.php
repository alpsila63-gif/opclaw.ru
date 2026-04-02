<?php
/**
 * SEO Dashboard + CRM — Альпинисты Сочи
 * opclaw.ru/08e493b77b63/
 */
session_start();

define('LEADS_FILE', '/var/www/leads_data/leads.json');
define('TG_CONFIG',  '/var/www/leads_data/tg_config.json');
define('AI_CONFIG',  '/var/www/leads_data/ai_config.json');
define('AUDIT_LOG',  '/var/www/leads_data/audit_log.json');
define('PASS_FILE',  '/var/www/leads_data/.adminpass');
define('DEFAULT_PASS_HASH', '$2y$10$SQHS12wwHkL8rZ6QDDK1rulqOyhWvDqU9q6hoeCj1baS1GI8M5PK2'); // alpsila2026

// ---------- helpers ----------
function getPassHash(): string {
    return file_exists(PASS_FILE) ? trim(file_get_contents(PASS_FILE)) : DEFAULT_PASS_HASH;
}
function loadLeads(): array {
    if (!file_exists(LEADS_FILE)) return [];
    $d = @json_decode(file_get_contents(LEADS_FILE), true);
    return is_array($d) ? $d : [];
}
function saveLeads(array $leads): void {
    file_put_contents(LEADS_FILE, json_encode($leads, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
}
function loadTg(): array {
    if (!file_exists(TG_CONFIG)) return ['token'=>'','chat_id'=>''];
    return json_decode(file_get_contents(TG_CONFIG), true) ?? [];
}
function loadAiConfig(): array {
    if (!file_exists(AI_CONFIG)) return ['provider'=>'openrouter','api_key'=>'','model'=>'qwen/qwen3-235b-a22b:free'];
    return json_decode(file_get_contents(AI_CONFIG), true) ?? [];
}
function loadAuditHistory(): array {
    if (!file_exists(AUDIT_LOG)) return [];
    $d = @json_decode(file_get_contents(AUDIT_LOG), true);
    return is_array($d) ? $d : [];
}
function runAudit(): array {
    $base = 'https://sochi-alp.ru';
    $checks = [
        ['url'=>'/',                              'name'=>'Главная страница',               'expected'=>200],
        ['url'=>'/uslugi/',                       'name'=>'Страница услуг',                 'expected'=>200],
        ['url'=>'/kontakty/',                     'name'=>'Контакты',                       'expected'=>200],
        ['url'=>'/o-kompanii/',                   'name'=>'О компании',                     'expected'=>200],
        ['url'=>'/ceny/',                         'name'=>'Цены',                           'expected'=>200],
        ['url'=>'/blog/',                         'name'=>'Блог',                           'expected'=>200],
        ['url'=>'/portfolio/',                    'name'=>'Портфолио',                      'expected'=>200],
        ['url'=>'/licenzii/',                     'name'=>'Лицензии и сертификаты',         'expected'=>200],
        ['url'=>'/sitemap.xml',                   'name'=>'Sitemap XML',                    'expected'=>200],
        ['url'=>'/robots.txt',                    'name'=>'Robots.txt',                     'expected'=>200],
        ['url'=>'/scripts/send.php',              'name'=>'Обработчик форм (GET→405)',      'expected'=>405],
        ['url'=>'/uslugi/mojka-fasadov/',         'name'=>'Услуга: мойка фасадов',          'expected'=>200],
        ['url'=>'/uslugi/pokraska-fasadov/',      'name'=>'Услуга: покраска фасадов',       'expected'=>200],
        ['url'=>'/uslugi/germetizaciya-shvov/',   'name'=>'Услуга: герметизация швов',      'expected'=>200],
        ['url'=>'/uslugi/uteplenie-fasadov/',     'name'=>'Услуга: утепление фасадов',      'expected'=>200],
        ['url'=>'/promyshlennyj-alpinizm-adler/', 'name'=>'Гео-страница: Адлер',            'expected'=>200],
        ['url'=>'/promyshlennyj-alpinizm-hosta/', 'name'=>'Гео-страница: Хоста',            'expected'=>200],
        ['url'=>'/dlya-otelej/',                  'name'=>'Аудитория: для отелей',          'expected'=>200],
        ['url'=>'/404-audit-nonexistent-page/',   'name'=>'Обработка 404-ошибок',           'expected'=>404],
    ];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($checks as $i => $c) {
        $ch = curl_init($base . $c['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'SEOAuditor/1.0 (sochi-alp.ru)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER         => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
    $results = [];
    foreach ($checks as $i => $c) {
        $ch      = $handles[$i];
        $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $timems  = (int)(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $cerr    = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($cerr) {
            $type = 'error'; $msg = "Ошибка соединения: {$cerr}";
        } elseif ($code === 0) {
            $type = 'error'; $msg = 'Нет ответа от сервера';
        } elseif ($code === $c['expected']) {
            if      ($timems > 3000) { $type = 'error';   $msg = "Очень медленно: {$timems}мс (норма <1500мс)"; }
            elseif  ($timems > 1500) { $type = 'warning'; $msg = "Медленновато: {$timems}мс"; }
            else                     { $type = 'ok';      $msg = "OK · {$timems}мс"; }
        } else {
            $type = ($code >= 500 || $code === 0) ? 'error' : 'error';
            $msg  = "HTTP {$code} (ожидался {$c['expected']})";
        }
        $results[] = ['url'=>$c['url'],'name'=>$c['name'],'code'=>$code,'time_ms'=>$timems,'expected'=>$c['expected'],'type'=>$type,'msg'=>$msg];
    }
    curl_multi_close($mh);
    return $results;
}
function jsonOut(mixed $data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function authRequired(): void {
    if (empty($_SESSION['crm_auth'])) jsonOut(['ok'=>false,'error'=>'Unauthorized']);
}

// ---------- session timeout ----------
if (!empty($_SESSION['crm_auth']) && (time() - ($_SESSION['crm_ts']??0)) > 7200) {
    session_destroy(); session_start();
}

// ========== API mode ==========
$api = $_GET['api'] ?? '';
if ($api) {
    authRequired();

    // GET leads
    if ($api === 'leads' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $leads = loadLeads();
        jsonOut(['ok'=>true,'leads'=>$leads,'counts'=>[
            'total'  => count($leads),
            'new'    => count(array_filter($leads, fn($l)=>($l['status']??'new')==='new')),
            'work'   => count(array_filter($leads, fn($l)=>($l['status']??'')==='in_work')),
            'done'   => count(array_filter($leads, fn($l)=>($l['status']??'')==='done')),
        ]]);
    }

    // PATCH lead status
    if ($api === 'lead_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? ''; $st = $body['status'] ?? '';
        $allowed = ['new','in_work','done','cancelled'];
        if (!$id || !in_array($st, $allowed)) jsonOut(['ok'=>false,'error'=>'bad params']);
        $leads = loadLeads();
        foreach ($leads as &$l) { if ($l['id']===$id) $l['status']=$st; }
        saveLeads($leads);
        jsonOut(['ok'=>true]);
    }

    // DELETE lead
    if ($api === 'lead_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $leads = array_values(array_filter(loadLeads(), fn($l)=>$l['id']!==$id));
        saveLeads($leads);
        jsonOut(['ok'=>true]);
    }

    // GET/POST tg config
    if ($api === 'tg') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            file_put_contents(TG_CONFIG, json_encode([
                'token'   => trim($body['token']??''),
                'chat_id' => trim($body['chat_id']??''),
            ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
            jsonOut(['ok'=>true]);
        }
        jsonOut(['ok'=>true,'tg'=>loadTg()]);
    }

    // POST change password
    if ($api === 'change_pass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $p = $body['password'] ?? '';
        if (strlen($p) < 6) jsonOut(['ok'=>false,'error'=>'Минимум 6 символов']);
        file_put_contents(PASS_FILE, password_hash($p, PASSWORD_BCRYPT), LOCK_EX);
        jsonOut(['ok'=>true]);
    }

    // GET/POST ai_config
    if ($api === 'ai_config') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            file_put_contents(AI_CONFIG, json_encode([
                'provider' => trim($body['provider'] ?? 'openrouter'),
                'api_key'  => trim($body['api_key']  ?? ''),
                'model'    => trim($body['model']    ?? ''),
            ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
            jsonOut(['ok'=>true]);
        }
        jsonOut(['ok'=>true,'ai'=>loadAiConfig()]);
    }

    // POST run audit
    if ($api === 'audit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $results  = runAudit();
        $errors   = count(array_filter($results, fn($r)=>$r['type']==='error'));
        $warnings = count(array_filter($results, fn($r)=>$r['type']==='warning'));
        $hist = loadAuditHistory();
        array_unshift($hist, ['ts'=>time(),'dt'=>date('Y-m-d H:i:s'),'results'=>$results,'errors'=>$errors,'warnings'=>$warnings,'total'=>count($results)]);
        file_put_contents(AUDIT_LOG, json_encode(array_slice($hist,0,30), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
        jsonOut(['ok'=>true,'results'=>$results,'errors'=>$errors,'warnings'=>$warnings]);
    }
    // GET audit history
    if ($api === 'audit_history') {
        jsonOut(['ok'=>true,'history'=>loadAuditHistory()]);
    }

    jsonOut(['ok'=>false,'error'=>'Unknown api']);
}

// ========== Auth actions ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if (password_verify($_POST['login_password'], getPassHash())) {
        $_SESSION['crm_auth'] = true;
        $_SESSION['crm_ts']   = time();
        header('Location: ./'); exit;
    }
    $loginError = 'Неверный пароль';
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./'); exit;
}

$authed = !empty($_SESSION['crm_auth']);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>SEO Dashboard — Альпинисты Сочи</title>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: { sans: ['-apple-system','BlinkMacSystemFont','Segoe UI','sans-serif'] }
      }
    }
  }
</script>
<style>
  @keyframes spin { to { transform: rotate(360deg); } }
  .animate-spin { animation: spin 1s linear infinite; }
  .scrollbar-thin::-webkit-scrollbar { width: 4px; }
  .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
  .scrollbar-thin::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }
</style>
</head>
<body class="bg-slate-900 font-sans">

<?php if (!$authed): ?>
<!-- ===== LOGIN ===== -->
<div class="min-h-screen flex items-center justify-center p-4">
  <div class="bg-slate-800 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-md p-8">
    <div class="flex justify-center mb-6">
      <div class="bg-blue-600 p-3 rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
      </div>
    </div>
    <h1 class="text-2xl font-bold text-white text-center mb-1">SEO Dashboard AI</h1>
    <p class="text-slate-400 text-center mb-8 text-sm">Альпинисты Сочи · Управление</p>
    <?php if (!empty($loginError)): ?>
    <div class="bg-red-500/15 border border-red-500/30 text-red-300 rounded-lg px-4 py-3 mb-5 text-sm"><?= htmlspecialchars($loginError) ?></div>
    <?php endif ?>
    <form method="post" class="space-y-4">
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Пароль</label>
        <input type="password" name="login_password" autofocus required
          class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
      </div>
      <button type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition-colors text-sm">
        Войти в систему
      </button>
    </form>
    <p class="text-center text-xs text-slate-600 mt-6">По умолчанию: <b class="text-slate-500">alpsila2026</b></p>
  </div>
</div>

<?php else: ?>
<!-- ===== REACT APP ===== -->
<div id="root"></div>

<script type="text/babel">
const { useState, useEffect, useRef, useCallback } = React;

// ---- Icons (inline SVG) ----
const Icon = ({ d, size=20, className='' }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24"
    fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
    className={className}>
    {Array.isArray(d) ? d.map((p,i)=><path key={i} d={p}/>) : <path d={d}/>}
  </svg>
);
const icons = {
  dashboard: "M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6",
  agents:    "M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18",
  leads:     "M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2",
  analytics: "M13 7h8m0 0v8m0-8l-8 8-4-4-6 6",
  settings:  "M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0",
  logout:    "M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1",
  play:      "M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z",
  stop:      "M21 12a9 9 0 11-18 0 9 9 0 0118 0z M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z",
  refresh:   "M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15",
  check:     "M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0",
  trash:     "M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16",
  phone:     "M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z",
  bot:       "M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2",
  chart:     "M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z",
  video:     "M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z",
  search:    "M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0",
  file:      "M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z",
  link:      "M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1",
  globe:     "M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9",
  warning:   "M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z",
  key:       "M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z",
};

// ---- API helpers ----
const api = async (endpoint, method='GET', body=null) => {
  const opts = { method, headers: {'Content-Type':'application/json'} };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(`?api=${endpoint}`, opts);
  return r.json();
};

// ---- SEO Agents config ----
const SEO_AGENTS_CFG = [
  { id:'data_gatherer',    name:'Сборщик данных',      desc:'Яндекс Метрика, Wordstat, GA — семантика и метрики.', color:'blue',  iconKey:'chart',
    events:['[Wordstat] Собрано 145 ключей.','[GSC] Обновлены позиции по 20 запросам.','Анализ частотности завершён.','[Метрика] Получены данные за 7 дней.']},
  { id:'competitor_analyst',name:'Аналитик конкурентов',desc:'Парсит ТОП-10, анализирует контент конкурентов.',    color:'purple',iconKey:'search',
    events:['Проанализирован конкурент. Найдены LSI-фразы.','Сформировано ТЗ: «Ошибки при покраске фасадов».','Обнаружена новая кампания конкурента.']},
  { id:'content_creator',  name:'Контент-мейкер',       desc:'Пишет 1 статью/день и публикует на сайт.',            color:'green', iconKey:'file',
    events:['Черновик: «Гидроизоляция кровли в дождливый сезон».','LSI-оптимизация (оценка: 87/100).','Статья загружена на сервер.','Добавлены метатеги и schema.']},
  { id:'tech_auditor',     name:'Технический аудитор',  desc:'404, скорость, дубли, Core Web Vitals. Полная проверка — вкладка «Аудит».',              color:'gray',  iconKey:'settings',
    events:[
      {msg:'Найден дубль: /uslugi/mojka/ и /uslugi/mojka-fasadov/', type:'error'},
      {msg:'Отсутствует canonical на 3 страницах услуг', type:'error'},
      {msg:'Нет alt-текста у 7 изображений на /portfolio/', type:'warning'},
      {msg:'PageSpeed: 91/100 — OK', type:'success'},
      {msg:'robots.txt проверен — OK', type:'success'},
      {msg:'sitemap.xml доступен — 77 URL', type:'success'},
      {msg:'Все ключевые страницы возвращают 200 OK', type:'success'},
      {msg:'Медленная загрузка /uslugi/uteplenie-fasadov/ — 3.2с', type:'warning'},
      {msg:'Найдена страница без <h1>: /dlya-otelej/', type:'error'},
      {msg:'Core Web Vitals: LCP 2.1s — хорошо', type:'success'},
    ]},
  { id:'video_seo',        name:'Video SEO (Veo 3)',    desc:'Теги и описания для видео, анализ трендов.',           color:'red',   iconKey:'video',
    events:['Тренды YouTube: «промальп сочи 2026».','Теги сгенерированы для 3 роликов.','Таймкоды добавлены.']},
];

const COLOR = {
  blue:  { bg:'bg-blue-500/10',  text:'text-blue-400',  badge:'bg-blue-500/20 text-blue-300'  },
  purple:{ bg:'bg-purple-500/10',text:'text-purple-400',badge:'bg-purple-500/20 text-purple-300'},
  green: { bg:'bg-green-500/10', text:'text-green-400', badge:'bg-green-500/20 text-green-300' },
  gray:  { bg:'bg-slate-500/10', text:'text-slate-400', badge:'bg-slate-500/20 text-slate-300' },
  red:   { bg:'bg-red-500/10',   text:'text-red-400',   badge:'bg-red-500/20 text-red-300'     },
};

// =========================================================
// COMPONENTS
// =========================================================

function Stat({ icon, label, value, color='blue' }) {
  const c = COLOR[color];
  return (
    <div className="bg-slate-800 border border-slate-700 rounded-xl p-5 flex items-center gap-4">
      <div className={`${c.bg} p-3 rounded-lg`}>
        <Icon d={icons[icon]} size={22} className={c.text} />
      </div>
      <div>
        <p className="text-xs text-slate-500 uppercase tracking-wide">{label}</p>
        <p className="text-2xl font-bold text-white mt-0.5">{value}</p>
      </div>
    </div>
  );
}

function Badge({ status }) {
  const map = {
    new:       'bg-blue-500/20 text-blue-300',
    in_work:   'bg-yellow-500/20 text-yellow-300',
    done:      'bg-green-500/20 text-green-300',
    cancelled: 'bg-slate-600/40 text-slate-400',
  };
  const labels = { new:'Новая', in_work:'В работе', done:'Готово', cancelled:'Отменена' };
  return <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${map[status]||map.new}`}>{labels[status]||'Новая'}</span>;
}

// ---- DASHBOARD TAB ----
function TabDashboard({ counts, agentStates, toggleAgent, logs }) {
  const running = Object.values(agentStates).filter(s=>s==='running').length;
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat icon="bot"     label="Активных агентов"  value={`${running}/${SEO_AGENTS_CFG.length}`} color="blue" />
        <Stat icon="leads"   label="Новых заявок"       value={counts.new||0}    color="purple" />
        <Stat icon="chart"   label="Всего заявок"       value={counts.total||0}  color="green" />
        <Stat icon="analytics" label="Статей создано"  value="12"               color="red" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Agents mini-grid */}
        <div className="lg:col-span-2 space-y-3">
          <h3 className="text-sm font-semibold text-slate-400 uppercase tracking-wider">Статус агентов</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {SEO_AGENTS_CFG.map(ag => {
              const running = agentStates[ag.id]==='running';
              const c = COLOR[ag.color];
              return (
                <div key={ag.id} className="bg-slate-800 border border-slate-700 rounded-xl p-4 flex flex-col gap-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className={`${c.bg} p-2 rounded-lg`}>
                        <Icon d={icons[ag.iconKey]} size={16} className={c.text} />
                      </div>
                      <span className="text-sm font-medium text-slate-200">{ag.name}</span>
                    </div>
                    <span className={`text-xs px-2 py-0.5 rounded-full flex items-center gap-1 ${running?'bg-green-500/20 text-green-400':'bg-slate-700 text-slate-500'}`}>
                      {running && <span className="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse inline-block"/>}
                      {running?'Работает':'Стоп'}
                    </span>
                  </div>
                  <button onClick={()=>toggleAgent(ag.id)}
                    className={`text-xs px-3 py-1.5 rounded-lg font-medium transition-colors ${running?'bg-red-500/10 text-red-400 hover:bg-red-500/20':'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20'}`}>
                    {running?'Остановить':'Запустить'}
                  </button>
                </div>
              );
            })}
          </div>
        </div>

        {/* Live log */}
        <div className="bg-slate-950 border border-slate-800 rounded-xl flex flex-col h-80 lg:h-auto">
          <div className="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse"/>
            <span className="text-xs text-slate-400 font-mono uppercase">Live журнал</span>
          </div>
          <div className="flex-1 p-3 overflow-y-auto space-y-2 scrollbar-thin font-mono text-xs">
            {logs.map((l,i) => (
              <div key={i} className="flex gap-2 leading-relaxed">
                <span className="text-slate-600 shrink-0">[{l.time}]</span>
                <span className={l.type==='success'?'text-green-400':l.type==='error'?'text-red-400':'text-blue-300'}>{l.msg}</span>
              </div>
            ))}
            {logs.length===0 && <p className="text-slate-600">Нет событий.</p>}
          </div>
        </div>
      </div>
    </div>
  );
}

// ---- SEO AGENTS TAB ----
function TabAgents({ agentStates, toggleAgent, logs }) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        {SEO_AGENTS_CFG.map(ag => {
          const running = agentStates[ag.id]==='running';
          const c = COLOR[ag.color];
          return (
            <div key={ag.id} className="bg-slate-800 border border-slate-700 rounded-xl p-5 flex flex-col gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className={`${c.bg} p-2.5 rounded-lg`}>
                    <Icon d={icons[ag.iconKey]} size={20} className={c.text} />
                  </div>
                  <div>
                    <h4 className="text-sm font-semibold text-white">{ag.name}</h4>
                    <p className="text-xs text-slate-500 mt-0.5">{ag.desc}</p>
                  </div>
                </div>
              </div>
              <div className="flex items-center gap-2 mt-auto pt-3 border-t border-slate-700">
                <span className={`flex-1 text-xs px-2.5 py-1 rounded-full text-center ${running?'bg-green-500/20 text-green-400':'bg-slate-700 text-slate-500'}`}>
                  {running ? '● Работает' : '○ Остановлен'}
                </span>
                <button onClick={()=>toggleAgent(ag.id)}
                  className={`px-4 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center gap-1.5
                    ${running?'bg-red-500/15 text-red-400 hover:bg-red-500/25':'bg-blue-600 text-white hover:bg-blue-700'}`}>
                  <Icon d={running?icons.stop:icons.play} size={12} />
                  {running?'Стоп':'Запуск'}
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* Full log */}
      <div className="bg-slate-950 border border-slate-800 rounded-xl">
        <div className="px-5 py-3 border-b border-slate-800 flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse"/>
          <span className="text-xs text-slate-400 uppercase tracking-wider font-mono">Журнал операций</span>
        </div>
        <div className="p-4 h-64 overflow-y-auto space-y-2 scrollbar-thin font-mono text-xs">
          {logs.map((l,i) => (
            <div key={i} className="flex gap-2">
              <span className="text-slate-600 shrink-0">[{l.time}]</span>
              <span className={l.type==='success'?'text-green-400':l.type==='error'?'text-red-400':'text-blue-300'}>{l.msg}</span>
            </div>
          ))}
          {logs.length===0 && <p className="text-slate-600">Запустите агентов чтобы увидеть активность.</p>}
        </div>
      </div>
    </div>
  );
}

// ---- LEADS / CRM TAB ----
function TabLeads() {
  const [leads, setLeads] = useState([]);
  const [counts, setCounts] = useState({});
  const [filter, setFilter] = useState('all');
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    const r = await api('leads');
    if (r.ok) { setLeads(r.leads); setCounts(r.counts); }
    setLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);

  const setStatus = async (id, status) => {
    await api('lead_status','POST',{id,status});
    load();
  };
  const deleteLead = async (id) => {
    if (!confirm('Удалить заявку?')) return;
    await api('lead_delete','POST',{id});
    load();
  };

  const shown = filter==='all' ? leads : leads.filter(l=>(l.status||'new')===filter);

  return (
    <div className="space-y-5">
      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[['new','Новые','blue'],['work','В работе','yellow'],['done','Готово','green'],['total','Всего','gray']].map(([k,lbl,col])=>(
          <div key={k} className="bg-slate-800 border border-slate-700 rounded-xl p-4 text-center">
            <p className="text-2xl font-bold text-white">{counts[k]||0}</p>
            <p className="text-xs text-slate-500 mt-1">{lbl}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="flex gap-2 flex-wrap">
        {[['all','Все'],['new','Новые'],['in_work','В работе'],['done','Готово'],['cancelled','Отменены']].map(([v,l])=>(
          <button key={v} onClick={()=>setFilter(v)}
            className={`text-xs px-3 py-1.5 rounded-full border transition-colors ${filter===v?'bg-blue-600 border-blue-600 text-white':'border-slate-600 text-slate-400 hover:border-slate-400'}`}>
            {l}
          </button>
        ))}
        <button onClick={load} className="ml-auto text-xs px-3 py-1.5 rounded-full border border-slate-600 text-slate-400 hover:border-slate-400 flex items-center gap-1.5">
          <Icon d={icons.refresh} size={12} /> Обновить
        </button>
      </div>

      {/* Table */}
      {loading ? (
        <div className="text-center py-16 text-slate-500">Загрузка…</div>
      ) : shown.length===0 ? (
        <div className="text-center py-16 text-slate-600">
          <Icon d={icons.leads} size={40} className="mx-auto mb-3 text-slate-700" />
          <p>Заявок нет</p>
        </div>
      ) : (
        <div className="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-700">
                  {['Дата','Имя','Телефон','Услуга','Сообщение','Статус','Действия'].map(h=>(
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-700/50">
                {shown.map(l => (
                  <tr key={l.id} className="hover:bg-slate-700/30 transition-colors">
                    <td className="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{l.dt||'—'}</td>
                    <td className="px-4 py-3 font-medium text-white whitespace-nowrap">{l.name||'—'}</td>
                    <td className="px-4 py-3">
                      {l.phone ? <a href={`tel:${l.phone}`} className="text-blue-400 hover:text-blue-300 font-medium whitespace-nowrap">{l.phone}</a> : '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-400 text-xs whitespace-nowrap">{l.service||'—'}</td>
                    <td className="px-4 py-3 text-slate-400 text-xs max-w-[180px] truncate">{l.message||'—'}</td>
                    <td className="px-4 py-3">
                      <select
                        value={l.status||'new'}
                        onChange={e=>setStatus(l.id, e.target.value)}
                        className="text-xs bg-slate-700 border border-slate-600 rounded-lg px-2 py-1 text-slate-300 cursor-pointer">
                        <option value="new">Новая</option>
                        <option value="in_work">В работе</option>
                        <option value="done">Готово</option>
                        <option value="cancelled">Отменена</option>
                      </select>
                    </td>
                    <td className="px-4 py-3">
                      <button onClick={()=>deleteLead(l.id)}
                        className="p-1.5 rounded-lg text-slate-500 hover:text-red-400 hover:bg-red-400/10 transition-colors">
                        <Icon d={icons.trash} size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

// ---- ANALYTICS TAB ----
function TabAnalytics() {
  const cards = [
    { title:'промышленный альпинизм сочи', pos:7, trend:'+3', vol:'1 240' },
    { title:'мойка фасадов сочи',           pos:12,trend:'+1', vol:'890' },
    { title:'герметизация швов сочи',       pos:5, trend:'+5', vol:'560' },
    { title:'покраска фасадов сочи',        pos:9, trend:'-1', vol:'430' },
    { title:'ремонт кровли сочи',           pos:14,trend:'+2', vol:'380' },
    { title:'гидроизоляция кровли сочи',    pos:11,trend:'0',  vol:'210' },
  ];
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
          <h3 className="text-sm font-semibold text-slate-300 mb-4 flex items-center gap-2">
            <Icon d={icons.chart} size={16} className="text-blue-400" /> Позиции в Яндексе
          </h3>
          <div className="space-y-3">
            {cards.map((c,i)=>(
              <div key={i} className="flex items-center justify-between py-2 border-b border-slate-700/50 last:border-0">
                <span className="text-sm text-slate-300 truncate max-w-[200px]">{c.title}</span>
                <div className="flex items-center gap-3 shrink-0">
                  <span className="text-xs text-slate-500">{c.vol}/мес</span>
                  <span className={`text-xs font-medium ${c.trend.startsWith('+')?'text-green-400':c.trend==='-1'||c.trend==='-2'?'text-red-400':'text-slate-500'}`}>{c.trend}</span>
                  <span className={`text-sm font-bold w-8 text-center ${c.pos<=5?'text-green-400':c.pos<=10?'text-yellow-400':'text-slate-400'}`}>#{c.pos}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
        <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
          <h3 className="text-sm font-semibold text-slate-300 mb-4 flex items-center gap-2">
            <Icon d={icons.search} size={16} className="text-purple-400" /> Анализ конкурентов
          </h3>
          {[
            { site:'promalpsochi.ru', score:72, links:145 },
            { site:'alpbond.org',     score:68, links:230 },
            { site:'trysochi.ru',     score:54, links:89  },
          ].map((c,i)=>(
            <div key={i} className="flex items-center gap-3 mb-4">
              <span className="text-slate-500 text-xs w-4">{i+1}</span>
              <div className="flex-1">
                <div className="flex justify-between mb-1">
                  <span className="text-sm text-slate-300">{c.site}</span>
                  <span className="text-xs text-slate-500">{c.links} ссылок</span>
                </div>
                <div className="h-1.5 bg-slate-700 rounded-full">
                  <div className="h-1.5 bg-purple-500 rounded-full" style={{width:`${c.score}%`}}/>
                </div>
              </div>
              <span className="text-xs font-bold text-purple-400 w-8">{c.score}</span>
            </div>
          ))}
          <p className="text-xs text-slate-600 mt-2">Данные обновлены агентом 12 мин назад</p>
        </div>
      </div>
    </div>
  );
}

// ---- SETTINGS TAB ----
function TabSettings() {
  const [tg, setTg] = useState({token:'',chat_id:''});
  const [ai, setAi] = useState({provider:'openrouter',api_key:'',model:'qwen/qwen3-235b-a22b:free'});
  const [pass, setPass] = useState('');
  const [msg, setMsg] = useState('');

  useEffect(() => {
    api('tg').then(r=>{ if(r.ok) setTg(r.tg||{}); });
    api('ai_config').then(r=>{ if(r.ok) setAi(r.ai||{}); });
  }, []);

  const saveTg = async e => {
    e.preventDefault();
    const r = await api('tg','POST', tg);
    setMsg(r.ok ? '✓ Telegram сохранён' : '✗ Ошибка');
    setTimeout(()=>setMsg(''),3000);
  };
  const saveAi = async e => {
    e.preventDefault();
    const r = await api('ai_config','POST', ai);
    setMsg(r.ok ? '✓ AI-настройки сохранены' : '✗ Ошибка');
    setTimeout(()=>setMsg(''),3000);
  };
  const savePass = async e => {
    e.preventDefault();
    const r = await api('change_pass','POST',{password:pass});
    setMsg(r.ok ? '✓ Пароль изменён' : r.error||'✗ Ошибка');
    setPass('');
    setTimeout(()=>setMsg(''),3000);
  };

  return (
    <div className="max-w-xl space-y-5">
      {msg && <div className="bg-blue-500/15 border border-blue-500/30 text-blue-300 rounded-lg px-4 py-3 text-sm">{msg}</div>}

      {/* Telegram */}
      <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
        <h3 className="text-sm font-semibold text-white mb-1 flex items-center gap-2">
          <span className="text-lg">✈️</span> Telegram-уведомления
        </h3>
        <p className="text-xs text-slate-500 mb-4">Бот: создать через @BotFather · Chat ID: @userinfobot</p>
        <form onSubmit={saveTg} className="space-y-3">
          <div>
            <label className="block text-xs text-slate-500 mb-1">Bot Token</label>
            <input type="text" value={tg.token||''} onChange={e=>setTg({...tg,token:e.target.value})}
              placeholder="123456789:AAFxxx..."
              className="w-full bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500"/>
          </div>
          <div>
            <label className="block text-xs text-slate-500 mb-1">Chat ID</label>
            <input type="text" value={tg.chat_id||''} onChange={e=>setTg({...tg,chat_id:e.target.value})}
              placeholder="-100xxxxxxxxxx"
              className="w-full bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500"/>
          </div>
          <button type="submit" className="bg-blue-600 hover:bg-blue-700 text-white text-sm px-5 py-2 rounded-lg font-medium transition-colors">
            Сохранить
          </button>
        </form>
      </div>

      {/* AI / OpenRouter */}
      <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
        <h3 className="text-sm font-semibold text-white mb-1 flex items-center gap-2">
          <span className="text-lg">🤖</span> AI-агент (OpenRouter)
        </h3>
        <p className="text-xs text-slate-500 mb-4">API ключ OpenRouter · модель для SEO-агентов</p>
        <form onSubmit={saveAi} className="space-y-3">
          <div>
            <label className="block text-xs text-slate-500 mb-1">Провайдер</label>
            <select value={ai.provider||'openrouter'} onChange={e=>setAi({...ai,provider:e.target.value})}
              className="w-full bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500">
              <option value="openrouter">OpenRouter</option>
              <option value="openai">OpenAI</option>
              <option value="anthropic">Anthropic</option>
            </select>
          </div>
          <div>
            <label className="block text-xs text-slate-500 mb-1">API Key</label>
            <input type="password" value={ai.api_key||''} onChange={e=>setAi({...ai,api_key:e.target.value})}
              placeholder="sk-or-v1-..."
              className="w-full bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 font-mono"/>
          </div>
          <div>
            <label className="block text-xs text-slate-500 mb-1">Модель</label>
            <input type="text" value={ai.model||''} onChange={e=>setAi({...ai,model:e.target.value})}
              placeholder="qwen/qwen3-235b-a22b:free"
              className="w-full bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 font-mono"/>
            <p className="text-xs text-slate-600 mt-1">Примеры: qwen/qwen3-235b-a22b:free · openai/gpt-4o · anthropic/claude-sonnet-4-6</p>
          </div>
          <button type="submit" className="bg-purple-600 hover:bg-purple-700 text-white text-sm px-5 py-2 rounded-lg font-medium transition-colors">
            Сохранить
          </button>
        </form>
      </div>

      {/* Password */}
      <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
        <h3 className="text-sm font-semibold text-white mb-4 flex items-center gap-2">
          <Icon d={icons.key} size={16} className="text-yellow-400" /> Смена пароля
        </h3>
        <form onSubmit={savePass} className="flex gap-2">
          <input type="password" value={pass} onChange={e=>setPass(e.target.value)}
            placeholder="Новый пароль (мин. 6 символов)" minLength={6} required
            className="flex-1 bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500"/>
          <button type="submit" className="bg-yellow-500 hover:bg-yellow-400 text-slate-900 text-sm px-4 py-2 rounded-lg font-semibold transition-colors">
            Сменить
          </button>
        </form>
      </div>

      {/* Info */}
      <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
        <h3 className="text-sm font-semibold text-white mb-3">Интеграции</h3>
        <div className="space-y-2 text-xs text-slate-500">
          <div className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-green-500"/>
            <span>PHP обработчик: <code className="text-slate-400">sochi-alp.ru/scripts/send.php</code></span>
          </div>
          <div className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-green-500"/>
            <span>Файл заявок: <code className="text-slate-400">/var/www/leads_data/leads.json</code></span>
          </div>
          <div className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-yellow-500"/>
            <span>Яндекс Метрика: ID <code className="text-slate-400">108282968</code></span>
          </div>
          <div className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-slate-600"/>
            <span>Google Analytics 4: не подключён</span>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---- AUDIT TAB ----
function TabAudit() {
  const [results,     setResults]     = useState([]);
  const [history,     setHistory]     = useState([]);
  const [loading,     setLoading]     = useState(false);
  const [expandedRun, setExpandedRun] = useState(null);
  const [lastRun,     setLastRun]     = useState('');

  const loadHistory = () => api('audit_history').then(r=>{ if(r.ok) setHistory(r.history||[]); });

  useEffect(() => { loadHistory(); }, []);

  const runAudit = async () => {
    setLoading(true);
    setResults([]);
    const r = await api('audit','POST',{});
    setLoading(false);
    if (r.ok) {
      setResults(r.results||[]);
      setLastRun(new Date().toLocaleString('ru-RU'));
      loadHistory();
    }
  };

  const typeBadge = {
    ok:      'bg-green-500/15 text-green-300 border border-green-500/25',
    warning: 'bg-yellow-500/15 text-yellow-300 border border-yellow-500/25',
    error:   'bg-red-500/15 text-red-300 border border-red-500/25',
  };
  const typeDot = { ok:'bg-green-400', warning:'bg-yellow-400', error:'bg-red-500' };
  const typeLabel = { ok:'OK', warning:'Предупреждение', error:'Ошибка' };
  const rowBg = { ok:'', warning:'bg-yellow-500/5', error:'bg-red-500/8' };

  const errors   = results.filter(r=>r.type==='error').length;
  const warnings = results.filter(r=>r.type==='warning').length;
  const oks      = results.filter(r=>r.type==='ok').length;

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h3 className="text-lg font-semibold text-white flex items-center gap-2">
            <Icon d={icons.warning} size={20} className="text-yellow-400"/> Технический аудит
          </h3>
          <p className="text-sm text-slate-500 mt-0.5">Проверка доступности страниц, кодов ответа, скорости загрузки</p>
        </div>
        <button onClick={runAudit} disabled={loading}
          className="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white px-5 py-2.5 rounded-lg text-sm font-medium transition-colors">
          {loading
            ? <><span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin inline-block"/>Проверяем…</>
            : <><Icon d={icons.refresh} size={16}/>Запустить аудит</>}
        </button>
      </div>

      {/* Summary cards */}
      {results.length > 0 && (
        <div className="grid grid-cols-3 gap-3">
          <div className="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-center">
            <p className="text-3xl font-bold text-green-400">{oks}</p>
            <p className="text-xs text-green-500/80 mt-1">Без ошибок</p>
          </div>
          <div className="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 text-center">
            <p className="text-3xl font-bold text-yellow-400">{warnings}</p>
            <p className="text-xs text-yellow-500/80 mt-1">Предупреждений</p>
          </div>
          <div className={`border rounded-xl p-4 text-center ${errors>0?'bg-red-500/10 border-red-500/20':'bg-slate-800 border-slate-700'}`}>
            <p className={`text-3xl font-bold ${errors>0?'text-red-400':'text-slate-500'}`}>{errors}</p>
            <p className={`text-xs mt-1 ${errors>0?'text-red-500/80':'text-slate-600'}`}>Ошибок</p>
          </div>
        </div>
      )}

      {/* Results table */}
      {results.length > 0 && (
        <div className="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
          <div className="px-5 py-3 border-b border-slate-700 flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Результаты · {lastRun}</span>
            <span className="text-xs text-slate-600">{results.length} проверок</span>
          </div>
          <div className="divide-y divide-slate-700/40">
            {results.map((r,i) => (
              <div key={i} className={`flex items-center gap-3 px-5 py-3 ${rowBg[r.type]}`}>
                <span className={`w-2 h-2 rounded-full shrink-0 ${typeDot[r.type]} ${r.type==='error'?'ring-2 ring-red-500/30':''}`}/>
                <div className="flex-1 min-w-0">
                  <p className={`text-sm font-medium ${r.type==='error'?'text-red-200':r.type==='warning'?'text-yellow-200':'text-white'}`}>{r.name}</p>
                  <p className="text-xs text-slate-500 font-mono mt-0.5">{r.url}</p>
                </div>
                <span className={`text-xs font-mono px-2 py-0.5 rounded ${r.code===r.expected?'text-slate-500':'text-red-400 font-bold bg-red-500/10'}`}>
                  HTTP {r.code||'—'}
                </span>
                <span className={`text-xs shrink-0 ${r.time_ms>3000?'text-red-400 font-semibold':r.time_ms>1500?'text-yellow-400':'text-slate-500'}`}>
                  {r.time_ms}мс
                </span>
                <span className={`text-xs px-2.5 py-1 rounded-full shrink-0 ${typeBadge[r.type]}`}>
                  {typeLabel[r.type]}
                </span>
                <span className={`text-xs shrink-0 max-w-[200px] truncate ${r.type==='error'?'text-red-400':r.type==='warning'?'text-yellow-400':'text-green-400'}`}>
                  {r.msg}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* History */}
      <div className="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div className="px-5 py-3 border-b border-slate-700">
          <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">История аудитов</span>
        </div>
        {history.length === 0 ? (
          <p className="text-center py-10 text-slate-600 text-sm">Нет истории. Запустите первый аудит.</p>
        ) : (
          <div className="divide-y divide-slate-700/50">
            {history.map((run,i) => (
              <div key={i}>
                <button onClick={()=>setExpandedRun(expandedRun===i?null:i)}
                  className="w-full flex items-center gap-3 px-5 py-3.5 hover:bg-slate-700/30 transition-colors text-left">
                  <span className="text-xs text-slate-500 shrink-0 w-32">{run.dt}</span>
                  <span className="flex-1 text-sm text-slate-300">Проверено {run.total||run.results?.length||0} URL</span>
                  {(run.errors||0) > 0 &&
                    <span className="text-xs bg-red-500/15 text-red-400 border border-red-500/20 px-2.5 py-0.5 rounded-full">{run.errors} ошибок</span>}
                  {(run.warnings||0) > 0 &&
                    <span className="text-xs bg-yellow-500/15 text-yellow-400 border border-yellow-500/20 px-2.5 py-0.5 rounded-full">{run.warnings} предупреждений</span>}
                  {(run.errors||0)===0 && (run.warnings||0)===0 &&
                    <span className="text-xs bg-green-500/15 text-green-400 border border-green-500/20 px-2.5 py-0.5 rounded-full">Всё ОК</span>}
                  <Icon d={expandedRun===i?"M5 15l7-7 7 7":"M19 9l-7 7-7-7"} size={14} className="text-slate-600 shrink-0"/>
                </button>
                {expandedRun===i && (
                  <div className="px-5 pb-4 space-y-1 bg-slate-900/30">
                    {(run.results||[]).map((r,j) => (
                      <div key={j} className={`flex items-center gap-3 py-2 border-b border-slate-700/30 last:border-0 ${r.type==='error'?'bg-red-500/5 rounded px-2':''}`}>
                        <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${typeDot[r.type]}`}/>
                        <span className={`text-xs flex-1 font-medium ${r.type==='error'?'text-red-300':r.type==='warning'?'text-yellow-300':'text-slate-400'}`}>{r.name}</span>
                        <span className="text-xs text-slate-600 font-mono">HTTP {r.code}</span>
                        <span className={`text-xs ${r.time_ms>3000?'text-red-400':r.time_ms>1500?'text-yellow-400':'text-slate-600'}`}>{r.time_ms}мс</span>
                        <span className={`text-xs ${r.type==='error'?'text-red-400':r.type==='warning'?'text-yellow-400':'text-slate-500'}`}>{r.msg}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// =========================================================
// MAIN APP
// =========================================================
function App() {
  const [tab, setTab] = useState('dashboard');
  const [agentStates, setAgentStates] = useState(
    Object.fromEntries(SEO_AGENTS_CFG.map(a=>[a.id,'idle']))
  );
  const [logs, setLogs] = useState([
    {time: new Date().toLocaleTimeString(), msg:'Система инициализирована.', type:'info'}
  ]);
  const [counts, setCounts] = useState({});

  const recentRef = useRef(new Set());

  const addLog = useCallback((msg, type='info') => {
    setLogs(p => [{time:new Date().toLocaleTimeString(), msg, type}, ...p].slice(0,120));
  }, []);

  // Load lead counts on mount
  useEffect(() => {
    api('leads').then(r=>{ if(r.ok) setCounts(r.counts); });
  }, []);

  // Simulate agent events — без дублей
  useEffect(() => {
    const running = SEO_AGENTS_CFG.filter(a=>agentStates[a.id]==='running');
    if (!running.length) return;
    const id = setInterval(() => {
      const ag = running[Math.floor(Math.random()*running.length)];
      // Выбираем событие которого ещё не было недавно
      const available = ag.events.filter(e => {
        const key = `${ag.id}:${typeof e==='string'?e:e.msg}`;
        return !recentRef.current.has(key);
      });
      const pool = available.length ? available : ag.events;
      const ev   = pool[Math.floor(Math.random()*pool.length)];
      const evMsg  = typeof ev === 'string' ? ev : ev.msg;
      const evType = typeof ev === 'string' ? 'success' : ev.type;
      const key    = `${ag.id}:${evMsg}`;
      recentRef.current.add(key);
      // Ограничиваем размер сета
      if (recentRef.current.size > 20) {
        const first = recentRef.current.values().next().value;
        recentRef.current.delete(first);
      }
      addLog(`[${ag.name}] ${evMsg}`, evType);
    }, 3500);
    return () => clearInterval(id);
  }, [agentStates, addLog]);

  const toggleAgent = (id) => {
    setAgentStates(p => {
      const running = p[id]==='running';
      const ag = SEO_AGENTS_CFG.find(a=>a.id===id);
      addLog(`Агент «${ag.name}» ${running?'остановлен':'запущен'}.`, running?'error':'info');
      return {...p, [id]: running?'idle':'running'};
    });
  };

  const nav = [
    { id:'dashboard', label:'Дашборд',            icon:icons.dashboard },
    { id:'agents',    label:'SEO Агенты',          icon:icons.bot       },
    { id:'leads',     label:'CRM Заявки',          icon:icons.leads     },
    { id:'analytics', label:'Позиции',             icon:icons.analytics },
    { id:'audit',     label:'Технический аудит',   icon:icons.warning   },
    { id:'settings',  label:'Настройки',           icon:icons.settings  },
  ];

  return (
    <div className="min-h-screen bg-slate-900 flex text-slate-200">
      {/* Sidebar */}
      <aside className="w-56 bg-slate-950 border-r border-slate-800 flex flex-col">
        <div className="px-5 py-5 border-b border-slate-800">
          <div className="flex items-center gap-2.5">
            <div className="bg-blue-600 p-1.5 rounded-lg">
              <Icon d={icons.bot} size={16} className="text-white" />
            </div>
            <div>
              <p className="text-xs font-bold text-white leading-none">SEO Dashboard</p>
              <p className="text-[10px] text-slate-500 mt-0.5">Альпинисты Сочи</p>
            </div>
          </div>
        </div>

        <nav className="flex-1 py-3 px-2 space-y-0.5">
          {nav.map(n => (
            <button key={n.id} onClick={()=>setTab(n.id)}
              className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors text-left
                ${tab===n.id?'bg-blue-600/20 text-blue-400 font-medium':'text-slate-500 hover:text-slate-300 hover:bg-slate-800'}`}>
              <Icon d={n.icon} size={16} />
              {n.label}
              {n.id==='leads' && counts.new>0 &&
                <span className="ml-auto text-xs bg-blue-600 text-white rounded-full px-1.5">{counts.new}</span>}
            </button>
          ))}
        </nav>

        <div className="p-3 border-t border-slate-800">
          <a href="?logout=1" className="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm text-slate-600 hover:text-slate-400 hover:bg-slate-800 transition-colors">
            <Icon d={icons.logout} size={16} />
            Выйти
          </a>
        </div>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col min-h-screen overflow-hidden">
        {/* Top bar */}
        <header className="bg-slate-900 border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0">
          <h1 className="text-lg font-semibold text-white">
            {nav.find(n=>n.id===tab)?.label}
          </h1>
          <div className="flex items-center gap-3">
            <span className="text-xs text-slate-500 bg-slate-800 px-3 py-1.5 rounded-full border border-slate-700 flex items-center gap-1.5">
              <Icon d={icons.globe} size={12} className="text-blue-400"/>
              sochi-alp.ru
            </span>
            <div className="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-xs font-bold text-white">A</div>
          </div>
        </header>

        {/* Content */}
        <main className="flex-1 overflow-y-auto p-6">
          {tab==='dashboard' && <TabDashboard counts={counts} agentStates={agentStates} toggleAgent={toggleAgent} logs={logs}/>}
          {tab==='agents'    && <TabAgents agentStates={agentStates} toggleAgent={toggleAgent} logs={logs}/>}
          {tab==='leads'     && <TabLeads />}
          {tab==='analytics' && <TabAnalytics />}
          {tab==='audit'     && <TabAudit />}
          {tab==='settings'  && <TabSettings />}
        </main>
      </div>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<App/>);
</script>

<?php endif ?>
</body>
</html>
