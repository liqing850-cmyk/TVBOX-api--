# API Manager (Cloudflare Worker 版)

一个基于 Cloudflare Worker 的 API 接口管理与聚合工具。支持双聚合链接（成人/非成人）、密码保护、批量接口自检、自动采集、跨域代理以及多格式 JSON 导入。

## 核心特性

- **Cloudflare 部署**：利用 Cloudflare Worker + KV 存储，实现 0 成本、高可用、免服务器部署。
- **双订阅模式**：
  - **标准订阅**：过滤成人内容，适合常规环境。
  - **全量订阅**：包含成人内容（通过 `?adult=1` 参数）。
- **密码保护**：管理后台支持 HTTP Basic Auth 认证，确保数据安全。
- **批量管理工具**：
  - **一键自检**：并发检查所有接口可用性。
  - **智能清理**：一键删除所有无效/超时的接口。
  - **成人内容控制**：一键开启或禁用所有 18+ 接口。
- **多格式导入**：支持标准 JSON 数组、嵌套对象、以及 ConfigPlus (95+ 源) 格式。
- **跨域代理**：内置 CORS 代理功能，解决前端调用接口时的跨域限制。

## 部署步骤 (Cloudflare Worker)

### 1. 创建 KV 命名空间
1. 登录 Cloudflare 控制台。
2. 进入 **Workers & Pages -> KV**。
3. 点击 **Create a namespace**，名称建议设为 `API_MANAGER_KV`。

### 2. 创建并配置 Worker
1. 进入 **Workers & Pages -> Create application**，创建一个新的 Worker。
2. 将项目中的 `worker.js` 代码粘贴到 Worker 编辑器中。
3. **绑定 KV**：
   - 进入 Worker 的 **Settings -> Variables**。
   - 在 **KV Namespace Bindings** 下点击 **Add binding**。
   - **Variable name** 必须填 `DB`。
   - **KV namespace** 选择你刚才创建的 `API_MANAGER_KV`。
4. **设置密码 (可选)**：
   - 在同一页面的 **Environment Variables** 下点击 **Add variable**。
   - `ADMIN_USER`: 管理后台用户名（默认 `admin`）。
   - `ADMIN_PASS`: 管理后台密码（默认 `admin`）。
5. 点击 **Save and deploy**。

## 使用指南

- **管理后台**：直接访问你的 Worker 域名（如 `https://your-worker.workers.dev/`），首次进入需输入设置的用户名和密码。
- **添加接口**：支持手动添加或通过 JSON 文件批量导入。
- **获取订阅链接**：在管理后台顶部可直接复制标准订阅或全量订阅链接。

---

## 传统部署 (PHP/Docker - 旧版)

## 项目概述

### 背景与目标
API Manager旨在简化API接口的收集、管理和验证过程，特别是针对影视源、代理服务等场景。开发者可以轻松添加、检查和导出有效接口，避免手动维护。核心理念：**简单、安全、高效**。
- **单文件架构**：所有PHP逻辑、HTML、CSS、JS集成在`index.php`，体积小（<50KB），易于上传。
- **数据持久化**：SQLite数据库 + 文件JSON导出，支持多用户隔离。
- **跨平台**：Docker一键部署，支持本地/云服务器。

### 适用场景
- 个人开发者：管理影视API源，快速采集/验证。
- 小团队：共享接口列表，批量检查可用性。
- 生产环境：集成到CMS或App后端，作为接口代理池。

## 特性详解

### 1. 用户管理系统
- **注册/登录**：用户名唯一，密码哈希存储（PASSWORD_DEFAULT）。最小密码长度6位，支持确认密码验证。
- **会话管理**：自定义session路径（`sessions/`），支持多用户并发。
- **密码修改**：需输入旧密码验证，防止未授权变更。
- **安全措施**：XSS防护（`htmlspecialchars`）、输入trim、PDO预处理防SQL注入。

### 2. 接口聚合管理（API管理）
- **CRUD操作**：添加/编辑/删除，支持URL验证（scheme: http/https，IDNA中文域名）。
- **批量工具**：
  - **一键检查**：cURL多线程（批次10个），检查HTTP 200-399为有效。优化：范围请求（`0-0`字节）、短超时（10s连接+5s整体）。
  - **删除无效/重复**：自动移除status='invalid'或重复URL（保留最新ID）。
- **排序与导出**：sort_order字段，支持上下移（顶部/底部）。导出有效接口为JSON（`json/{uid}.json`），格式：`{"urls": [{"name":"xxx","url":"https://..."}]}`。
- **UI**：Bootstrap表格，状态徽章（绿色有效/红色无效），模态编辑。

### 3. 影视源配置（Video Sources）
- **CRUD操作**：添加/编辑/删除源，支持`api_url`（必填）、`detail_url`（可选）、`is_adult`（0/1标记成人）。
- **批量工具**：类似API，一键检查/删除无效/重复。
- **导入/导出**：
  - **导入**：上传JSON，支持两种格式：
    - 新：`{"sites": [{"name":"源1","api_url":"https://...","detail_url":"https://...","is_adult":false}]}`
    - 旧：`{"api_site": {"key1": {"name":"源1","api":"https://...","detail":"https://...","is_adult":true}}}`
    - 自动跳过重复（基于api_url），设置sort_order。
  - **导出**：`json/{uid}_videos.json`，格式：`{"sites": [{"name":"xxx","api":"https://...","detail_url":"https://...","is_adult":true}]}`（bool类型，可选字段移除空值）。
- **UI**：额外列显示详情URL和类型徽章（黄色成人/灰色常规）。

### 4. 代理管理（Proxies）
- **类型支持**：
  - `selfHosted`：自建（`/?url=...`），默认本地`http://127.0.0.1:8080/`。
  - `allOriginsGet`：AllOrigins JSON模式（`/get?url=...`），解析`contents`字段。
  - `allOriginsRaw`：AllOrigins原始内容（`/raw?url=...`）。
- **操作**：添加/编辑/删除（保护默认第一个），开关启用。
- **智能检查**：针对类型优化：
  - 自建：国内目标（baidu.com/robots.txt），无UA头。
  - 公共：全球目标（github.com/robots.txt），加UA，HEAD请求（NOBODY）。
  - 结果：列表显示名称/状态/HTTP码。
- **集成**：采集工具自动使用启用代理。

### 5. 影视采集工具（Client Fetch）
- **输入**：多行URL（e.g., GitHub raw JSON），默认示例`https://raw.githubusercontent.com/gaotianliuyun/gao/master/0821.json`。
- **代理/过滤**：下拉选代理和类型（所有/影视仓/音乐/新闻/数据API/其他）。
- **解析逻辑**：
  - JSON：提取`urls[]`、`[]`数组、`sites[].api`。
  - 文本：行解析（名称 URL）、纯URL（主机名作为名称）。
  - 分类：关键词（"影视"/"music"）+ URL模式（`/api/`、`.json`）。
- **输出**：分类表格（选中复选框），日志区（实时采集进度）。
- **添加**：批量提交选中/分类到API列表，自动重定向查看。

### 6. 前端与响应式
- **框架**：Bootstrap 5.3（CDN），Icons 1.10。
- **布局**：
  - 未登录：居中登录卡片，渐变背景，动画淡入。
  - 已登录：固定侧边栏（桌面240px，移动Offcanvas），主内容滚动。
- **交互**：JS动态加载section（无刷新），模态编辑，复制JSON链接（clipboard API）。
- **兼容**：PHP ob_start缓冲HTML，JS fallback（如密码修改静态HTML）。

## 系统要求与依赖

- **PHP**：8.2+（测试8.2-8.3）。
  - 扩展：PDO_SQLite（数据库）、cURL（检查/采集）、Intl（IDNA中文URL）。
- **服务器**：Apache/Nginx，mod_rewrite可选（未用）。
- **权限**：`data/`、`json/`、`sessions/` 可写（755/777测试）。
- **浏览器**：现代（Chrome/FF/Safari），支持ES6+（async/await）。
- **无外部服务**：纯静态CDN（Bootstrap），SQLite本地。

## 快速启动（Docker部署）

Docker是推荐方式，一键构建+运行，支持持久化。默认端口已设为8088（可自定义）。

### 步骤详解
1. **准备环境**：
   - 安装Docker 20+ 和 Docker Compose 2+。
   - 克隆仓库：
     ```
     git clone https://github.com/yourusername/api-manager.git
     cd api-manager
     ```
   - 创建空目录（如果缺失）：`mkdir -p data json sessions`。

2. **构建与启动**（使用docker-compose）：
   ```
   docker-compose up --build -d
   ```
   - `--build`：首次构建镜像（安装PHP扩展）。
   - `-d`：后台运行。
   - 构建过程：基于`php:8.2-apache`，安装libsqlite3/intl/curl，启用扩展，复制文件，设置权限（www-data）。

3. **访问与验证**：
   - 浏览器打开：http://localhost:8088
   - 注册用户（e.g., admin/123456），登录。
   - 测试功能：添加代理 → 采集源 → 添加接口 → 一键检查 → 导出JSON。
   - 检查持久化：停止容器后，重启数据保留（`data/db.sqlite3`存在）。

4. **管理命令**：
   - 查看日志：`docker-compose logs -f app`
   - 停止：`docker-compose down`
   - 重启：`docker-compose restart`
   - 清理卷：`docker-compose down -v`（删除持久化数据，慎用）。
   - 进入容器：`docker-compose exec app bash`（调试PHP：`php -m`）。

### Docker Compose 配置详解（docker-compose.yml）
项目根目录已包含以下文件，直接复制使用。端口默认8088（本地→容器80），支持自定义。

```yaml
version: '3.8'

services:
  app:
    build: .  # 使用根目录Dockerfile构建自定义镜像
    ports:
      - "8088:80"  # 本地8088端口映射到容器Apache 80端口（可改成"80:80"需root）
    volumes:
      - ./data:/var/www/html/data  # 持久化SQLite DB、proxies.json、error_log.txt
      - ./json:/var/www/html/json  # 持久化用户JSON导出文件
      - ./sessions:/var/www/html/sessions  # 持久化PHP Session（生产可选Redis）
    environment:
      - APACHE_RUN_USER=www-data  # Apache运行用户，确保权限
      - APACHE_RUN_GROUP=www-data  # Apache运行组
      - TZ=Asia/Shanghai  # 时区（可选，匹配服务器）
    restart: unless-stopped  # Docker自动重启，除非手动停止
    # 健康检查（可选，监控可用性）
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost || exit 1"]  # 检查首页响应
      interval: 30s  # 每30s检查
      timeout: 10s   # 超时10s
      retries: 3     # 失败3次重启
      start_period: 40s  # 启动后40s开始检查
```

- **自定义扩展**：
  - **端口**：改`ports`为`"你的端口:80"`。
  - **卷**：添加`./logs:/var/log/apache2`捕获Apache日志。
  - **环境**：添加`PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d`自定义php.ini。
  - **网络**：添加`networks: default`隔离。
- **生产优化**：用Traefik/Nginx反代HTTPS，secrets管理敏感配置。

### 手动Docker构建（无Compose）
如果不使用Compose：
```
# 构建镜像
docker build -t api-manager .

# 运行容器
docker run -d \
  --name api-manager \
  -p 8088:80 \
  -v $(pwd)/data:/var/www/html/data \
  -v $(pwd)/json:/var/www/html/json \
  -v $(pwd)/sessions:/var/www/html/sessions \
  -e TZ=Asia/Shanghai \
  --restart unless-stopped \
  api-manager
```

## 无Docker手动部署

### 服务器准备
1. **上传文件**：
   - 将`index.php`、`Dockerfile`、`docker-compose.yml`、`README.md`等上传到Web根目录（e.g., `/var/www/html/`）。
   - 创建目录：`mkdir data json sessions`。
   - 设置权限：`chown -R www-data:www-data . && chmod -R 755 data json sessions`（Apache用户）。

2. **PHP配置**：
   - 启用扩展：编辑`php.ini`或用`apt install php8.2-sqlite3 php8.2-curl php8.2-intl`。
   - 测试：`php -m | grep -E 'pdo_sqlite|curl|intl'`（全输出）。

3. **Web服务器**：
   - **Apache**：默认DocumentRoot `/var/www/html`，重启`systemctl restart apache2`。
   - **Nginx**：配置`location / { try_files $uri =404; }`，重载`nginx -s reload`。
   - 添加.htaccess（可选）：
     ```
     <Directory "/var/www/html/data">
         Order Deny,Allow
         Deny from all
     </Directory>
     ```

4. **启动验证**：
   - 访问http://yourserver:8088/index.php（端口依服务器）。
   - 首次运行：自动创建`data/db.sqlite3`，测试注册/登录。

### 常见自定义
- **默认代理**：编辑`initProxies()`添加自定义URL。
- **日志**：错误写入`data/error_log.txt`，监控导入/检查失败。
- **扩展**：添加Redis Session（改`session_save_path`）或MySQL（换PDO驱动）。

## 使用指南与示例

### 示例1：快速添加并检查API
1. 登录 → “接口聚合”。
2. 输入：名称“测试API”，URL“https://api.github.com”。
3. 点击“添加” → “一键检查”（绿色徽章表示有效）。
4. 导出：点击“查看JSON”，复制链接分享。

### 示例2：采集影视源
1. “影视采集” → 输入源URL（多行）。
2. 选“AllOrigins”代理 + “影视仓”类型 → “开始采集”。
3. 查看日志/结果表格 → 选中 → “添加所有选中项”。
4. 切换“接口配置”验证/导出。

### 示例3：导入JSON源
1. “接口配置” → “导入JSON文件” → 上传文件。
2. 成功消息：显示添加/跳过数。
3. “视频源JSON”下载验证。

### 示例4：代理优化
1. “代理管理” → 添加自建URL → 启用。
2. “检查代理可用性” → 结果列表（HTTP码）。
3. 用在新采集中绕CORS。

## 故障排除与调试

| 问题 | 可能原因 | 解决方案 |
|------|----------|----------|
| **目录不可写** | 权限不足 | `chmod 755 data/`；Docker: `docker-compose exec app chown -R www-data data` |
| **SQLite扩展缺失** | PHP未安装 | `apt install php8.2-sqlite3`；Docker已内置 |
| **cURL检查超时** | 网络/代理问题 | 缩短CURLOPT_TIMEOUT=5；测试`curl -I https://github.com` |
| **采集失败 (CORS)** | 代理禁用 | 启用AllOrigins；检查浏览器控制台 |
| **JSON导入无效** | 格式错 | 验证JSON（在线工具）；支持api_site/sites |
| **Session丢失** | 目录/路径错 | 检查`sessions/`权限；重启服务器 |
| **移动布局乱** | Bootstrap CDN阻 | 下载本地JS/CSS替换CDN |
| **中文URL无效** | Intl未启用 | `php -m | grep intl`；Docker已处理 |
| **Docker端口冲突** | 8088占用 | 改docker-compose ports: "8089:80" |
| **健康检查失败** | 容器未启动 | `docker-compose logs app`；检查卷权限 |

- **日志查看**：`data/error_log.txt`（导入/PDO错误）；浏览器F12（JS采集）。
- **调试模式**：临时加`error_reporting(E_ALL); ini_set('display_errors',1);`到index.php顶部。

## 贡献与开发

### 贡献流程
1. **Fork & Clone**：GitHub Fork → `git clone yourfork`。
2. **分支**：`git checkout -b feature/add-redis-session`。
3. **开发**：
   - 本地测试：`php -S localhost:8088`（需扩展）。
   - Docker测试：`docker-compose up`。
   - 验证：添加功能后，运行检查/导入。
4. **提交**：`git add . && git commit -m "Add Redis support" && git push`。
5. **PR**：描述变更、测试步骤、截图。

### 开发提示
- **代码结构**：PHP函数（getApisHtml等）+ JS sections对象动态加载。
- **测试用例**：
  - 单元：PHP单元测试PDO插入/ cURL状态。
  - 集成：Selenium模拟采集/添加。
- **潜在扩展**：
  - Redis缓存检查结果。
  - Webhook通知无效接口。
  - 多语言（i18n）。

### 已知限制
- 单文件：大型扩展需拆分。
- cURL无代理链：复杂网络需外部工具。
- 采集限20s超时：长任务分批。

## 许可与鸣谢

- **许可**：MIT License – 自由使用/修改/分发。保留版权：© 2025 YourName。
- **依赖**：
  - Bootstrap 5.3 / Icons 1.10 (MIT)。
  - PHP PDO/cURL/Intl (开源)。
- **灵感**：开源API仓库如gaotianliuyun/gao。

## 联系与更新

- **仓库**：(https://github.com/liqing850-cmyk/TVBOX-api--)
- **议题**：Bug报告/功能请求（标签：bug/enhancement）。
- **更新日志**：v1.0 – 初始发布；v1.1 – 添加导入兼容。
- **社区**：欢迎Star/Fork，Discord/Slack群（未来）。

感谢使用API Manager！如需支持，@issue我。🚀
