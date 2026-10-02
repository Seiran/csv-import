<?php
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Імпорт заявок</title>
<style>
  body { font: 16px/1.5 system-ui, sans-serif; background: #f4f5f7; margin: 0; padding: 40px 16px; color: #1c1e21; }
  .card { max-width: 560px; margin: 0 auto; background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
  h1 { margin: 0 0 20px; font-size: 22px; }
  input[type=file] { display: block; margin-bottom: 16px; }
  button { background: #2563eb; color: #fff; border: 0; padding: 10px 22px; border-radius: 8px; font-size: 15px; cursor: pointer; }
  button:disabled { opacity: .5; cursor: default; }
  .bar { height: 14px; background: #e5e7eb; border-radius: 7px; overflow: hidden; margin: 20px 0 8px; display: none; }
  .bar > i { display: block; height: 100%; width: 0; background: #2563eb; transition: width .3s; }
  #stat { font-size: 14px; color: #555; }
  #msg { margin-top: 14px; }
  .ok { color: #15803d; } .err { color: #b91c1c; }
</style>
</head>
<body>
<div class="card">
  <h1>Імпорт заявок (CSV / XLSX)</h1>
  <input type="file" id="file" accept=".csv,.xlsx">
  <button id="go">Завантажити</button>
  <div class="bar" id="bar"><i id="fill"></i></div>
  <div id="stat"></div>
  <div id="msg"></div>
</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const $ = id => document.getElementById(id);

async function post(action, body) {
  const r = await fetch('api.php?action=' + action, { method: 'POST', body, headers: { 'X-CSRF-Token': CSRF } });
  const j = await r.json().catch(() => ({ error: 'Некоректна відповідь сервера (HTTP ' + r.status + ')' }));
  if (!r.ok || j.error) throw new Error(j.error || 'HTTP ' + r.status);
  return j;
}

function render(s) {
  $('fill').style.width = s.percent + '%';
  $('stat').textContent = s.phase === 'converting'
    ? `Читання xlsx… прочитано рядків: ${s.converted}`
    : `${s.percent}% — оброблено ${s.processed}, збережено ${s.saved}, не збережено ${s.errors}, із зауваженнями ${s.warnings}`;
}

$('go').onclick = async () => {
  const file = $('file').files[0];
  if (!file) { $('msg').innerHTML = '<span class="err">Оберіть файл</span>'; return; }
  $('go').disabled = true;
  $('msg').textContent = '';
  $('bar').style.display = 'block';
  $('stat').textContent = 'Завантаження файлу…';
  try {
    const fd = new FormData(); fd.append('file', file);
    const { id } = await post('upload', fd);

    // Цикл: кожен запит обробляє порцію ≤ 20 с і повертає стан.
    let s, fails = 0;
    do {
      try {
        const p = new FormData(); p.append('id', id);
        s = await post('process', p);
        fails = 0;
        render(s);
        if (s.busy) await new Promise(r => setTimeout(r, 1000));
      } catch (e) {               // разовий збій мережі — повторюємо, імпорт продовжиться з місця зупинки
        if (++fails > 3) throw e;
        await new Promise(r => setTimeout(r, 1500));
      }
    } while (!s || !s.done);

    let html = `<span class="ok">Готово: збережено ${s.saved} з ${s.processed} рядків.</span>`;
    if (s.errors) html += ` Не збережено: ${s.errors}.`;
    if (s.errors || s.warnings) html += ` <a href="api.php?action=errors&id=${id}">Звіт (CSV): ${s.warnings} зауважень</a>`;
    $('msg').innerHTML = html;
  } catch (e) {
    $('msg').innerHTML = '<span class="err">Помилка: ' + e.message.replace(/</g, '&lt;') + '</span>';
  } finally {
    $('go').disabled = false;
  }
};
</script>
</body>
</html>
