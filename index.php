<?php
$sessionPath = __DIR__ . '/sessions';
$dataDir = __DIR__ . '/data';
$jsonDir = __DIR__ . '/json';

// --- 初始化与权限检查 ---
foreach ([$dataDir, $jsonDir, $sessionPath] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true)) die("无法创建目录: $dir (请检查父目录权限)");
        chmod($dir, 0777);
    }
    if (!is_writable($dir)) {
        die("目录不可写: $dir\n请在宿主机运行: chmod -R 777 data json sessions");
    }
}

session_save_path($sessionPath);
session_start();

// --- 新增：自建 CORS 代理逻辑 ---
if (isset($_GET['url']) && !empty($_GET['url'])) {
    $targetUrl = $_GET['url'];
    if (validateUrl($targetUrl)) {
        $ch = curl_init(normalizeUrlForCurl($targetUrl));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            header("HTTP/1.1 502 Bad Gateway");
            echo "代理请求失败: " . htmlspecialchars(curl_error($ch));
        } else {
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if ($contentType) header("Content-Type: $contentType");
            echo $response;
        }
        curl_close($ch);
        exit;
    }
}

// --- 兼容性定义常量 ---
if (!defined('IDNA_NONSTRICT')) {
    define('IDNA_NONSTRICT', 0);
}
if (!defined('INTL_IDNA_VARIANT_UTS46')) {
    define('INTL_IDNA_VARIANT_UTS46', 1);
}

// --- 目录和文件定义 ---
$proxyFile = $dataDir . '/proxies.json';

// --- 代理管理核心函数 (已重构) ---
function getProxyTypes() {
    return [
        'selfHosted'  => '自建代理 (/?url=...)',
        'allOriginsGet' => 'AllOrigins /get (返回JSON)',
        'allOriginsRaw' => 'AllOrigins /raw (直接返回内容)' // <-- 新增的类型
    ];
}

function initProxies() {
    global $proxyFile;
    $defaultProxies = [
        ['url' => 'http://localhost/', 'name' => '本地CORS代理', 'enabled' => true, 'type' => 'selfHosted'],
        ['url' => 'https://api.allorigins.win', 'name' => 'allorigins.win (公共)', 'enabled' => true, 'type' => 'allOriginsGet']
    ];
    file_put_contents($proxyFile, json_encode($defaultProxies, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $defaultProxies;
}

function getProxies() {
    global $proxyFile;
    if (!file_exists($proxyFile)) {
        return initProxies();
    }
    $proxies = json_decode(file_get_contents($proxyFile), true);
    if (!is_array($proxies)) return initProxies();

    $needsSave = false;
    foreach ($proxies as $i => &$proxy) {
        if (!isset($proxy['type'])) {
            $needsSave = true;
            if (strpos($proxy['url'], 'allorigins.win') !== false) {
                $proxy['type'] = 'allOriginsGet'; // 修正：确保旧数据指向新类型
            } else {
                $proxy['type'] = 'selfHosted';
            }
        }
        // v-- 新增的兼容性代码 --v
        if (isset($proxy['type']) && $proxy['type'] === 'allOrigins') {
            $proxy['type'] = 'allOriginsGet';
            $needsSave = true;
        }
        // 智能修正：将 selfHosted 的 localhost 修正为 127.0.0.1 (Docker 内部兼容性)
        if (isset($proxy['type']) && $proxy['type'] === 'selfHosted' && strpos($proxy['url'], 'localhost') !== false) {
            $proxy['url'] = str_replace('localhost', '127.0.0.1', $proxy['url']);
            $needsSave = true;
        }
        // ^-- 新增的兼容性代码 --^
    }
    if ($needsSave) saveProxies($proxies);
    return $proxies;
}

function saveProxies($proxies) {
    global $proxyFile;
    return file_put_contents($proxyFile, json_encode(array_values($proxies), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// --- 新增：中文URL支持函数 ---
function validateUrl($url) {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) return false;
    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) return false;
    $host = $parsed['host'];
    if (preg_match('/[^\x00-\x7F]/u', $host)) {
        if (!function_exists('idn_to_ascii')) return false;
        $ascii = idn_to_ascii($host, IDNA_NONSTRICT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) return false;
    }
    return true;
}

function normalizeUrlForCurl($url) {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) return false;
    $scheme = $parsed['scheme'];
    $host = $parsed['host'];
    if (preg_match('/[^\x00-\x7F]/u', $host)) {
        if (!function_exists('idn_to_ascii')) return false;
        $host = idn_to_ascii($host, IDNA_NONSTRICT, INTL_IDNA_VARIANT_UTS46);
        if ($host === false) return false;
    }
    $userinfo = '';
    if (isset($parsed['user'])) {
        $userinfo = rawurlencode($parsed['user']);
        if (isset($parsed['pass'])) {
            $userinfo .= ':' . rawurlencode($parsed['pass']);
        }
        $userinfo .= '@';
    }
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    $encoded_path = '';
    if ($path === '/') {
        $encoded_path = '/';
    } else {
        $path_parts = explode('/', $path);
        foreach ($path_parts as $part) {
            if ($part !== '') {
                $encoded_path .= '/' . rawurlencode($part);
            }
        }
        if (str_ends_with($path, '/') && $encoded_path !== '/') {
            $encoded_path .= '/';
        }
    }
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    if ($query && isset($parsed['query'])) {
        parse_str($parsed['query'], $params);
        $new_query = http_build_query($params);
        $query = '?' . $new_query;
    }
    return $scheme . '://' . $userinfo . $host . $port . $encoded_path . $query;
}

// --- 数据库设置 ---
if (!extension_loaded('sqlite3')) die("服务器未启用 SQLite3 扩展，请安装 php-sqlite3");
$dbFile = $dataDir . '/db.sqlite3';
$init = !file_exists($dbFile);
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($init) {
        $db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE,password TEXT)");
        $db->exec("CREATE TABLE apis(id INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER,name TEXT,url TEXT,addtime TEXT,status TEXT DEFAULT 'unknown', sort_order INTEGER DEFAULT 0)");
        $db->exec("CREATE TABLE video_sources(id INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER,name TEXT,api_url TEXT,detail_url TEXT,is_adult INTEGER DEFAULT 0,status TEXT DEFAULT 'unknown', addtime TEXT, sort_order INTEGER DEFAULT 0)");
    } else {
        // 添加 sort_order 列（如果不存在）
        try {
            $db->exec("ALTER TABLE apis ADD COLUMN sort_order INTEGER DEFAULT 0");
        } catch (PDOException $e) {
            // 列可能已存在，忽略
        }
        // 为现有记录设置 sort_order = -id（如果未设置）
        $db->exec("UPDATE apis SET sort_order = -id WHERE sort_order IS NULL OR sort_order = 0");

        // Check if video_sources table exists, create if not
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='video_sources'");
        if ($stmt->fetch() === false) {
            $db->exec("CREATE TABLE video_sources(id INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER,name TEXT,api_url TEXT,detail_url TEXT,is_adult INTEGER DEFAULT 0,status TEXT DEFAULT 'unknown', addtime TEXT, sort_order INTEGER DEFAULT 0)");
        }

        // 添加 sort_order 列（如果不存在）
        try {
            $db->exec("ALTER TABLE video_sources ADD COLUMN sort_order INTEGER DEFAULT 0");
        } catch (PDOException $e) {
            // 列可能已存在，忽略
        }
        // 添加 detail_url 列（如果不存在）
        try {
            $db->exec("ALTER TABLE video_sources ADD COLUMN detail_url TEXT");
        } catch (PDOException $e) {
            // 列可能已存在，忽略
        }
        // 添加 is_adult 列（如果不存在）
        try {
            $db->exec("ALTER TABLE video_sources ADD COLUMN is_adult INTEGER DEFAULT 0");
        } catch (PDOException $e) {
            // 列可能已存在，忽略
        }
        // 为现有记录设置 sort_order = -id（如果未设置）
        $db->exec("UPDATE video_sources SET sort_order = -id WHERE sort_order IS NULL OR sort_order = 0");
    }
} catch (PDOException $e) { die("数据库连接失败: " . $e->getMessage()); }

// --- 辅助函数 ---
function uid() { return $_SESSION['uid'] ?? 0; }
function isLogin() { return uid() > 0; }
function truncateText($text, $length = 20) { return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text; }
function statusToChinese($status) { return ['valid' => '有效', 'invalid' => '无效', 'unknown' => '未知'][$status] ?? $status; }
function adultToChinese($is_adult) { return $is_adult ? '成人' : '常规'; }
function getUsername($db, $uid) {
    try {
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $user['username'] : '未知用户';
    } catch (PDOException $e) {
        return '未知用户';
    }
}
function getNewSortOrder($db, $uid, $table = 'apis') {
    $stmt = $db->prepare("SELECT COALESCE(MIN(sort_order), 0) FROM $table WHERE uid = ?");
    $stmt->execute([$uid]);
    $minOrder = $stmt->fetchColumn();
    return $minOrder - 1;
}

function getMaxSortOrder($db, $uid, $table = 'apis') {
    $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM $table WHERE uid = ?");
    $stmt->execute([$uid]);
    $maxOrder = $stmt->fetchColumn();
    return $maxOrder + 1;
}

function jsonOut($uid) {
    global $db, $jsonDir;
    $stmt = $db->prepare("SELECT name,url FROM apis WHERE uid=? AND status='valid'");
    $stmt->execute([$uid]);
    $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = ["urls" => $urls];
    file_put_contents($jsonDir . '/' . $uid . '.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function videoJsonOut($uid) {
    global $db, $jsonDir;
    $stmt = $db->prepare("SELECT name,api_url as api,detail_url,is_adult FROM video_sources WHERE uid=? AND status='valid'");
    $stmt->execute([$uid]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sites as &$site) {
        $site['is_adult'] = (bool) ($site['is_adult'] ?? false); // 确保 bool 类型
        if (empty($site['detail_url'])) unset($site['detail_url']); // 可选字段
    }
    $out = ["sites" => $sites];
    file_put_contents($jsonDir . '/' . $uid . '_videos.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getApisHtml($uid) {
    global $db;
    ob_start();
    ?>
    <!-- 1. 添加新接口的表单，独立成一个卡片 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="section-header"><h6><i class="bi bi-plus-circle me-1"></i>添加新接口</h6></div>
            <form method="post" action="?a=add&p=apis" class="row g-3">
                <div class="col-md-3"><input type="text" class="form-control" name="name" placeholder="接口名称" required></div>
                <div class="col-md-7"><input type="url" class="form-control" name="url" placeholder="接口URL (支持中文域名/路径)" required></div>
                <div class="col-md-2"><button type="submit" class="btn btn-success w-100"><i class="bi bi-plus"></i> 添加</button></div>
            </form>
        </div>
    </div>

    <!-- 2. 管理接口列表的表单，包含操作按钮和表格 -->
    <form method="post" action="?a=delete_selected" id="apisForm">
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <a href="?a=check_all" class="btn btn-primary btn-sm me-2"><i class="bi bi-check-circle me-1"></i>一键检查</a>
                <button type="submit" class="btn btn-danger btn-sm me-2" onclick="return confirm('确定删除所有选中的接口吗？')"><i class="bi bi-trash me-1"></i>删除选中</button>
                <a href="?a=delete_invalid" class="btn btn-danger btn-sm me-2" onclick="return confirm('确定删除所有无效接口吗？')"><i class="bi bi-trash me-1"></i>一键删除无效</a>
                <a href="?a=delete_duplicates" class="btn btn-warning btn-sm me-2" onclick="return confirm('确定删除重复URL的多余接口吗？（保留最新的一个）')"><i class="bi bi-duplicate me-1"></i>一键删除重复</a>
                <a href="json/<?= $uid ?>.json" target="_blank" class="btn btn-info btn-sm me-2"><i class="bi bi-file-earmark-code me-1"></i>查看JSON</a>
                <button type="button" onclick="copyJsonUrl()" class="btn btn-secondary btn-sm"><i class="bi bi-copy me-1"></i>复制JSON链接</button>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead><tr><th><input type="checkbox" onclick="toggleSelectAll(this)"></th><th>ID</th><th>名称</th><th>URL</th><th>添加时间</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php
                    $stmt = $db->prepare("SELECT * FROM apis WHERE uid=? ORDER BY sort_order DESC, id DESC");
                    $stmt->execute([$uid]);
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $r['id'] ?>"></td>
                        <td><?= $r['id'] ?></td>
                        <td><?= htmlspecialchars(truncateText($r['name'], 30)) ?></td>
                        <td><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" class="text-truncate d-block" style="max-width: 300px;"><?= htmlspecialchars(truncateText($r['url'], 50)) ?></a></td>
                        <td><?= $r['addtime'] ?></td>
                        <td><span class="badge 
        <?= $r['status'] === 'valid' ? 'bg-success' : '' ?> 
        <?= $r['status'] === 'invalid' ? 'bg-danger' : '' ?> 
        status-badge status-<?= $r['status'] ?>">
        <?= statusToChinese($r['status']) ?>
    </span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-info check-single" data-id="<?= $r['id'] ?>"><i class="bi bi-check-circle"></i></button>
                                <button type="button" class="btn btn-outline-secondary move-up" onclick="moveApi(<?= $r['id'] ?>, 'up')" title="移到顶部"><i class="bi bi-arrow-up-circle"></i></button>
                                <button type="button" class="btn btn-outline-secondary move-down" onclick="moveApi(<?= $r['id'] ?>, 'down')" title="移到底部"><i class="bi bi-arrow-down-circle"></i></button>
                                <button type="button" class="btn btn-outline-primary" onclick="prepareEditApi(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars($r['name'])) ?>', '<?= addslashes(htmlspecialchars($r['url'])) ?>')"><i class="bi bi-pencil"></i></button>
                                <a href="?a=del&id=<?= $r['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('确定删除？')"><i class="bi bi-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </form>
    <?php
    return ob_get_clean();
}

function getVideoSourcesHtml($uid) {
    global $db;
    ob_start();
    ?>
    <!-- 1. 添加新影视源的表单，独立成一个卡片 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="section-header"><h6><i class="bi bi-plus-circle me-1"></i>添加新影视源</h6></div>
            <form method="post" action="?a=add_video_source&p=video_sources" class="row g-3">
                <div class="col-md-3"><input type="text" class="form-control" name="name" placeholder="源名称" required></div>
                <div class="col-md-4"><input type="url" class="form-control" name="api_url" placeholder="API URL (e.g., https://example.com/provide/vod)" required></div>
                <div class="col-md-3"><input type="url" class="form-control" name="detail_url" placeholder="详情 URL (可选)"></div>
                <div class="col-md-2">
                    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="is_adult" id="is_adult"><label class="form-check-label" for="is_adult">成人内容</label></div>
                    <button type="submit" class="btn btn-success w-100 mt-2"><i class="bi bi-plus"></i> 添加</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 新增：导入JSON文件表单 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="section-header"><h6><i class="bi bi-upload me-1"></i>导入JSON文件</h6></div>
            <form method="post" action="?a=import_video_sources&p=video_sources" enctype="multipart/form-data" class="row g-3">
                <div class="col-md-8"><input type="file" class="form-control" name="json_file" accept=".json" required></div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload"></i> 导入</button></div>
            </form>
        </div>
    </div>

    <!-- 2. 管理影视源列表的表单，包含操作按钮和表格 -->
    <form method="post" action="?a=delete_video_sources_selected" id="videoSourcesForm">
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
    <a href="?a=check_video_sources_all" class="btn btn-primary btn-sm me-2"><i class="bi bi-check-circle me-1"></i>一键检查</a>
    <button type="submit" class="btn btn-danger btn-sm me-2" onclick="return confirm('确定删除所有选中的影视源吗？')"><i class="bi bi-trash me-1"></i>删除选中</button>
    <a href="?a=delete_video_sources_invalid" class="btn btn-danger btn-sm me-2" onclick="return confirm('确定删除所有无效影视源吗？')"><i class="bi bi-trash me-1"></i>一键删除无效</a>
    <a href="?a=delete_video_sources_duplicates" class="btn btn-warning btn-sm me-2" onclick="return confirm('确定删除重复URL的多余源吗？（保留最新的一个）')"><i class="bi bi-duplicate me-1"></i>一键删除重复</a>
    <a href="json/<?= $uid ?>_videos.json" target="_blank" class="btn btn-info btn-sm me-2"><i class="bi bi-file-earmark-code me-1"></i>视频源JSON</a>
    <a href="json/<?= $uid ?>_videos.json" download class="btn btn-secondary btn-sm me-2"><i class="bi bi-download me-1"></i>导出JSON</a>
    <button type="button" onclick="copyVideoJsonUrl()" class="btn btn-secondary btn-sm"><i class="bi bi-copy me-1"></i>复制JSON链接</button>
     </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead><tr><th><input type="checkbox" onclick="toggleSelectAll(this)"></th><th>ID</th><th>名称</th><th>API URL</th><th>详情 URL</th><th>类型</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php
                    $stmt = $db->prepare("SELECT * FROM video_sources WHERE uid=? ORDER BY sort_order DESC, id DESC");
                    $stmt->execute([$uid]);
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $r['id'] ?>"></td>
                        <td><?= $r['id'] ?></td>
                        <td><?= htmlspecialchars(truncateText($r['name'], 30)) ?></td>
                        <td><a href="<?= htmlspecialchars($r['api_url']) ?>" target="_blank" class="text-truncate d-block" style="max-width: 200px;"><?= htmlspecialchars(truncateText($r['api_url'], 40)) ?></a></td>
                        <td><?= $r['detail_url'] ? htmlspecialchars(truncateText($r['detail_url'], 40)) : '-' ?></td>
                        <td><span class="badge <?= $r['is_adult'] ? 'bg-warning' : 'bg-secondary' ?>"><?= adultToChinese($r['is_adult']) ?></span></td>
                        <td><span class="badge 
        <?= $r['status'] === 'valid' ? 'bg-success' : '' ?> 
        <?= $r['status'] === 'invalid' ? 'bg-danger' : '' ?> 
        status-badge status-<?= $r['status'] ?>">
        <?= statusToChinese($r['status']) ?>
    </span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-info check-single" data-id="<?= $r['id'] ?>"><i class="bi bi-check-circle"></i></button>
                                <button type="button" class="btn btn-outline-secondary move-up" onclick="moveVideoSource(<?= $r['id'] ?>, 'up')" title="移到顶部"><i class="bi bi-arrow-up-circle"></i></button>
                                <button type="button" class="btn btn-outline-secondary move-down" onclick="moveVideoSource(<?= $r['id'] ?>, 'down')" title="移到底部"><i class="bi bi-arrow-down-circle"></i></button>
                                <button type="button" class="btn btn-outline-primary" onclick="prepareEditVideoSource(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars($r['name'])) ?>', '<?= addslashes(htmlspecialchars($r['api_url'])) ?>', '<?= addslashes(htmlspecialchars($r['detail_url'] ?? '')) ?>', <?= $r['is_adult'] ?>)"><i class="bi bi-pencil"></i></button>
                                <a href="?a=del_video_source&id=<?= $r['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('确定删除？')"><i class="bi bi-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </form>
    <?php
    return ob_get_clean();
}

function checkUrlStatus($url) {
    $curlUrl = normalizeUrlForCurl($url);
    if ($curlUrl === false) return 'invalid';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $curlUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_HEADER => false, CURLOPT_RANGE => '0-0',
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 400 ? 'valid' : 'invalid';
}

// --- 请求处理 (Router) ---
$a = $_REQUEST['a'] ?? 'home';
$p = $_REQUEST['p'] ?? ''; // 新增：获取 p 参数，用于重定向
$err = ''; $success = ''; $checkMsg = '';

if ($a === 'logout') { session_destroy(); header("Location: ?"); exit; }

if ($a === 'register' && !isLogin()) {
    if ($_POST) {
        $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? ''); $confirmPassword = trim($_POST['confirm_password'] ?? '');
        if (empty($username) || empty($password) || empty($confirmPassword)) { $err = '所有字段均为必填'; }
        elseif ($password !== $confirmPassword) { $err = '两次密码不匹配'; }
        elseif (strlen($password) < 6) { $err = '密码至少6位'; }
        else {
            try {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?"); $stmt->execute([$username]);
                if ($stmt->fetch()) { $err = '用户名已存在'; }
                else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    $insertStmt->execute([$username, $hashedPassword]);
                    header("Location: ?a=login"); exit;
                }
            } catch (PDOException $e) { $err = '注册失败：' . $e->getMessage(); }
        }
    }
}
if ($a === 'login' && !isLogin()) {
    if ($_POST) {
        $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? '');
        if (empty($username) || empty($password)) { $err = '用户名和密码均为必填'; }
        else {
            try {
                $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?"); $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['uid'] = $user['id']; 
                    session_write_close(); // <-- 新增此行，强制写入 Session
                    header("Location: ?"); exit;
                } else { $err = '用户名或密码错误'; }
            } catch (PDOException $e) { $err = '登录失败：' . $e->getMessage(); }
        }
    }
}
if ($a === 'change_password' && isLogin()) {
    if ($_POST) {
        $oldPassword = trim($_POST['old_password'] ?? ''); $newPassword = trim($_POST['new_password'] ?? ''); $confirmPassword = trim($_POST['confirm_password'] ?? '');
        if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) { $err = '所有字段均为必填'; }
        elseif ($newPassword !== $confirmPassword) { $err = '新密码不匹配'; }
        elseif (strlen($newPassword) < 6) { $err = '新密码至少6位'; }
        else {
            try {
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?"); $stmt->execute([uid()]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (password_verify($oldPassword, $user['password'])) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $updateStmt->execute([$hashedPassword, uid()]);
                    $success = '密码修改成功';
                } else { $err = '旧密码错误'; }
            } catch (PDOException $e) { $err = '修改失败：' . $e->getMessage(); }
        }
    }
}

// 登录后才能访问的功能
if (isLogin()) {
    // 代理管理
    if ($a === 'add_proxy' && $_POST) {
        $proxies = getProxies();
        $proxies[] = ['url' => trim($_POST['proxy_url']), 'name' => trim($_POST['proxy_name']), 'type' => $_POST['proxy_type'], 'enabled' => true];
        saveProxies($proxies);
        header("Location: ?p=proxies"); exit;
    }
    if ($a === 'update_proxy' && $_POST) {
        $proxies = getProxies();
        $index = intval($_POST['index']);
        if (isset($proxies[$index])) {
            $proxies[$index]['url'] = trim($_POST['proxy_url']);
            $proxies[$index]['name'] = trim($_POST['proxy_name']);
            $proxies[$index]['type'] = $_POST['proxy_type'];
            $proxies[$index]['enabled'] = isset($_POST['enabled']);
            saveProxies($proxies);
        }
        header("Location: ?p=proxies"); exit;
    }
    if ($a === 'delete_proxy' && isset($_GET['index'])) {
        $proxies = getProxies();
        $index = intval($_GET['index']);
        if ($index > 0 && isset($proxies[$index])) { // 不允许删除第一个
            unset($proxies[$index]);
            saveProxies($proxies);
        }
        header("Location: ?p=proxies"); exit;
    }
    // 这是最终的、智能的、对症下药的版本
    if ($a === 'check_proxies') {
        session_write_close(); // 关键修复：释放会话锁，防止自检代理时产生死锁
        $proxyStatus = [];
        $proxies = getProxies();
        
        // V7 终极智能修复: 针对不同代理类型，使用不同的检测目标和请求头
        $globalTestUrl = 'https://www.github.com/robots.txt'; // 用于全球公共代理
        $localTestUrl = 'https://www.baidu.com/robots.txt';    // 用于自建代理(通常在国内环境)

        foreach ($proxies as $proxy) {
            if (!$proxy['enabled']) continue;

            $proxyBaseUrl = rtrim(explode('?', $proxy['url'])[0], '/');
            $fullUrl = '';
            $curlOptions = [ // 基础 cURL 选项
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5, // 优化：缩短超时时间
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_NOBODY => true, // 优化：仅检查头，不下载内容
                CURLOPT_CONNECTTIMEOUT => 3, // 优化：缩短连接超时
                CURLOPT_SSL_VERIFYPEER => false, // 允许自签名证书或本地 HTTPS
                CURLOPT_SSL_VERIFYHOST => false
            ];

            // --- 核心逻辑: 对症下药 ---
            if ($proxy['type'] === 'selfHosted') {
                // 对自建代理: 使用国内目标，且不加UA头，避免其自身程序崩溃
                $fullUrl = $proxyBaseUrl . '/?url=' . urlencode($localTestUrl);
            } else {
                // 对AllOrigins等公共代理: 使用全球目标，并必须加上UA头
                $fullUrl = ($proxy['type'] === 'allOriginsGet') 
                    ? $proxyBaseUrl . '/get?url=' . urlencode($globalTestUrl)
                    : $proxyBaseUrl . '/raw?url=' . urlencode($globalTestUrl);

                $curlOptions[CURLOPT_USERAGENT] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
            }

            $ch = curl_init($fullUrl);
            curl_setopt_array($ch, $curlOptions);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $isValid = ($httpCode >= 200 && $httpCode < 400);
            $proxyStatus[] = ['name' => $proxy['name'], 'status' => $isValid ? '有效' : '无效', 'code' => $httpCode];
        }
        header('Content-Type: application/json'); echo json_encode($proxyStatus); exit;
    }
    // --- 新增：接口上下移功能 ---
    if ($a === 'move_up' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $currStmt = $db->prepare("SELECT sort_order FROM apis WHERE id = ? AND uid = ?");
        $currStmt->execute([$id, uid()]);
        $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            $err = '接口不存在';
        } else {
            $newOrder = getMaxSortOrder($db, uid(), 'apis');
            $db->prepare("UPDATE apis SET sort_order = ? WHERE id = ? AND uid = ?")->execute([$newOrder, $id, uid()]);
            $success = '移到顶部成功';
        }
        jsonOut(uid());
        header("Location: ?p=apis"); exit; // 确保重定向回 apis 页面
    }
    if ($a === 'move_down' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $currStmt = $db->prepare("SELECT sort_order FROM apis WHERE id = ? AND uid = ?");
        $currStmt->execute([$id, uid()]);
        $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            $err = '接口不存在';
        } else {
            $newOrder = getNewSortOrder($db, uid(), 'apis');
            $db->prepare("UPDATE apis SET sort_order = ? WHERE id = ? AND uid = ?")->execute([$newOrder, $id, uid()]);
            $success = '移到底部成功';
        }
        jsonOut(uid());
        header("Location: ?p=apis"); exit; // 确保重定向回 apis 页面
    }
    // --- 新增：影视源上下移功能 ---
    if ($a === 'move_video_source_up' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $currStmt = $db->prepare("SELECT sort_order FROM video_sources WHERE id = ? AND uid = ?");
        $currStmt->execute([$id, uid()]);
        $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            $err = '影视源不存在';
        } else {
            $newOrder = getMaxSortOrder($db, uid(), 'video_sources');
            $db->prepare("UPDATE video_sources SET sort_order = ? WHERE id = ? AND uid = ?")->execute([$newOrder, $id, uid()]);
            $success = '移到顶部成功';
        }
        videoJsonOut(uid());
        header("Location: ?p=video_sources"); exit; // 确保重定向回 video_sources 页面
    }
    if ($a === 'move_video_source_down' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $currStmt = $db->prepare("SELECT sort_order FROM video_sources WHERE id = ? AND uid = ?");
        $currStmt->execute([$id, uid()]);
        $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            $err = '影视源不存在';
        } else {
            $newOrder = getNewSortOrder($db, uid(), 'video_sources');
            $db->prepare("UPDATE video_sources SET sort_order = ? WHERE id = ? AND uid = ?")->execute([$newOrder, $id, uid()]);
            $success = '移到底部成功';
        }
        videoJsonOut(uid());
        header("Location: ?p=video_sources"); exit; // 确保重定向回 video_sources 页面
    }
    // API 管理
    if ($a === 'add_client_fetched' && $_POST) { // (已修复状态同步)
        $selected = json_decode($_POST['selected'] ?? '[]', true);
        if (!is_array($selected)) {
            header('Content-Type: application/json');
            echo json_encode(['message' => '错误: 数据格式不正确。', 'apisHtml' => getApisHtml(uid())]); exit;
        }
        $addedCount = 0; $skippedCount = 0; $now = date('Y-m-d H:i:s');
        $minOrder = getNewSortOrder($db, uid());
        $stmtCheck = $db->prepare("SELECT id FROM apis WHERE uid=? AND url=?");
        $stmtInsert = $db->prepare("INSERT INTO apis (uid,name,url,addtime,status, sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($selected as $api) {
            if (isset($api['url']) && validateUrl($api['url'])) {
                $stmtCheck->execute([uid(), $api['url']]);
                if ($stmtCheck->fetch()) {
                    $skippedCount++; // 跳过重复URL
                } else {
                    $stmtInsert->execute([uid(), $api['name'], $api['url'], $now, 'unknown', $minOrder]);
                    $minOrder--;
                    if($stmtInsert->rowCount() > 0) $addedCount++;
                }
            } else { $skippedCount++; }
        }
        jsonOut(uid());
        header('Content-Type: application/json');
        echo json_encode([
            'message' => "处理完成: 添加 {$addedCount}, 跳过 {$skippedCount} (包括重复URL和无效项)。",
            'apisHtml' => getApisHtml(uid())
        ]);
        exit;
    }
    if ($a === 'add' && $_POST) {
        $name = trim($_POST['name']); $url = trim($_POST['url']);
        if (empty($name)) {
            $err = '无效的名称';
        } elseif (!validateUrl($url)) {
            $err = '无效的URL';
        } else {
            $checkStmt = $db->prepare("SELECT id FROM apis WHERE uid=? AND url=?");
            $checkStmt->execute([uid(), $url]);
            if ($checkStmt->fetch()) {
                $err = '该URL已存在';
            } else {
                $newOrder = getNewSortOrder($db, uid());
                $stmt = $db->prepare("INSERT INTO apis(uid,name,url,addtime,status, sort_order) VALUES(?,?,?,datetime('now','localtime'),'unknown',?)");
                $stmt->execute([uid(), $name, $url, $newOrder]);
                jsonOut(uid());
                $success = '接口添加成功';
            }
        }
        header("Location: ?p=apis"); exit; // 确保添加后重定向回 apis 页面
    }
    if ($a === 'edit_api' && $_POST) {
        $postId = intval($_POST['id']); $name = trim($_POST['name'] ?? ''); $url = trim($_POST['url'] ?? '');
        if (empty($name)) {
            $err = '无效的名称';
        } elseif (!validateUrl($url)) {
            $err = '无效的URL';
        } else {
            $checkStmt = $db->prepare("SELECT id FROM apis WHERE uid=? AND url=? AND id != ?");
            $checkStmt->execute([uid(), $url, $postId]);
            if ($checkStmt->fetch()) {
                $err = '该URL已存在';
            } else {
                $stmt = $db->prepare("UPDATE apis SET name=?,url=? WHERE id=? AND uid=?");
                $stmt->execute([$name, $url, $postId, uid()]);
                jsonOut(uid());
                $success = '接口已更新';
            }
        }
        header("Location: ?p=apis"); exit; // 确保编辑后重定向回 apis 页面
    }
    if ($a === 'del' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $db->prepare("DELETE FROM apis WHERE id=? AND uid=?"); $stmt->execute([$id, uid()]);
        jsonOut(uid()); $success = '接口删除成功';
        header("Location: ?p=apis"); exit; // 确保删除后重定向回 apis 页面
    }
    if ($a === 'delete_selected' && $_POST) {
        if (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            if (!empty($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM apis WHERE id IN ($placeholders) AND uid=?");
                $stmt->execute(array_merge($ids, [uid()]));
                $deleted = $stmt->rowCount();
                jsonOut(uid());
                $success = "已删除 {$deleted} 个选中的接口";
            } else {
                $err = '未选中任何接口';
            }
        } else {
            $err = '无效的选择';
        }
        header("Location: ?p=apis"); exit; // 确保操作后重定向回 apis 页面
    }
    if ($a === 'check_single' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $db->prepare("SELECT url FROM apis WHERE id=? AND uid=?");
        $stmt->execute([$id, uid()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $status = checkUrlStatus($r['url']);
            $upStmt = $db->prepare("UPDATE apis SET status=? WHERE id=?"); $upStmt->execute([$status, $id]);
            jsonOut(uid());
            header('Content-Type: application/json');
            echo json_encode(['status' => $status]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => '接口不存在']);
        }
        exit;
    }
    if ($a === 'check_all') {
        session_write_close(); // 释放会话锁，允许用户在检查期间继续浏览或进行其他操作
        set_time_limit(0); // 防止超时
        $stmt = $db->prepare("SELECT id, url FROM apis WHERE uid=?"); $stmt->execute([uid()]);
        $apis = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $batchSize = 10; // 每批处理10个
        $chunks = array_chunk($apis, $batchSize);
        $updated = 0;
        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $r) {
                $curlUrl = normalizeUrlForCurl($r['url']);
                if ($curlUrl === false) {
                    $status = 'invalid';
                    $upStmt = $db->prepare("UPDATE apis SET status=? WHERE id=?"); $upStmt->execute([$status, $r['id']]);
                    $updated++;
                    continue;
                }
                $ch = curl_init($curlUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_HEADER => false,
                    CURLOPT_RANGE => '0-0',
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$r['id']] = $ch;
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.1);
                }
            } while ($running > 0);
            foreach ($handles as $id => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $status = ($httpCode >= 200 && $httpCode < 400) ? 'valid' : 'invalid';
                $upStmt = $db->prepare("UPDATE apis SET status=? WHERE id=?"); $upStmt->execute([$status, $id]);
                curl_multi_remove_handle($mh, $ch);
                $updated++;
            }
            curl_multi_close($mh);
        }
        jsonOut(uid()); $checkMsg = "检查完成，共更新 $updated 个接口状态";
        header("Location: ?p=apis"); exit; // 确保操作后重定向回 apis 页面
    }
    if ($a === 'delete_invalid') {
        try {
            $stmt = $db->prepare("DELETE FROM apis WHERE uid = :uid AND status = 'invalid'");
            $stmt->bindParam(':uid', $_SESSION['uid'], PDO::PARAM_INT); $stmt->execute();
            $deletedRows = $stmt->rowCount(); jsonOut(uid());
            $checkMsg = "已成功删除 $deletedRows 个无效接口";
        } catch (PDOException $e) { $err = '删除失败：' . $e->getMessage(); }
        header("Location: ?p=apis"); exit; // 确保操作后重定向回 apis 页面
    }
    if ($a === 'delete_duplicates') {
        try {
            $stmt = $db->prepare("SELECT url, COUNT(*) as cnt FROM apis WHERE uid=? GROUP BY url HAVING cnt > 1");
            $stmt->execute([uid()]);
            $dups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $deleted = 0;
            foreach ($dups as $dup) {
                $keepStmt = $db->prepare("SELECT id FROM apis WHERE uid=? AND url=? ORDER BY id DESC LIMIT 1");
                $keepStmt->execute([uid(), $dup['url']]);
                $keepId = $keepStmt->fetchColumn();
                $delStmt = $db->prepare("DELETE FROM apis WHERE uid=? AND url=? AND id != ?");
                $delStmt->execute([uid(), $dup['url'], $keepId]);
                $deleted += $delStmt->rowCount();
            }
            jsonOut(uid());
            $checkMsg = "已删除 $deleted 个重复接口（保留最新的一个）";
        } catch (PDOException $e) { $err = '删除重复失败：' . $e->getMessage(); }
        header("Location: ?p=apis"); exit; // 确保操作后重定向回 apis 页面
    }

    // --- 新增：影视源管理功能 ---
    if ($a === 'add_video_source' && $_POST) {
        $name = trim($_POST['name'] ?? ''); $api_url = trim($_POST['api_url'] ?? ''); $detail_url = trim($_POST['detail_url'] ?? '');
        $is_adult = isset($_POST['is_adult']) ? 1 : 0;
        if (empty($name)) {
            $err = '无效的名称';
        } elseif (!validateUrl($api_url)) {
            $err = '无效的API URL';
        } elseif (!empty($detail_url) && !validateUrl($detail_url)) {
            $err = '无效的详情 URL';
        } else {
            $checkStmt = $db->prepare("SELECT id FROM video_sources WHERE uid=? AND api_url=?");
            $checkStmt->execute([uid(), $api_url]);
            if ($checkStmt->fetch()) {
                $err = '该API URL已存在';
            } else {
                $newOrder = getNewSortOrder($db, uid(), 'video_sources');
                $stmt = $db->prepare("INSERT INTO video_sources(uid,name,api_url,detail_url,is_adult,addtime,status, sort_order) VALUES(?,?,?,?,?,?,?,?)");
                $stmt->execute([uid(), $name, $api_url, $detail_url, $is_adult, date('Y-m-d H:i:s'), 'unknown', $newOrder]);
                videoJsonOut(uid());
                $success = '影视源添加成功';
            }
        }
        header("Location: ?p=video_sources"); exit; // 确保添加后重定向回 video_sources 页面
    }
    if ($a === 'edit_video_source' && $_POST) {
        $postId = intval($_POST['id']); $name = trim($_POST['name'] ?? ''); $api_url = trim($_POST['api_url'] ?? ''); $detail_url = trim($_POST['detail_url'] ?? '');
        $is_adult = isset($_POST['is_adult']) ? 1 : 0;
        if (empty($name)) {
            $err = '无效的名称';
        } elseif (!validateUrl($api_url)) {
            $err = '无效的API URL';
        } elseif (!empty($detail_url) && !validateUrl($detail_url)) {
            $err = '无效的详情 URL';
        } else {
            $checkStmt = $db->prepare("SELECT id FROM video_sources WHERE uid=? AND api_url=? AND id != ?");
            $checkStmt->execute([uid(), $api_url, $postId]);
            if ($checkStmt->fetch()) {
                $err = '该API URL已存在';
            } else {
                $stmt = $db->prepare("UPDATE video_sources SET name=?,api_url=?,detail_url=?,is_adult=? WHERE id=? AND uid=?");
                $stmt->execute([$name, $api_url, $detail_url, $is_adult, $postId, uid()]);
                videoJsonOut(uid());
                $success = '影视源已更新';
            }
        }
        header("Location: ?p=video_sources"); exit; // 确保编辑后重定向回 video_sources 页面
    }
    if ($a === 'del_video_source' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $db->prepare("DELETE FROM video_sources WHERE id=? AND uid=?"); $stmt->execute([$id, uid()]);
        videoJsonOut(uid()); $success = '影视源删除成功';
        header("Location: ?p=video_sources"); exit; // 确保删除后重定向回 video_sources 页面
    }
    if ($a === 'delete_video_sources_selected' && $_POST) {
        if (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            if (!empty($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM video_sources WHERE id IN ($placeholders) AND uid=?");
                $stmt->execute(array_merge($ids, [uid()]));
                $deleted = $stmt->rowCount();
                videoJsonOut(uid());
                $success = "已删除 {$deleted} 个选中的影视源";
            } else {
                $err = '未选中任何影视源';
            }
        } else {
            $err = '无效的选择';
        }
        header("Location: ?p=video_sources"); exit; // 确保操作后重定向回 video_sources 页面
    }
    if ($a === 'check_video_source_single' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $db->prepare("SELECT api_url FROM video_sources WHERE id=? AND uid=?");
        $stmt->execute([$id, uid()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $status = checkUrlStatus($r['api_url']);
            $upStmt = $db->prepare("UPDATE video_sources SET status=? WHERE id=?"); $upStmt->execute([$status, $id]);
            videoJsonOut(uid());
            header('Content-Type: application/json');
            echo json_encode(['status' => $status]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => '影视源不存在']);
        }
        exit;
    }
    if ($a === 'check_video_sources_all') {
        session_write_close(); // 释放会话锁
        set_time_limit(0); // 防止超时
        $stmt = $db->prepare("SELECT id, api_url FROM video_sources WHERE uid=?"); $stmt->execute([uid()]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $batchSize = 10; // 每批处理10个
        $chunks = array_chunk($sources, $batchSize);
        $updated = 0;
        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $r) {
                $curlUrl = normalizeUrlForCurl($r['api_url']);
                if ($curlUrl === false) {
                    $status = 'invalid';
                    $upStmt = $db->prepare("UPDATE video_sources SET status=? WHERE id=?"); $upStmt->execute([$status, $r['id']]);
                    $updated++;
                    continue;
                }
                $ch = curl_init($curlUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_HEADER => false,
                    CURLOPT_RANGE => '0-0',
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$r['id']] = $ch;
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.1);
                }
            } while ($running > 0);
            foreach ($handles as $id => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $status = ($httpCode >= 200 && $httpCode < 400) ? 'valid' : 'invalid';
                $upStmt = $db->prepare("UPDATE video_sources SET status=? WHERE id=?"); $upStmt->execute([$status, $id]);
                curl_multi_remove_handle($mh, $ch);
                $updated++;
            }
            curl_multi_close($mh);
        }
        videoJsonOut(uid()); $checkMsg = "检查完成，共更新 $updated 个影视源状态";
        header("Location: ?p=video_sources"); exit; // 确保操作后重定向回 video_sources 页面
    }
    if ($a === 'delete_video_sources_invalid') {
        try {
            $stmt = $db->prepare("DELETE FROM video_sources WHERE uid = :uid AND status = 'invalid'");
            $stmt->bindParam(':uid', $_SESSION['uid'], PDO::PARAM_INT); $stmt->execute();
            $deletedRows = $stmt->rowCount(); videoJsonOut(uid());
            $checkMsg = "已成功删除 $deletedRows 个无效影视源";
        } catch (PDOException $e) { $err = '删除失败：' . $e->getMessage(); }
        header("Location: ?p=video_sources"); exit; // 确保操作后重定向回 video_sources 页面
    }
    // 新增：导入JSON文件
    if ($a === 'import_video_sources' && isLogin()) {
        if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
            $jsonContent = file_get_contents($_FILES['json_file']['tmp_name']);
            $data = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $err = 'JSON 格式无效: ' . json_last_error_msg();
            } else {
                $sites = [];
                $skippedDuplicates = 0;
                $addedCount = 0;
                
                // 兼容旧格式 {"api_site": {key: {name, api, detail, is_adult}}}
                if (isset($data['api_site']) && is_array($data['api_site'])) {
                    foreach ($data['api_site'] as $key => $site) {
                        $sites[] = [
                            'name' => $site['name'] ?? ucfirst($key),
                            'api_url' => $site['api'] ?? '',
                            'detail_url' => $site['detail'] ?? '',
                            'is_adult' => isset($site['is_adult']) ? 1 : 0,
                            'addtime' => $site['addtime'] ?? date('Y-m-d H:i:s')
                        ];
                    }
                } 
                // 新格式 {"sites": [{name, api_url, detail_url, is_adult}]}
                elseif (isset($data['sites']) && is_array($data['sites'])) {
                    foreach ($data['sites'] as $site) {
                        $sites[] = [
                            'name' => $site['name'] ?? '',
                            'api_url' => $site['api_url'] ?? $site['api'] ?? '',
                            'detail_url' => $site['detail_url'] ?? $site['detail'] ?? '',
                            'is_adult' => $site['is_adult'] ?? 0,
                            'addtime' => date('Y-m-d H:i:s')
                        ];
                    }
                } else {
                    $err = 'JSON 格式无效：缺少 "sites" 数组或兼容的 "api_site" 对象';
                }
                
                if (!empty($sites)) {
                    $checkStmt = $db->prepare("SELECT id FROM video_sources WHERE uid=? AND api_url=?");
                    $stmtInsert = $db->prepare("INSERT INTO video_sources (uid, name, api_url, detail_url, is_adult, addtime, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, 'unknown', ?)");
                    
                    foreach ($sites as $site) {
                        $name = trim($site['name'] ?? '');
                        $api_url = trim($site['api_url'] ?? '');
                        $detail_url = trim($site['detail_url'] ?? '');
                        $is_adult = $site['is_adult'] ?? 0;
                        $addtime = $site['addtime'] ?? date('Y-m-d H:i:s');
                        
                        if (empty($name) || empty($api_url) || !validateUrl($api_url)) {
                            continue;
                        }
                        
                        $checkStmt->execute([uid(), $api_url]);
                        if ($checkStmt->fetch()) {
                            $skippedDuplicates++;
                            continue;
                        }
                        
                        $sort_order = getNewSortOrder($db, uid(), 'video_sources');
                        
                        try {
                            $stmtInsert->execute([uid(), $name, $api_url, $detail_url, $is_adult, $addtime, $sort_order]);
                            $addedCount++;
                        } catch (PDOException $e) {
                            file_put_contents($dataDir . '/error_log.txt', date('Y-m-d H:i:s') . " 导入错误: " . $e->getMessage() . "\n", FILE_APPEND);
                        }
                    }
                    videoJsonOut(uid());
                    $success = "成功导入 $addedCount 个视频源（跳过 $skippedDuplicates 个重复）！";
                    header("Location: ?p=video_sources"); exit;
                } else {
                    $err = 'JSON 格式无效：缺少 "sites" 数组或兼容的 "api_site" 对象';
                }
            }
        } else {
            $err = '文件上传失败';
        }
        header("Location: ?p=video_sources"); exit;
    }
    if ($a === 'delete_video_sources_duplicates' && isLogin()) {
        try {
            // 查找重复的 api_url 组（每个组保留最新的 id）
            $duplicatesQuery = $db->query("SELECT api_url, COUNT(*) as count, MAX(id) as max_id FROM video_sources WHERE uid = " . uid() . " GROUP BY api_url HAVING count > 1");
            $duplicates = $duplicatesQuery->fetchAll(PDO::FETCH_ASSOC);
            
            $deletedCount = 0;
            $stmtDelete = $db->prepare("DELETE FROM video_sources WHERE uid = ? AND api_url = ? AND id <> ?");
            
            foreach ($duplicates as $dup) {
                $stmtDelete->execute([uid(), $dup['api_url'], $dup['max_id']]);
                $deletedCount += $stmtDelete->rowCount();
            }
            
            videoJsonOut(uid());  // 更新 JSON
            $success = "成功删除 $deletedCount 个重复视频源（保留最新）！";
        } catch (PDOException $e) {
            $err = '删除重复失败: ' . $e->getMessage();
        }
        header("Location: ?p=video_sources"); exit;
    }
}
// 为 change_password 生成 HTML（如果适用）
$changePasswordHtml = '';
if ($a === 'change_password' && isLogin()) {
    ob_start();
    if ($err) echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    if ($success) echo '<div class="alert alert-success">' . htmlspecialchars($success) . '</div>';
    ?>
    <div class="card"><div class="card-body"><h5 class="card-title">修改密码</h5><form method="post" action="?a=change_password"><div class="mb-3"><label class="form-label">旧密码</label><input type="password" class="form-control" name="old_password" required></div><div class="mb-3"><label class="form-label">新密码</label><input type="password" class="form-control" name="new_password" required></div><div class="mb-3"><label class="form-label">确认新密码</label><input type="password" class="form-control" name="confirm_password" required></div><button type="submit" class="btn btn-primary">修改</button></form></div></div>
    <?php
    $changePasswordHtml = ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>接口管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
    /* --- 全局基础样式 --- */
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    /* --- 状态 1: 用户未登录 (.logged-out) --- */
    /* 只对未登录的页面生效，让登录框居中 */
    body.logged-out {
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        padding: 20px;
    }
    body.logged-out .main-content {
        width: 100%;
        max-width: 400px; /* 限制宽度，确保表单不拉伸 */
        padding: 0; /* 移除主内容的padding，让flex直接居中 */
    }
    .login-container {
        width: 100%;
        padding: 40px 30px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        animation: fadeInUp 0.6s ease-out;
    }
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .login-container .card-title {
        text-align: center;
        color: #333;
        margin-bottom: 2rem;
        font-weight: 600;
    }
    .login-container .form-control {
        border-radius: 8px;
        border: 1px solid #e1e5e9;
        padding: 12px 16px;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    }
    .login-container .form-control:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
    }
    .login-container .btn-primary {
        border-radius: 8px;
        padding: 12px;
        font-weight: 500;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        border: none;
        width: 100%;
    }
    .login-container .text-center a {
        color: #4f46e5;
        text-decoration: none;
        font-weight: 500;
    }
    .login-container .text-center a:hover {
        text-decoration: underline;
    }

    /* --- 状态 2: 用户已登录 (.logged-in) --- */
    /* 只对已登录的页面生效，恢复正常的仪表盘布局 */
    body.logged-in {
        display: flex; /* 这里用flex是为了左右布局 */
        min-height: 100vh; /* 改为min-height，避免固定高度限制滚动 */
    }
    .sidebar {
        background-color: #343a40;
        color: white;
        padding: 15px;
        height: 100vh;
        overflow-y: auto;
        transition: transform 0.3s ease-in-out; /* 平滑过渡 */
    }
    .main-content {
        flex-grow: 1; /* 占据所有剩余空间 */
        padding: 20px;
        overflow-y: auto; /* 主内容区可以滚动 */
        transition: margin-left 0.3s ease-in-out; /* 平滑过渡 */
    }

    /* --- 桌面端 (min-width: 769px) --- */
    @media (min-width: 769px) {
        body.logged-in .sidebar {
            width: 240px;
            flex-shrink: 0; /* 防止侧边栏被压缩 */
            position: fixed; /* 固定侧边栏 */
            left: 0;
            top: 0;
            z-index: 1000;
            transform: none !important; /* 强制不隐藏 */
            display: block !important; /* 强制显示 */
            visibility: visible !important;
            /* 移除 offcanvas 相关样式，确保像普通 sidebar */
            border-right: 1px solid #dee2e6 !important;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
        }
        body.logged-in .main-content {
            margin-left: 240px; /* 给主内容留出侧边栏空间 */
        }
        /* 隐藏手机导航栏 */
        .navbar.d-md-none {
            display: none !important;
        }
        /* 桌面端隐藏offcanvas关闭按钮和header */
        .offcanvas-header .btn-close,
        .offcanvas-header h5 {
            display: none !important;
        }
        .offcanvas {
            background: #343a40 !important;
            border: none !important;
            box-shadow: none !important;
            /* 确保桌面端不应用 offcanvas 动画和隐藏 */
            transform: none !important;
            visibility: visible !important;
            width: 240px !important;
            position: fixed !important;
        }
        .offcanvas-body {
            padding: 0 !important; /* 移除 offcanvas-body 的额外 padding */
        }
    }

    /* --- 手机端 (max-width: 768px) --- */
    @media (max-width: 768px) {
        body.logged-in {
            flex-direction: column; /* 手机端垂直布局 */
        }
        body.logged-in .sidebar {
            width: 280px; /* offcanvas宽度 */
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1045; /* Bootstrap offcanvas z-index */
            transform: translateX(-100%); /* 默认隐藏 */
            display: block; /* 允许offcanvas显示 */
        }
        body.logged-in .main-content {
            margin-left: 0; /* 手机端无margin */
            width: 100%;
            height: 100vh; /* 全屏高度 */
            overflow-y: auto; /* 允许滚动 */
            padding-top: 56px; /* 抵消fixed-top navbar高度 */
        }
        /* offcanvas 显示时，覆盖主内容 */
        .offcanvas.show .sidebar {
            transform: translateX(0);
        }
        /* 手机导航栏显示 */
        .navbar.d-md-none {
            display: block !important;
            z-index: 1040;
        }
        /* 手机端登录容器优化 */
        body.logged-out {
            padding: 10px;
        }
        body.logged-out .main-content {
            max-width: none; /* 手机端允许全宽 */
            padding: 0;
        }
        .login-container {
            padding: 30px 20px;
            margin: 0 auto;
        }
    }
    /* 添加到现有的 <style> 部分 */
.sidebar .text-white {
    font-size: 0.9rem;
    margin: 10px 0;
    opacity: 0.9;
}
.navbar-text {
    font-size: 0.9rem;
    color: #ffffff !important;
}
</style>
</head>
<body class="<?php echo isLogin() ? 'logged-in' : 'logged-out'; ?>">
    <?php if (isLogin()): ?>
<nav class="navbar navbar-dark bg-dark d-md-none fixed-top">  <!-- 手机端固定顶部导航 -->
    <div class="container-fluid">
        <a class="navbar-brand" href="#">接口管理</a>
        <span class="navbar-text me-3"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars(getUsername($db, uid())) ?></span>
        <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
            <span class="navbar-toggler-icon"></span>
        </button>
    </div>
</nav>
<?php endif; ?>
    <?php if (isLogin()): ?>
<div class="offcanvas offcanvas-start sidebar d-md-block" id="sidebar" tabindex="-1" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="sidebarLabel">菜单</h5>
        <button type="button" class="btn-close btn-close-white d-md-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <h4><i class="bi bi-hdd-stack"></i> 接口管理</h4>
        <p class="text-white"><i class="bi bi-person-circle me-2"></i> 当前用户: <?= htmlspecialchars(getUsername($db, uid())) ?></p>
        <hr>
        <ul class="nav flex-column">
            <?php if (isLogin()): ?>
            <li class="nav-item"><a class="nav-link text-white" href="#" onclick="loadSection('apis', event)"><i class="bi bi-card-list me-2"></i>接口聚合</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="#" onclick="loadSection('client', event)"><i class="bi bi-cloud-arrow-down me-2"></i>影视采集</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="#" onclick="loadSection('proxies', event)"><i class="bi bi-shield-lock me-2"></i>代理管理</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="#" onclick="loadSection('video_sources', event)"><i class="bi bi-film me-2"></i>接口配置</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="#" onclick="loadSection('change_password', event)"><i class="bi bi-key me-2"></i>修改密码</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?a=logout"><i class="bi bi-box-arrow-right me-2"></i>退出登录</a></li>
            <?php else: ?>
            <li class="nav-item"><a class="nav-link text-white" href="?a=login">登录</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?a=register">注册</a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

    <div class="main-content">
        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($checkMsg): ?><div class="alert alert-info"><?= htmlspecialchars($checkMsg) ?></div><?php endif; ?>

        <?php if ($a === 'register'): ?>
            <div class="login-container">
                <div class="card"><div class="card-body p-0"><h5 class="card-title">用户注册</h5><form method="post" action="?a=register"><div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="username" required></div><div class="mb-3"><label class="form-label">密码 (至少6位)</label><input type="password" class="form-control" name="password" required></div><div class="mb-3"><label class="form-label">确认密码</label><input type="password" class="form-control" name="confirm_password" required></div><button type="submit" class="btn btn-primary">注册</button><div class="text-center mt-3"><a href="?a=login">已有账户？立即登录</a></div></form></div></div>
            </div>
        <?php elseif ($a === 'login'): ?>
            <div class="login-container">
                <div class="card"><div class="card-body p-0"><h5 class="card-title">用户登录</h5><form method="post" action="?a=login"><div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="username" required autofocus></div><div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control" name="password" required></div><button type="submit" class="btn btn-primary">登录</button><div class="text-center mt-3"><a href="?a=register">还没有账户？立即注册</a></div></form></div></div>
            </div>
        <?php elseif (!isLogin()): ?>
            <!-- 默认登录表单 -->
            <div class="login-container">
                <div class="card"><div class="card-body p-0"><h5 class="card-title">用户登录</h5><form method="post" action="?a=login"><div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="username" required autofocus></div><div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control" name="password" required></div><button type="submit" class="btn btn-primary">登录</button><div class="text-center mt-3"><a href="?a=register">还没有账户？立即注册</a></div></form></div></div>
            </div>
        <?php else: // 已登录用户：始终渲染容器 ?>
            <div id="main-content-area" style="padding-top: 0;">
                <?php if ($a === 'change_password'): ?>
                    <?= $changePasswordHtml ?>
                <?php endif; ?>
                <!-- 其他情况留空，由 JS loadSection 填充 -->
            </div>
        <?php endif; ?>

    </div>

    <!-- Modals -->
    <div class="modal fade" id="editApiModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">编辑接口</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post" action="?a=edit_api&p=apis"><div class="modal-body"><input type="hidden" name="id" id="edit_api_id"><div class="mb-3"><label class="form-label">名称</label><input type="text" class="form-control" name="name" id="edit_api_name" required></div><div class="mb-3"><label class="form-label">URL</label><input type="url" class="form-control" name="url" id="edit_api_url" placeholder="支持中文域名/路径" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div></div>
    <div class="modal fade" id="editVideoSourceModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">编辑影视源</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post" action="?a=edit_video_source&p=video_sources"><div class="modal-body"><input type="hidden" name="id" id="edit_video_source_id"><div class="mb-3"><label class="form-label">名称</label><input type="text" class="form-control" name="name" id="edit_video_source_name" required></div><div class="mb-3"><label class="form-label">API URL</label><input type="url" class="form-control" name="api_url" id="edit_video_source_api_url" required></div><div class="mb-3"><label class="form-label">详情 URL (可选)</label><input type="url" class="form-control" name="detail_url" id="edit_video_source_detail_url"></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_adult" id="edit_video_source_is_adult"><label class="form-check-label" for="edit_video_source_is_adult">成人内容</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div></div>
    <div class="modal fade" id="editProxyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">编辑代理</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post" action="?a=update_proxy"><div class="modal-body"><input type="hidden" name="index" id="edit_proxy_index"><div class="mb-3"><label class="form-label">名称</label><input type="text" class="form-control" name="proxy_name" id="edit_proxy_name" required></div><div class="mb-3"><label class="form-label">URL</label><input type="url" class="form-control" name="proxy_url" id="edit_proxy_url" required></div><div class="mb-3"><label class="form-label">类型</label><select class="form-select" name="proxy_type" id="edit_proxy_type"><?php foreach (getProxyTypes() as $key => $name): ?><option value="<?= $key ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="edit_proxy_enabled"><label class="form-check-label" for="edit_proxy_enabled">启用</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div></div>

    <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script>
        const PROXY_CONFIG = {
            selfHosted: {
                name: '自建代理',
                buildUrl: (proxyBase, targetUrl) => {
                    let base = proxyBase.trim();
                    // 智能重定向: 如果代理指向 localhost，说明用户希望使用服务器自身的代理功能
                    if (base.includes('localhost') || base.includes('127.0.0.1')) {
                        // 使用 window.location 构造当前服务器的真实基础路径
                        const path = window.location.pathname.replace(/\/[^\/]*$/, '');
                        base = window.location.origin + (path === '/' ? '' : path);
                    }
                    // 彻底清洗 base URL
                    base = base.replace(/\/(\?url=)?$/, '').replace(/\?url=$/, '');
                    const finalUrl = `${base}/?url=${encodeURIComponent(targetUrl)}`;
                    console.log(`[Proxy] 构造请求: ${finalUrl}`); // 调试日志
                    return finalUrl;
                },
                parseResponse: (responseText) => Promise.resolve(responseText) 
            },
            allOriginsGet: {
                name: 'AllOrigins /get',
                buildUrl: (proxyBase, targetUrl) => {
                    let base = proxyBase.trim().replace(/\/$/, '').replace(/\/get(\?url=)?$/, '').replace(/\?get=$/, '');
                    return `${base}/get?url=${encodeURIComponent(targetUrl)}`;
                },
                parseResponse: (responseText) => {
                    try {
                        const data = JSON.parse(responseText);
                        if (data && data.contents) return Promise.resolve(data.contents);
                        return Promise.reject('Invalid JSON format: missing "contents"');
                    } catch (e) { return Promise.reject('Failed to parse JSON'); }
                }
            },
            allOriginsRaw: {
                name: 'AllOrigins /raw',
                buildUrl: (proxyBase, targetUrl) => {
                    let base = proxyBase.trim().replace(/\/$/, '').replace(/\/raw(\?url=)?$/, '').replace(/\?raw=$/, '');
                    return `${base}/raw?url=${encodeURIComponent(targetUrl)}`;
                },
                parseResponse: (responseText) => Promise.resolve(responseText) 
            }
        };

        let clientFetchedResults = [];
        let editProxyModalInstance = null;

        function logToClient(message) {
            const logDiv = document.getElementById('client-log');
            if (logDiv) { logDiv.innerHTML += `<div>${message}</div>`; logDiv.scrollTop = logDiv.scrollHeight; }
        }
        function copyToClipboard(text) { navigator.clipboard.writeText(text).then(() => alert('已复制到剪贴板')).catch(err => console.error('复制失败: ', err)); }
        function copyJsonUrl() { copyToClipboard(`${fullDomain}json/<?= uid() ?>.json`); }
        function copyVideoJsonUrl() { copyToClipboard(`${fullDomain}json/<?= uid() ?>_videos.json`); }
        function htmlspecialchars(str) { return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }

        function renderClientResults(results) {
            if (results.length === 0 && clientFetchedResults.length === 0) {
                 const container = document.getElementById('client-results');
                 if(container) container.innerHTML = '';
                 const actionsDiv = document.getElementById('client-actions');
                 if (actionsDiv) actionsDiv.style.display = 'none';
                 return;
            }
            const startIndex = clientFetchedResults.length;
            clientFetchedResults = clientFetchedResults.concat(results); 

            // 按类别分组
            const groupedResults = {};
            clientFetchedResults.forEach((api, globalIndex) => {
                if (!groupedResults[api.category]) groupedResults[api.category] = [];
                groupedResults[api.category].push({ ...api, globalIndex });
            });

            const container = document.getElementById('client-results');
            if (!container) return;
            container.innerHTML = '';

            // 为每个类别渲染一个部分
            for (const [category, apis] of Object.entries(groupedResults)) {
                let tbodyHtml = '';
                apis.forEach(api => {
                    tbodyHtml += `<tr><td><input type="checkbox" class="form-check-input" name="selected-api" value="${api.globalIndex}" checked></td><td class="api-name">${htmlspecialchars(api.name || '未知')}</td><td class="api-url"><a href="${htmlspecialchars(api.url)}" target="_blank">${htmlspecialchars(api.url)}</a></td></tr>`;
                });

                const categoryBadge = getCategoryBadge(category);
                const sectionHtml = `
                    <div class="mb-4 client-category-section" data-category="${category}">
                        <h6 class="section-header"><i class="bi bi-folder me-1"></i>${category} <span class="badge ${categoryBadge}">${apis.length} 个</span></h6>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-sm">
                                <thead><tr><th><input type="checkbox" class="form-check-input" onclick="toggleSelectAll(this, '${category}')" checked></th><th>名称</th><th>URL</th></tr></thead>
                                <tbody>${tbodyHtml}</tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <button onclick="addClientFetchedByCategory('${category}')" class="btn btn-success btn-sm"><i class="bi bi-plus-circle me-1"></i>添加此分类选中项</button>
                        </div>
                    </div>
                `;
                container.innerHTML += sectionHtml;
            }

            const actionsDiv = document.getElementById('client-actions');
            if (actionsDiv) actionsDiv.style.display = 'block';
        }

        function getCategoryBadge(category) {
            const badges = {
                '影视仓': 'bg-info',
                '音乐': 'bg-primary',
                '新闻': 'bg-warning',
                '数据API': 'bg-success',
                '其他': 'bg-secondary'
            };
            return badges[category] || 'bg-secondary';
        }

        function parseApiFromContent(content, source) {
            const results = new Map();
            function addResult(name, url) {
                if (url && typeof url === 'string' && url.startsWith('http')) {
                    const trimmedUrl = url.trim();
                    if (!results.has(trimmedUrl)) {
                         results.set(trimmedUrl, { name: (name || `从 ${source} 提取`).trim(), url: trimmedUrl });
                    }
                }
            }
            try {
                const json = JSON.parse(content);
                if (json.urls && Array.isArray(json.urls)) json.urls.forEach(item => addResult(item.name, item.url));
                else if (Array.isArray(json)) json.forEach(item => addResult(item.name, item.url));
                else if (json.sites && Array.isArray(json.sites)) json.sites.forEach(item => addResult(item.name, item.api));
            } catch (e) {}

            content.split(/[\r\n]+/).forEach(line => {
                const parts = line.trim().split(/[ ,，]+/).map(p => p.trim());
                if (parts.length >= 2 && parts[1].startsWith('http')) addResult(parts[0], parts[1]);
                else if (parts.length === 1 && parts[0].startsWith('http')) addResult(new URL(parts[0]).hostname, parts[0]);
            });
            if (results.size === 0) {
                 const urls = content.match(/https?:\/\/[^\s"'<>]+/g) || [];
                 urls.forEach(url => addResult(new URL(url).hostname, url));
            }
            return Array.from(results.values()).map(api => {
                const lowerName = api.name.toLowerCase();
                const lowerUrl = api.url.toLowerCase();

                // 扩展分类逻辑，根据功能内容分类
                if (matchKeywords(lowerName, lowerUrl, ["影视", "仓", "video", "movie", "tv", "drama", "film", "repository", "warehouse"]) ||
                    matchPatterns(api.url, [/\/api\//i, /\/v\d+\//i, /\/tv\//i, /\/video\//i, /\/film\//i, /\/drama\//i, /\/warehouse\//i, /\/repo\//i, /\.json$/i, /\/config\//i])) {
                    api.category = '影视仓';
                } else if (matchKeywords(lowerName, lowerUrl, ["音乐", "music", "audio", "song", "playlist", "album"])) {
                    api.category = '音乐';
                } else if (matchKeywords(lowerName, lowerUrl, ["新闻", "news", "article", "blog", "feed", "rss"])) {
                    api.category = '新闻';
                } else if (matchKeywords(lowerName, lowerUrl, ["数据", "data", "api", "json", "stats", "query"])) {
                    api.category = '数据API';
                } else {
                    api.category = '其他';
                }
                return api;
            });
        }

        function matchKeywords(name, url, keywords) {
            return keywords.some(kw => name.includes(kw.toLowerCase()) || url.includes(kw.toLowerCase()));
        }

        function matchPatterns(url, patterns) {
            return patterns.some(pattern => pattern.test(url));
        }

        async function fetchClientApis() {
            const sourceUrls = document.getElementById('sourceUrls').value.trim().split('\n').map(url => url.trim()).filter(Boolean);
            if (sourceUrls.length === 0) { logToClient('请输入采集源 URL'); return; }

            const proxySelect = document.getElementById('corsProxy');
            const selectedOption = proxySelect.options[proxySelect.selectedIndex];
            const proxyType = selectedOption.value;
            const proxyBaseUrl = selectedOption.dataset.url;
            const proxyConfig = PROXY_CONFIG[proxyType];
            if (!proxyConfig) { logToClient(`错误: 未找到 '${proxyType}' 对应的代理配置`); return; }

            const selectedType = document.getElementById('apiType').value;

            document.getElementById('fetch-btn').disabled = true;
            document.getElementById('client-loading').style.display = 'block';
            logToClient(`使用代理: ${selectedOption.text}`);
            logToClient(`采集类型: ${selectedType === 'all' ? '所有' : selectedType}`);

            for (const [i, source] of sourceUrls.entries()) {
                logToClient(`[${i + 1}/${sourceUrls.length}] 开始采集: ${source}`);
                try {
                    const fullUrl = proxyConfig.buildUrl(proxyBaseUrl, source);
                    const response = await fetch(fullUrl, { signal: AbortSignal.timeout(20000) }); // 20s timeout
                    if (response.status === 204) throw new Error(`HTTP 204: 代理返回空内容 (可能速率限制)`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    
                    const responseText = await response.text();
                    const content = await proxyConfig.parseResponse(responseText);
                    let parsedResults = parseApiFromContent(content, source);
                    
                    if (selectedType !== 'all') {
                        parsedResults = parsedResults.filter(api => api.category === selectedType);
                    }

                    if (parsedResults.length > 0) {
                        logToClient(`> 成功采集 ${parsedResults.length} 个接口`);
                        renderClientResults(parsedResults);
                    } else {
                        logToClient(`> 未采集到有效接口`);
                    }
                } catch (err) {
                    logToClient(`> 采集失败: ${err.message}`);
                }
            }
            document.getElementById('fetch-btn').disabled = false;
            document.getElementById('client-loading').style.display = 'none';
            logToClient('采集完成');
        }

        function addClientFetched() {
            const checkboxes = document.querySelectorAll('#client-results input[name="selected-api"]:checked');
            if (checkboxes.length === 0) { logToClient('请至少选择一个接口'); return; }
            const selected = Array.from(checkboxes).map(cb => clientFetchedResults[parseInt(cb.value)]).filter(Boolean);

            logToClient(`正在添加 ${selected.length} 个接口...`);
            fetch('?a=add_client_fetched', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `selected=${encodeURIComponent(JSON.stringify(selected))}`
            })
            .then(response => response.json())
            .then(data => {
                logToClient(data.message);
                if (data.apisHtml) {
                    sections['apis'] = data.apisHtml;
                    loadSection('apis');
                    document.getElementById('sidebar').querySelector('a[onclick*="apis"]').click();
                    alert('添加成功！已自动切换到“接口聚合”页面查看。');
                }
            })
            .catch(err => logToClient('添加失败: ' + err.message));
        }

        function addClientFetchedByCategory(category) {
            const section = document.querySelector(`.client-category-section[data-category="${category}"]`);
            if (!section) return;
            const checkboxes = section.querySelectorAll(`input[name="selected-api"]:checked`);
            const selected = Array.from(checkboxes)
                .map(cb => clientFetchedResults[parseInt(cb.value)])
                .filter(api => api);

            if (selected.length === 0) { logToClient(`分类 ${category} 中无选中接口`); return; }

            logToClient(`正在添加分类 ${category} 中的 ${selected.length} 个接口...`);
            fetch('?a=add_client_fetched', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `selected=${encodeURIComponent(JSON.stringify(selected))}`
            })
            .then(response => response.json())
            .then(data => {
                logToClient(data.message);
                if (data.apisHtml) {
                    sections['apis'] = data.apisHtml;
                    loadSection('apis');
                    document.getElementById('sidebar').querySelector('a[onclick*="apis"]').click();
                    alert('添加成功！已自动切换到“接口聚合”页面查看。');
                }
            })
            .catch(err => logToClient('添加失败: ' + err.message));
        }

        function toggleSelectAll(checkbox, category = null) {
            const table = checkbox.closest('table');
            if (!table) return;
            const name = category ? 'selected-api' : 'selected_ids[]';
            table.querySelectorAll(`tbody input[name="${name}"]`).forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        function checkProxies() {
            const statusDiv = document.getElementById('proxy-status');
            statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div> 正在检查...';
            fetch('?a=check_proxies').then(res => res.json()).then(data => {
                let html = '<div class="list-group">';
                data.forEach(s => { html += `<div class="list-group-item d-flex justify-content-between align-items-center list-group-item-${s.status === '有效' ? 'success' : 'danger'}"><strong>${htmlspecialchars(s.name)}</strong>: ${s.status} <span class="badge bg-secondary">${s.code}</span></div>`; });
                statusDiv.innerHTML = html + '</div>';
            });
        }
        function prepareEditProxy(index, name, url, type, enabled) {
            if (!editProxyModalInstance) {
                editProxyModalInstance = new bootstrap.Modal(document.getElementById('editProxyModal'));
            }
            document.getElementById('edit_proxy_index').value = index;
            document.getElementById('edit_proxy_name').value = name;
            document.getElementById('edit_proxy_url').value = url;
            document.getElementById('edit_proxy_type').value = type;
            document.getElementById('edit_proxy_enabled').checked = enabled;
            editProxyModalInstance.show();
        }
        
        // --- 新增：接口上下移 JS 函数 ---
        function moveApi(id, direction) {
            fetch(`?a=move_${direction}&id=${id}`)
                .then(response => response.text())
                .then(() => {
                    // 刷新页面以更新列表顺序
                    location.reload();
                })
                .catch(err => {
                    console.error('移动失败:', err);
                    alert('移动失败，请重试');
                });
        }
        
        // --- 新增：影视源上下移 JS 函数 ---
        function moveVideoSource(id, direction) {
            fetch(`?a=move_video_source_${direction}&id=${id}`)
                .then(response => response.text())
                .then(() => {
                    // 刷新页面以更新列表顺序
                    location.reload();
                })
                .catch(err => {
                    console.error('移动失败:', err);
                    alert('移动失败，请重试');
                });
        }
        
        const sections = {
            'apis': `<?= getApisHtml(isLogin() ? uid() : 0) ?>`,
            'video_sources': `<?= getVideoSourcesHtml(isLogin() ? uid() : 0) ?>`,
            'client': `<?php ob_start(); ?>
                <div class="card"><div class="card-body">
                    <h6 class="section-header"><i class="bi bi-search me-1"></i>采集源</h6>
                    <div class="mb-3"><textarea class="form-control" id="sourceUrls" rows="3" placeholder="输入采集源URL，每行一个">https://raw.githubusercontent.com/gaotianliuyun/gao/master/0821.json</textarea></div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                           <label class="form-label">选择代理</label>
                            <select class="form-select" id="corsProxy">
                                <?php foreach (getProxies() as $proxy): if ($proxy['enabled']): ?>
                                <option value="<?= htmlspecialchars($proxy['type']) ?>" data-url="<?= htmlspecialchars($proxy['url']) ?>">
                                    <?= htmlspecialchars($proxy['name']) ?>
                                </option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">选择接口类型</label>
                            <select class="form-select" id="apiType">
                                <option value="all">所有</option>
                                <option value="影视仓">影视仓</option>
                                <option value="音乐">音乐</option>
                                <option value="新闻">新闻</option>
                                <option value="数据API">数据API</option>
                                <option value="其他">其他</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button onclick="fetchClientApis()" id="fetch-btn" class="btn btn-primary w-100"><i class="bi bi-cloud-download me-1"></i>开始采集</button>
                        </div>
                    </div>
                    <div id="client-loading" class="text-center mb-3" style="display: none;"><div class="spinner-border text-primary"></div><p class="mt-2">正在采集...</p></div>
                    <div id="client-log" class="mb-3 p-2 bg-light border rounded" style="max-height: 150px; overflow-y: auto; font-size: 0.9em; font-family: monospace;"></div>
                    <div id="client-results" class="table-responsive"></div>
                    <div class="mt-3" id="client-actions" style="display: none;"><div class="btn-group">
                        <button onclick="addClientFetched()" class="btn btn-success btn-sm"><i class="bi bi-plus-circle me-1"></i>添加所有选中项</button>
                    </div></div>
                </div></div>
            <?php echo ob_get_clean(); ?>`,
            'proxies': `<?php ob_start(); ?>
                <div class="card"><div class="card-body">
                    <div class="mb-3"><button onclick="checkProxies()" class="btn btn-primary btn-sm"><i class="bi bi-patch-check me-1"></i>检查代理可用性</button></div>
                    <div id="proxy-status" class="mb-3"></div>
                    <div class="table-responsive">
                        <table class="table table-striped">
                        <thead><tr><th>名称</th><th>URL</th><th>类型</th><th>状态</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach (getProxies() as $index => $proxy): ?>
                        <tr>
                            <td><?= htmlspecialchars($proxy['name']) ?></td><td><?= htmlspecialchars($proxy['url']) ?></td>
                            <td><?= htmlspecialchars(getProxyTypes()[$proxy['type']] ?? '未知') ?></td>
                            <td>
                                <form method="post" action="?a=update_proxy" class="d-inline">
                                    <input type="hidden" name="index" value="<?= $index ?>"><input type="hidden" name="proxy_name" value="<?= htmlspecialchars($proxy['name']) ?>"><input type="hidden" name="proxy_url" value="<?= htmlspecialchars($proxy['url']) ?>"><input type="hidden" name="proxy_type" value="<?= htmlspecialchars($proxy['type']) ?>">
                                    <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" name="enabled" <?= $proxy['enabled'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                    </div>
                                </form>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="prepareEditProxy(<?= $index ?>, '<?= addslashes(htmlspecialchars($proxy['name'])) ?>', '<?= addslashes(htmlspecialchars($proxy['url'])) ?>', '<?= $proxy['type'] ?>', <?= $proxy['enabled'] ? 'true' : 'false' ?>)"><i class="bi bi-pencil"></i></button>
                                <?php if ($index > 0): ?><a href="?a=delete_proxy&index=<?= $index ?>" class="btn btn-outline-danger" onclick="return confirm('确定删除吗？')"><i class="bi bi-trash"></i></a><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <div class="section-header"><h6><i class="bi bi-plus-circle me-1"></i>添加新代理</h6></div>
                    <form method="post" action="?a=add_proxy" class="row g-3 align-items-end">
                        <div class="col-md-4"><label class="form-label">名称</label><input type="text" class="form-control" name="proxy_name" placeholder="代理名称" required></div>
                        <div class="col-md-4"><label class="form-label">URL</label><input type="url" class="form-control" name="proxy_url" placeholder="代理URL" required></div>
                        <div class="col-md-3"><label class="form-label">类型</label><select class="form-select" name="proxy_type"><?php foreach (getProxyTypes() as $key => $name): ?><option value="<?= $key ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-1"><button type="submit" class="btn btn-success w-100"><i class="bi bi-plus"></i></button></div>
                    </form>
                </div></div>
            <?php echo ob_get_clean(); ?>`,
            'change_password': `<?= addslashes($changePasswordHtml) ?>`
        };

        // Fallback 修改密码 HTML（如果 sections 未预生成）
        const changePasswordStaticHtml = `
<div class="card"><div class="card-body">
    <h5 class="card-title">修改密码</h5>
    <form method="post" action="?a=change_password&p=change_password">
        <div class="mb-3"><label class="form-label">旧密码</label><input type="password" class="form-control" name="old_password" required></div>
        <div class="mb-3"><label class="form-label">新密码</label><input type="password" class="form-control" name="new_password" required></div>
        <div class="mb-3"><label class="form-label">确认新密码</label><input type="password" class="form-control" name="confirm_password" required></div>
        <button type="submit" class="btn btn-primary">修改</button>
    </form>
</div></div>
`;

        function loadSection(section, event = null) {
            if(event) event.preventDefault();
            let content = sections[section];
            if (section === 'change_password' && !content) {
                content = changePasswordStaticHtml;  // 使用静态 fallback
            }
            document.getElementById('main-content-area').innerHTML = content || '<p>加载中...</p>';
            
            // 更新 active 链接
            document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active'));
            const activeLink = document.querySelector(`.sidebar .nav-link[onclick*="loadSection('${section}')"]`);
            if(activeLink) activeLink.classList.add('active');

            // 更新 URL
            const url = new URL(window.location);
            url.searchParams.set('p', section);
            if (section !== 'change_password') url.searchParams.delete('a');  // 清理 a 参数，除非是 change_password
            window.history.pushState({}, '', url);

            // 手机端关闭 offcanvas
            if (window.matchMedia("(max-width: 768px)").matches) {
                const offcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('sidebar'));
                if (offcanvas) offcanvas.hide();
            }

            if (section === 'apis') {
                document.querySelectorAll('.check-single').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const id = this.dataset.id;
                        const badge = this.closest('tr').querySelector('.status-badge');
                        badge.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                        badge.classList.remove('bg-success', 'bg-danger');
                        fetch(`?a=check_single&id=${id}`)
                            .then(res => res.json())
                            .then(data => {
                                if (data.status) {
                                    badge.classList.add(data.status === 'valid' ? 'bg-success' : 'bg-danger');
                                    badge.textContent = statusToChinese(data.status);
                                } else {
                                    badge.textContent = '错误';
                                }
                            })
                            .catch(err => {
                                badge.textContent = '错误';
                            });
                    });
                });
            }

            if (section === 'video_sources') {
                document.querySelectorAll('.check-single').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const id = this.dataset.id;
                        const badge = this.closest('tr').querySelector('.status-badge');
                        badge.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                        badge.classList.remove('bg-success', 'bg-danger');
                        fetch(`?a=check_video_source_single&id=${id}`)
                            .then(res => res.json())
                            .then(data => {
                                if (data.status) {
                                    badge.classList.add(data.status === 'valid' ? 'bg-success' : 'bg-danger');
                                    badge.textContent = statusToChinese(data.status);
                                } else {
                                    badge.textContent = '错误';
                                }
                            })
                            .catch(err => {
                                badge.textContent = '错误';
                            });
                    });
                });
            }
        }        

        function prepareEditApi(id, name, url) {
            document.getElementById('edit_api_id').value = id;
            document.getElementById('edit_api_name').value = name;
            document.getElementById('edit_api_url').value = url;
            new bootstrap.Modal(document.getElementById('editApiModal')).show();
        }

        function prepareEditVideoSource(id, name, api_url, detail_url, is_adult) {
            document.getElementById('edit_video_source_id').value = id;
            document.getElementById('edit_video_source_name').value = name;
            document.getElementById('edit_video_source_api_url').value = api_url;
            document.getElementById('edit_video_source_detail_url').value = detail_url;
            document.getElementById('edit_video_source_is_adult').checked = !!is_adult;
            new bootstrap.Modal(document.getElementById('editVideoSourceModal')).show();
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (!isLogin()) return;
            const urlParams = new URLSearchParams(window.location.search);
            let page = urlParams.get('p') || 'apis';
            if (urlParams.get('a') === 'change_password') {
                page = 'change_password';  // 强制加载 change_password
            }
            loadSection(page);
        });

        function isLogin() {
            return <?= isLogin() ? 'true' : 'false' ?>;
        }

        function statusToChinese(status) {
            const map = { 'valid': '有效', 'invalid': '无效', 'unknown': '未知' };
            return map[status] || status;
        }

        function uid() {
            return <?= uid() ?>;
        }
    </script>
</body>
</html>