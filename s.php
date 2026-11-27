<?php
declare(strict_types=1);

/*
 * File Manager for your own website.
 * Install this file in public_html as fm.php.
 * Remove this file when you no longer need it.
 */

session_start(); // ← ini yang sebelumnya hilang

const FM_ROOT = __DIR__;
const FM_MAX_UPLOAD = 20 * 1024 * 1024; // 20 MB
const FM_MAX_EDIT = 2 * 1024 * 1024;   // 2 MB
const FM_BACKUP_DIR = '.fm-backups';
const FM_LOG_FILE = '.fm-activity.log';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fail(string $message): never { throw new RuntimeException($message); }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        fail('Invalid security token. Refresh the page and try again.');
    }
}
function audit(string $action, string $path): void {
    $private = FM_ROOT . '/' . FM_BACKUP_DIR;
    if (!is_dir($private)) @mkdir($private, 0700, true);
    if (!file_exists($private . '/.htaccess')) @file_put_contents($private . '/.htaccess', "Require all denied\nDeny from all\n");
    $line = date('c') . "\t" . preg_replace('/[\r\n\t]/', ' ', $action) . "\t" .
        preg_replace('/[\r\n\t]/', ' ', $path) . "\n";
    @file_put_contents(FM_ROOT . '/' . FM_BACKUP_DIR . '/activity.log', $line, FILE_APPEND | LOCK_EX);
}
function normalize_rel(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? '';
    $path = trim($path, '/');
    if ($path === '' || $path === '.') return '';
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, "\0")) fail('Invalid path.');
    }
    return implode('/', $parts);
}
function full_path(string $rel, bool $mustExist = false): string {
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
    if ($real !== $root && !str_starts_with($real, $prefix)) fail('Access outside public_html is blocked.');
    if ($mustExist && is_link($candidate)) fail('Symbolic links are not allowed.');
    return $real;
}
function safe_name(string $name): string {
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') ||
        str_contains($name, '\\') || str_contains($name, "\0")) fail('Invalid filename.');
    return $name;
}
function backup_file(string $path, string $rel): void {
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
function atomic_write(string $path, string $contents, string $rel): void {
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
function current_dir(): string {
    return normalize_rel((string)($_GET['dir'] ?? ''));
}
function fmt_size(int $n): string {
    $units = ['B','KB','MB','GB']; $i = 0; $v = (float)$n;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return number_format($v, $i ? 1 : 0) . ' ' . $units[$i];
}

$error = '';
$notice = '';
try {
    if (isset($_GET['logout'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = (string)($_POST['action'] ?? '');
        $dir = current_dir();
        $rel = normalize_rel((string)($_POST['path'] ?? $dir));

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
        } elseif ($action === 'mkdir' || $action === 'create') {
            $name = safe_name((string)($_POST['name'] ?? ''));
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
            $content = (string)($_POST['content'] ?? '');
            if ($content === '' && filesize($target) > 0 && empty($_POST['confirm_empty'])) fail('Empty content blocked. Tick the confirmation to intentionally empty this file.');
            atomic_write($target, $content, $rel);
            audit('edit', $rel); $notice = 'Saved. A backup was created first.';
        } elseif ($action === 'rename') {
            $target = full_path($rel, true);
            if ($target === realpath(__FILE__)) fail('Renaming the active file manager is disabled.');
            $name = safe_name((string)($_POST['name'] ?? ''));
            $newRel = (dirname($rel) === '.' ? '' : dirname($rel) . '/') . $name;
            $new = full_path($newRel);
            if (file_exists($new)) fail('Destination already exists.');
            if (!@rename($target, $new)) fail('Rename failed.');
            audit('rename', $rel . ' -> ' . $newRel); $notice = 'Renamed.';
        } elseif ($action === 'delete') {
            $target = full_path($rel, true);
            if ($target === realpath(__FILE__)) fail('Deleting the active file manager is disabled.');
            if (is_dir($target)) {
                if (count(scandir($target) ?: []) > 2) fail('Folder is not empty. Delete its contents first.');
                if (!@rmdir($target)) fail('Could not delete folder.');
            } else {
                if (!@unlink($target)) fail('Delete failed.');
            }
            audit('delete', $rel); $notice = 'Deleted.';
        } elseif ($action === 'chmod') {
            $target = full_path($rel, true);
            $mode = octdec((string)($_POST['mode'] ?? '0644'));
            if (!in_array($mode, [0600, 0640, 0644, 0700, 0750, 0755], true)) fail('Only common safe permission modes are allowed.');
            if (!@chmod($target, $mode)) fail('Permission change failed.');
            audit('chmod ' . decoct($mode), $rel); $notice = 'Permissions updated.';
        } elseif ($action === 'download') {
            $target = full_path($rel, true);
            if (!is_file($target) || is_link($target)) fail('Not a regular file.');
            session_write_close();
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
                if ($entry === false || str_contains($entry, "\0")) continue;
                $entry = str_replace('\\', '/', $entry);
                if (str_starts_with(\( entry, '/') || preg_match('#(^|/)\.\.(/| \))#', $entry)) { $zip->close(); fail('Unsafe ZIP entry blocked.'); }
                $dest = $outDir . '/' . $entry;
                if (str_ends_with($entry, '/')) { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
                $parent = dirname($dest);
                if (!is_dir($parent)) @mkdir($parent, 0755, true);
                $realParent = realpath($parent);
                if ($realParent === false || !str_starts_with($realParent . DIRECTORY_SEPARATOR, $base)) { $zip->close(); fail('Unsafe ZIP path blocked.'); }
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
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$dir = current_dir();
try {
    $base = full_path($dir, true);
    if (!is_dir($base)) fail('Not a directory.');
} catch (Throwable $e) { $dir = ''; $base = FM_ROOT; $error = $error ?: $e->getMessage(); }
$items = scandir($base) ?: [];
$dirs = []; $files = [];
foreach ($items as $name) {
    if ($name === '.' || $name === '..' || $name === FM_BACKUP_DIR || str_starts_with($name, '.fm-')) continue;
    $relItem = ($dir ? $dir . '/' : '') . $name;
    try { $p = full_path($relItem, true); } catch (Throwable $e) { continue; }
    if (is_dir($p)) $dirs[] = $name; else $files[] = $name;
}
sort($dirs, SORT_NATURAL | SORT_FLAG_CASE); sort($files, SORT_NATURAL | SORT_FLAG_CASE);
$parent = $dir === '' ? null : (dirname($dir) === '.' ? '' : dirname($dir));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>File Manager</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e7edf7;font:14px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1180px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}.brand{font-size:22px;font-weight:800}.sub{color:#94a6c0;font-size:12px}.btn,button{border:1px solid #31445f;background:#17253a;color:#e7edf7;padding:9px 12px;border-radius:9px;text-decoration:none;cursor:pointer;font:inherit}.primary{background:#3978f6;border-color:#3978f6}.danger{color:#ffaaaa}.path,.panel{background:#111d30;border:1px solid #263750;border-radius:14px;padding:15px;margin-bottom:16px}.tools{display:flex;gap:8px;flex-wrap:wrap}.tools form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.tools input[type=text],.tools input[type=file],input,textarea,select{background:#0b1526;color:#e7edf7;border:1px solid #31445f;padding:9px;border-radius:8px;max-width:100%}.tools input[type=text]{width:150px}.msg{padding:12px;border-radius:10px;margin:12px 0}.err{background:#4a2028;color:#ffd4d4}.ok{background:#173d31;color:#c8ffe6}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:12px 9px;border-bottom:1px solid #263750}.table th{color:#93a6c1;font-size:12px}.name{color:#e7edf7;text-decoration:none;overflow-wrap:anywhere}.folder{color:#f4c76b}.actions{display:flex;gap:6px;flex-wrap:wrap}.small{font-size:12px;color:#94a6c0}.editor{width:100%;min-height:360px;font:13px ui-monospace,monospace;white-space:pre;tab-size:4}.editbox{margin-top:18px}.inline{display:inline}.foot{color:#8092ab;font-size:12px;margin-top:18px}@media(max-width:650px){.wrap{padding:13px}.hide{display:none}.table th:nth-child(3),.table td:nth-child(3){display:none}.table td{padding:10px 5px}.actions .btn{padding:7px}}
</style></head><body><main class="wrap">
<div class="top"><div><div class="brand">▦ File Manager</div><div class="sub">Secure website file management · public_html scope</div></div></div>
<?php if($error):?><div class="msg err"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="msg ok"><?=h($notice)?></div><?php endif;?>
<div class="path"><span class="small">LOCATION</span><div style="margin-top:6px">/public_html<?= $dir ? '/' . h($dir) : '' ?></div></div>
<section class="panel"><div class="tools">
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="upload"><input type="file" name="files[]" multiple required><label class="small"><input type="checkbox" name="overwrite" value="1"> Replace existing (backup first)</label><button class="primary">Upload</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="mkdir"><input name="name" type="text" placeholder="New folder" required><button>New folder</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create"><input name="name" type="text" placeholder="New file.txt" required><button>New file</button></form>
</div></section>
<section class="panel" style="padding:0;overflow:hidden"><table class="table"><thead><tr><th>Name</th><th class="hide">Size</th><th class="hide">Modified</th><th>Actions</th></tr></thead><tbody>
<?php if($parent !== null):?><tr><td><a class="name folder" href="?dir=<?=rawurlencode($parent)?>">📁 ..</a></td><td class="hide">—</td><td class="hide">—</td><td>Parent folder</td></tr><?php endif;?>
<?php foreach(array_merge($dirs,$files) as $name): $r=($dir?$dir.'/':'').$name; $p=FM_ROOT.'/'.$r; $isDir=is_dir($p); ?>
<tr><td><a class="name <?=$isDir?'folder':''?>" href="<?=$isDir?'?dir='.rawurlencode($r):'?dir='.rawurlencode($dir).'&edit='.rawurlencode($r)?>"><?=$isDir?'📁':'📄'?> <?=h($name)?></a></td><td class="hide"><?= $isDir?'—':h(fmt_size((int)filesize($p))) ?></td><td class="hide"><?=h(date('Y-m-d H:i',filemtime($p)))?></td><td><div class="actions">
<?php if(!$isDir):?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="download"><input type="hidden" name="path" value="<?=h($r)?>"><button>Download</button></form><?php if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='zip'):?><form method="post" class="inline" onsubmit="return confirm('Extract this ZIP here?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="extract"><input type="hidden" name="path" value="<?=h($r)?>"><button>Extract</button></form><?php endif;?><?php endif;?>
<form method="post" class="inline" onsubmit="let n=prompt('New name','<?=h(addslashes($name))?>');if(!n)return false;this.name.value=n;"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="path" value="<?=h($r)?>"><input type="hidden" name="name"><button>Rename</button></form>
<form method="post" class="inline" onsubmit="return confirm('Delete <?=h(addslashes($name))?>?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="path" value="<?=h($r)?>"><button class="danger">Delete</button></form>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="chmod"><input type="hidden" name="path" value="<?=h($r)?>"><select name="mode"><option value="0644">0644</option><option value="0640">0640</option><option value="0600">0600</option><option value="0755">0755</option><option value="0750">0750</option><option value="0700">0700</option></select><button>Chmod</button></form>
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
<section class="panel editbox"><h3>Edit: <?=h($edit)?></h3><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="path" value="<?=h($edit)?>"><textarea class="editor" name="content" spellcheck="false"><?=h($contents)?></textarea><p class="small">Maximum 2 MB. A backup is made before saving. Saving empty content requires explicit confirmation.</p><label class="small"><input type="checkbox" name="confirm_empty" value="1"> I intentionally want to save an empty file</label><p><button class="primary">Save changes</button> <a class="btn" href="?dir=<?=rawurlencode($dir)?>">Close</a></p></form></section>
<?php } catch(Throwable $e) { echo '<div class="msg err">'.h($e->getMessage()).'</div>'; } endif;?>
<div class="foot">Root is restricted to this public_html directory. Backups are stored in a hidden .fm-backups folder. Keep this manager private and remove it after use.</div>
</main></body></html>