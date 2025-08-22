<?php
// Simple Life Dashboards (PHP) - Single-file app for easy run/deploy
// Run: php -S 0.0.0.0:8000 -t public

declare(strict_types=1);

// Paths
$rootDir = dirname(__DIR__);
$dataDir = $rootDir . '/data';
$uploadsDir = $rootDir . '/public/uploads';
$filesDir = $uploadsDir . '/files';
$avatarsDir = $uploadsDir . '/avatars';

// Ensure directories
@mkdir($dataDir, 0777, true);
@mkdir($uploadsDir, 0777, true);
@mkdir($filesDir, 0777, true);
@mkdir($avatarsDir, 0777, true);

// Database
$db = new PDO('sqlite:' . $dataDir . '/app.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Schema
$db->exec('CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT,
  username TEXT,
  avatar_path TEXT
)');
$db->exec('CREATE TABLE IF NOT EXISTS journal_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 1,
  entry_date TEXT NOT NULL,
  title TEXT,
  content TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_journal_unique ON journal_entries(user_id, entry_date, title)');
$db->exec('CREATE TABLE IF NOT EXISTS todos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 1,
  label TEXT NOT NULL,
  is_done INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE IF NOT EXISTS habit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 1,
  habit_name TEXT NOT NULL,
  log_date TEXT NOT NULL,
  completed INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE IF NOT EXISTS files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 1,
  original_name TEXT NOT NULL,
  stored_name TEXT NOT NULL,
  mime_type TEXT,
  size_bytes INTEGER,
  stored_path TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

// Helpers
function request_path(): string {
  $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  return rtrim($uri, '/') ?: '/';
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function json_response($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function today(): string { return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d'); }
function read_body(): array { return $_POST; }

// Routing
$path = request_path();

// API: Combined heatmap (journals + todos + habits)
if ($path === '/api/heatmap') {
  $start = (new DateTime('-365 days', new DateTimeZone('UTC')))->format('Y-m-d');
  // Journal counts by date
  $stmt = $db->prepare('SELECT entry_date as d, COUNT(*) as c FROM journal_entries WHERE entry_date >= ? GROUP BY entry_date');
  $stmt->execute([$start]);
  $j = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  // Todo counts by created day
  $stmt = $db->prepare('SELECT date(created_at) as d, COUNT(*) as c FROM todos WHERE date(created_at) >= ? GROUP BY d');
  $stmt->execute([$start]);
  $t = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  // Habit logs by date
  $stmt = $db->prepare('SELECT log_date as d, COUNT(*) as c FROM habit_logs WHERE log_date >= ? GROUP BY log_date');
  $stmt->execute([$start]);
  $hmap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

  $counts = [];
  foreach ([$j, $t, $hmap] as $src) {
    foreach ($src as $d => $c) { $counts[$d] = ($counts[$d] ?? 0) + (int)$c; }
  }
  ksort($counts);
  $data = [];
  foreach ($counts as $d => $c) { $data[] = ['date' => $d, 'count' => $c]; }
  json_response($data);
}

// POST: save journal entry (supports multiple per day via title)
if ($path === '/journal/save' && is_post()) {
  $date = $_POST['entry_date'] ?? today();
  $title = trim($_POST['title'] ?? '');
  $content = $_POST['content'] ?? '';
  if (!$title) { $title = 'Untitled'; }
  // Upsert by (user_id, date, title)
  $stmt = $db->prepare('SELECT id FROM journal_entries WHERE user_id=1 AND entry_date=? AND title=? LIMIT 1');
  $stmt->execute([$date, $title]);
  $id = $stmt->fetchColumn();
  if ($id) {
    $stmt = $db->prepare('UPDATE journal_entries SET content=? WHERE id=?');
    $stmt->execute([$content, $id]);
  } else {
    $stmt = $db->prepare('INSERT INTO journal_entries(user_id, entry_date, title, content) VALUES(1,?,?,?)');
    $stmt->execute([$date, $title, $content]);
  }
  header('Location: /?d=' . urlencode($date));
  exit;
}

// POST: upload files (inline viewing, no download forced)
if ($path === '/files/upload' && is_post()) {
  if (!isset($_FILES['file'])) { header('Location: /'); exit; }
  $f = $_FILES['file'];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { header('Location: /'); exit; }
  $orig = $f['name'];
  $mime = mime_content_type($f['tmp_name']);
  $size = (int)$f['size'];
  $stored = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', $orig);
  $dest = $filesDir . '/' . $stored;
  if (!move_uploaded_file($f['tmp_name'], $dest)) { header('Location: /'); exit; }
  $stmt = $db->prepare('INSERT INTO files(user_id, original_name, stored_name, mime_type, size_bytes, stored_path) VALUES(1,?,?,?,?,?)');
  $stmt->execute([$orig, $stored, $mime, $size, $dest]);
  header('Location: /');
  exit;
}

// GET: render file inline by id
if ($path === '/files/view') {
  $id = (int)($_GET['id'] ?? 0);
  $stmt = $db->prepare('SELECT original_name, mime_type, stored_path FROM files WHERE id=?');
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || !is_file($row['stored_path'])) { http_response_code(404); echo 'Not found'; exit; }
  header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
  header('Content-Disposition: inline; filename="' . basename($row['original_name']) . '"');
  header('Content-Length: ' . filesize($row['stored_path']));
  readfile($row['stored_path']);
  exit;
}

// POST: avatar upload (local)
if ($path === '/profile/avatar' && is_post()) {
  if (isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['avatar'];
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $stored = 'avatar_1.' . ($ext ?: 'png');
    $dest = $avatarsDir . '/' . $stored;
    @unlink($dest);
    if (move_uploaded_file($f['tmp_name'], $dest)) {
      $stmt = $db->prepare('INSERT INTO users(id, avatar_path) VALUES(1, ?) ON CONFLICT(id) DO UPDATE SET avatar_path=excluded.avatar_path');
      $stmt->execute([$dest]);
    }
  }
  header('Location: /');
  exit;
}

// Default: Dashboard page
$date = $_GET['d'] ?? today();
// Load the two journal sections
$stmt = $db->prepare('SELECT title, content FROM journal_entries WHERE user_id=1 AND entry_date=?');
$stmt->execute([$date]);
$entries = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$learnToday = $entries['What I Learnt Today'] ?? '';
$mistakesToday = $entries['Mistakes I Made Today'] ?? '';

// Recent files
$files = $db->query('SELECT id, original_name, mime_type FROM files ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
// Current avatar if any
$avatar = $db->query('SELECT avatar_path FROM users WHERE id=1')->fetchColumn() ?: '';
$avatarUrl = $avatar ? '/uploads/avatars/' . basename($avatar) : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Overview+</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.quilljs.com/1.3.6/quill.snow.css">
  <style>
    .quill-editor { background: #fff; }
    .file-preview { max-width: 100%; height: auto; }
    .avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">Overview+</a>
    <div class="ms-auto d-flex align-items-center gap-3">
      <form class="d-flex align-items-center" method="post" action="/profile/avatar" enctype="multipart/form-data">
        <?php if ($avatarUrl): ?>
          <img src="<?= h($avatarUrl) ?>" class="avatar me-2" alt="avatar">
        <?php endif; ?>
        <input type="file" name="avatar" accept="image/*" class="form-control form-control-sm me-2">
        <button class="btn btn-outline-light btn-sm" type="submit">Upload Avatar</button>
      </form>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Activity Heatmap</span>
          <small class="text-muted">Journals + Todos + Habits</small>
        </div>
        <div class="card-body" style="height:200px">
          <canvas id="heatmap"></canvas>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card">
        <div class="card-header">What I Learnt Today (<?= h($date) ?>)</div>
        <div class="card-body">
          <form method="post" action="/journal/save">
            <input type="hidden" name="entry_date" value="<?= h($date) ?>">
            <input type="hidden" name="title" value="What I Learnt Today">
            <div id="learn-editor" class="quill-editor" style="height:150px;"><?= $learnToday ?></div>
            <input type="hidden" id="learn-content" name="content">
            <button class="btn btn-primary mt-2" type="submit" onclick="syncQuill('learn-editor','learn-content')">Save</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card">
        <div class="card-header">Mistakes I Made Today (<?= h($date) ?>)</div>
        <div class="card-body">
          <form method="post" action="/journal/save">
            <input type="hidden" name="entry_date" value="<?= h($date) ?>">
            <input type="hidden" name="title" value="Mistakes I Made Today">
            <div id="mistakes-editor" class="quill-editor" style="height:150px;"><?= $mistakesToday ?></div>
            <input type="hidden" id="mistakes-content" name="content">
            <button class="btn btn-primary mt-2" type="submit" onclick="syncQuill('mistakes-editor','mistakes-content')">Save</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header">Upload Files (inline viewing)</div>
        <div class="card-body">
          <form method="post" action="/files/upload" enctype="multipart/form-data" class="d-flex gap-2">
            <input type="file" name="file" class="form-control" accept="image/*,.pdf,.txt,.md">
            <button class="btn btn-outline-primary" type="submit">Upload</button>
          </form>
          <div class="row row-cols-1 row-cols-md-3 g-3 mt-2">
            <?php foreach ($files as $f): ?>
              <div class="col">
                <div class="card h-100">
                  <div class="card-body">
                    <div class="mb-2"><strong><?= h($f['original_name']) ?></strong></div>
                    <?php if (strpos($f['mime_type'] ?? '', 'image/') === 0): ?>
                      <img src="/files/view?id=<?= (int)$f['id'] ?>" class="file-preview" alt="image">
                    <?php elseif (($f['mime_type'] ?? '') === 'application/pdf'): ?>
                      <iframe src="/files/view?id=<?= (int)$f['id'] ?>" style="width:100%;height:220px" title="pdf"></iframe>
                    <?php else: ?>
                      <a href="/files/view?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">Open</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@2.0.1/dist/chartjs-chart-matrix.umd.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
  window.syncQuill = function(editorId, inputId){
    const el = document.getElementById(editorId);
    if (!el) return;
    const q = Quill.find(el) || new Quill(el, { theme: 'snow' });
    document.getElementById(inputId).value = el.querySelector('.ql-editor').innerHTML;
  };
  document.querySelectorAll('.quill-editor').forEach(el=>{ if (!Quill.find(el)) new Quill(el, { theme: 'snow' }); });
  const heatmapCanvas = document.getElementById('heatmap');
  if (heatmapCanvas){
    fetch('/api/heatmap').then(r=>r.json()).then(data=>{
      const days = {}; data.forEach(d=>{ days[d.date] = d.count; });
      const today = new Date(); const start = new Date(today.getTime() - 365*24*60*60*1000);
      const values = [];
      for(let d=new Date(start); d<=today; d.setDate(d.getDate()+1)){
        const key = d.toISOString().slice(0,10);
        const week = (function getWeekNumber(dt){ const date=new Date(Date.UTC(dt.getFullYear(), dt.getMonth(), dt.getDate())); const dayNum=date.getUTCDay()||7; date.setUTCDate(date.getUTCDate()+4-dayNum); const yearStart=new Date(Date.UTC(date.getUTCFullYear(),0,1)); return Math.ceil((((date-yearStart)/86400000)+1)/7); })(d);
        const dow = d.getDay();
        values.push({x: week, y: dow, v: days[key] || 0, date: key});
      }
      const weeks = [...new Set(values.map(v=>v.x))];
      new Chart(heatmapCanvas, { type:'matrix', data:{ datasets:[{ label:'Activity Heatmap', data: values, width: ({chart}) => (chart.chartArea||{}).width/weeks.length-2, height: ({chart}) => (chart.chartArea||{}).height/7-2, backgroundColor: ctx=>{ const v=ctx.raw.v; const a=v?Math.min(0.1+v/5,1):0.05; return `rgba(13,110,253,${a})`; }, borderWidth:1, borderColor:'rgba(0,0,0,0.05)' }] }, options:{ maintainAspectRatio:false, scales:{ x:{ type:'linear', ticks:{ callback:()=>'' } }, y:{ type:'linear', ticks:{ callback:v=>['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][v] } } }, plugins:{ tooltip:{ callbacks:{ label: ctx=>`${ctx.raw.date}: ${ctx.raw.v}` } } }, onClick: (evt, elems)=>{ const ch=evt.chart; const points=ch.getElementsAtEventForMode(evt,'nearest',{intersect:true},true); if(points.length){ const raw=ch.data.datasets[points[0].datasetIndex].data[points[0].index]; const d=raw.date; location.href='/?d='+d; } } } });
    });
  }
</script>
</body>
</html>