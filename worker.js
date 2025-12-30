/**
 * Cloudflare Worker: API Manager & Aggregator (密码保护安全版)
 */

export default {
    async fetch(request, env) {
      const url = new URL(request.url);
      const params = url.searchParams;
  
      // --- 1. 公开路径 (不需要密码) ---
      // 聚合导出链接必须公开，否则播放器无法访问
      if (url.pathname === '/export') {
        return handleExport(env, params.get('adult') === '1');
      }
      // CORS 代理链接
      if (params.get('url')) {
        return handleProxy(params.get('url'), request);
      }
  
      // --- 2. 身份验证 (Basic Auth) ---
      // 仅保护管理后台路由
      const authError = handleAuth(request, env);
      if (authError) return authError;
  
      // --- 3. 受保护的路由 ---
      switch (url.pathname) {
        case '/': return new Response(getUI(url.origin), { headers: { 'Content-Type': 'text/html;charset=UTF-8' } });
        case '/api/list': return handleList(env);
        case '/api/save-all': return handleSaveAll(request, env);
        case '/api/check-all': return handleCheckAll(env);
        default: return new Response('Not Found', { status: 404 });
      }
    }
  };
  
  // --- 身份验证逻辑 ---
  function handleAuth(request, env) {
    const authHeader = request.headers.get('Authorization');
    if (!authHeader) {
      return new Response('Unauthorized', {
        status: 401,
        headers: { 'WWW-Authenticate': 'Basic realm="Admin Access"' }
      });
    }
  
    // 从环境变量读取，若未设置则默认为 admin/admin
    const expectedUser = env.ADMIN_USER || 'admin';
    const expectedPass = env.ADMIN_PASS || 'admin';
  
    try {
      const authValue = authHeader.split(' ')[1];
      const [user, pass] = atob(authValue).split(':');
      if (user === expectedUser && pass === expectedPass) {
        return null; // 验证通过
      }
    } catch (e) {}
  
    return new Response('Invalid credentials', { status: 401 });
  }
  
  // --- 代理逻辑 ---
  async function handleProxy(targetUrl, request) {
    try {
      const response = await fetch(targetUrl, {
        headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' }
      });
      const headers = new Headers(response.headers);
      headers.set('Access-Control-Allow-Origin', '*');
      return new Response(response.body, { status: response.status, headers });
    } catch (e) { return new Response('Proxy Error', { status: 502 }); }
  }
  
  // --- 数据操作 ---
  async function handleList(env) {
    const data = await env.DB.get('api_data') || '[]';
    return new Response(data, { headers: { 'Content-Type': 'application/json' } });
  }
  
  async function handleSaveAll(request, env) {
    const newList = await request.json();
    await env.DB.put('api_data', JSON.stringify(newList));
    return new Response(JSON.stringify({ success: true }));
  }
  
  async function handleCheckAll(env) {
    let list = JSON.parse(await env.DB.get('api_data') || '[]');
    const updated = await Promise.all(list.map(async (item) => {
      if (!item.enabled) return item;
      try {
        const res = await fetch(item.url, { method: 'HEAD', timeout: 5000 });
        item.status = (res.status >= 200 && res.status < 400) ? '有效' : '无效';
        item.code = res.status;
      } catch { item.status = '超时'; item.code = 0; }
      return item;
    }));
    await env.DB.put('api_data', JSON.stringify(updated));
    return new Response(JSON.stringify({ success: true }));
  }
  
  async function handleExport(env, includeAdult) {
    const list = JSON.parse(await env.DB.get('api_data') || '[]');
    const filtered = list.filter(i => i.enabled && (includeAdult || !i.is_adult));
    return new Response(JSON.stringify({
      urls: filtered.map(i => ({ name: i.name, url: i.url })),
      count: filtered.length,
      updated: new Date().toLocaleString()
    }, null, 2), { 
      headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' } 
    });
  }
  
  // --- UI 模板 ---
  function getUI(origin) {
    return `
  <!DOCTYPE html>
  <html lang="zh-CN">
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>API 管理控制台</title>
      <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
      <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
      <style>
          .api-row { transition: all 0.2s; border-bottom: 1px solid #eee; }
          .disabled-api { opacity: 0.5; filter: grayscale(0.8); }
          .url-text { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; font-size: 0.8em; color: #666; }
          .badge-adult { background-color: #dc3545; font-size: 0.7em; }
          .copy-input { font-size: 0.85em; background-color: #f8f9fa !important; cursor: pointer; }
      </style>
  </head>
  <body class="bg-light">
      <div class="container py-4">
          <!-- 头部 -->
          <div class="d-flex justify-content-between align-items-center mb-3">
              <h4 class="mb-0 text-primary fw-bold"><i class="bi bi-shield-lock"></i> API 管理聚合</h4>
              <div class="btn-group shadow-sm">
                  <button onclick="checkAll()" class="btn btn-outline-primary btn-sm"><i class="bi bi-activity"></i> 全量自检</button>
                  <button onclick="document.getElementById('importFile').click()" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-arrow-up"></i> 导入</button>
                  <button onclick="showAddModal()" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> 手动添加</button>
                  <input type="file" id="importFile" accept=".json" style="display:none" onchange="handleImport(this)">
              </div>
          </div>
  
          <!-- 聚合链接 -->
          <div class="row g-3 mb-4">
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm">
                      <div class="card-body p-2">
                          <label class="small text-muted mb-1">标准订阅 (无 18+)</label>
                          <div class="input-group input-group-sm">
                              <input type="text" class="form-control copy-input" value="${origin}/export" readonly onclick="copyLink(this)">
                              <button class="btn btn-dark" onclick="copyLink(this.previousElementSibling)">复制</button>
                          </div>
                      </div>
                  </div>
              </div>
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm">
                      <div class="card-body p-2 text-danger">
                          <label class="small text-danger mb-1">全量订阅 (含 18+)</label>
                          <div class="input-group input-group-sm">
                              <input type="text" class="form-control copy-input border-danger text-danger" value="${origin}/export?adult=1" readonly onclick="copyLink(this)">
                              <button class="btn btn-danger" onclick="copyLink(this.previousElementSibling)">复制</button>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
  
          <!-- 批量操作 -->
          <div class="card mb-4 border-0 shadow-sm bg-white">
              <div class="card-body p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div class="input-group input-group-sm w-auto flex-grow-1" style="max-width:400px;">
                      <span class="input-group-text bg-white border-end-0"><i class="bi bi-cloud-download"></i></span>
                      <input type="text" id="fetchUrl" class="form-control border-start-0" placeholder="抓取 JSON 或网页源">
                      <button onclick="fetchRemote()" class="btn btn-secondary">抓取</button>
                  </div>
                  <div class="btn-group btn-group-sm">
                      <button onclick="batchAction('delete_invalid')" class="btn btn-outline-danger">删除无效</button>
                      <button onclick="batchAction('disable_adult')" class="btn btn-outline-dark">禁用成人</button>
                      <button onclick="batchAction('enable_adult')" class="btn btn-outline-warning text-dark">开启成人</button>
                      <button onclick="clearList()" class="btn btn-danger">清空列表</button>
                  </div>
              </div>
              <div id="fetchStatus" class="card-footer bg-white border-top-0 pt-0" style="display:none;">
                  <div class="d-flex justify-content-between align-items-center small mb-2 border-top pt-2">
                      <span>发现 <b id="fetchCount" class="text-primary">0</b> 个接口</span>
                      <button onclick="batchSave()" class="btn btn-success btn-sm py-0">确认保存</button>
                  </div>
                  <div id="fetchList" class="border rounded bg-light" style="max-height:150px; overflow-y:auto; font-size:0.85em;"></div>
              </div>
          </div>
  
          <!-- 列表 -->
          <div class="card border-0 shadow-sm">
              <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                      <thead class="table-light small">
                          <tr>
                              <th width="40">#</th>
                              <th>名称</th>
                              <th>URL</th>
                              <th width="80">状态</th>
                              <th width="60">启用</th>
                              <th width="150" class="text-center">操作</th>
                          </tr>
                      </thead>
                      <tbody id="apiTable" class="small"></tbody>
                  </table>
              </div>
          </div>
      </div>
  
      <!-- 模态框 -->
      <div class="modal fade" id="apiModal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content border-0 shadow">
                  <div class="modal-header py-2 border-bottom-0"><h6 class="modal-title" id="modalTitle">接口配置</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body pt-0">
                      <input type="hidden" id="editIdx">
                      <div class="mb-2"><label class="form-label small mb-1">名称</label><input type="text" id="editName" class="form-control form-control-sm"></div>
                      <div class="mb-2"><label class="form-label small mb-1">URL</label><textarea id="editUrl" class="form-control form-control-sm" rows="3"></textarea></div>
                      <div class="form-check"><input class="form-check-input" type="checkbox" id="editAdult"><label class="form-check-label small" for="editAdult">标记为成人内容 (18+)</label></div>
                  </div>
                  <div class="modal-footer border-0 pt-0"><button type="button" onclick="saveApi()" class="btn btn-primary btn-sm w-100">保存修改</button></div>
              </div>
          </div>
      </div>
  
      <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
      <script>
          let apiData = [];
          let fetchedTemp = [];
          const modal = new bootstrap.Modal(document.getElementById('apiModal'));
  
          async function init() {
              const res = await fetch('/api/list');
              if (res.status === 401) return location.reload();
              apiData = await res.json();
              render();
          }
  
          function render() {
              const tbody = document.getElementById('apiTable');
              tbody.innerHTML = apiData.map((item, idx) => \`
                  <tr class="api-row \${!item.enabled ? 'disabled-api' : ''}">
                      <td class="text-muted">\${idx + 1}</td>
                      <td>
                          <strong class="\${item.is_adult ? 'text-danger' : ''}">\${item.name}</strong>
                          \${item.is_adult ? '<span class="badge badge-adult ms-1">18+</span>' : ''}
                      </td>
                      <td><span class="url-text">\${item.url}</span></td>
                      <td><span class="badge bg-\${getStatusColor(item.status)}">\${item.status || '-'}</span></td>
                      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" \${item.enabled ? 'checked' : ''} onclick="toggleEnable(\${idx})"></div></td>
                      <td class="text-center">
                          <div class="btn-group btn-group-sm">
                              <button class="btn btn-light" onclick="move(\${idx}, -1)" \${idx === 0 ? 'disabled' : ''}><i class="bi bi-chevron-up"></i></button>
                              <button class="btn btn-light" onclick="move(\${idx}, 1)" \${idx === apiData.length - 1 ? 'disabled' : ''}><i class="bi bi-chevron-down"></i></button>
                              <button class="btn btn-light text-primary" onclick="showEditModal(\${idx})"><i class="bi bi-pencil-square"></i></button>
                              <button class="btn btn-light text-danger" onclick="removeApi(\${idx})"><i class="bi bi-trash"></i></button>
                          </div>
                      </td>
                  </tr>
              \`).join('');
          }
  
          function getStatusColor(s) {
              return s === '有效' ? 'success' : (s === '无效' ? 'danger' : (s === '超时' ? 'warning' : 'secondary'));
          }
  
          function parseApiList(json) {
              let list = [];
              function scan(obj, parentKey = '') {
                  if (!obj || typeof obj !== 'object') return;
                  const rawUrl = obj.api || obj.url;
                  if (rawUrl && typeof rawUrl === 'string') {
                      const cleanUrl = rawUrl.trim().replace(/[\\\`\\"']/g, '');
                      if (cleanUrl.startsWith('http')) {
                          list.push({
                              name: (obj.name || obj.title || parentKey || '未命名').toString().trim(),
                              url: cleanUrl,
                              is_adult: !!obj.is_adult || parentKey.toLowerCase().includes('adult') || cleanUrl.includes('adult'),
                              enabled: true, status: '未检查'
                          });
                          return;
                      }
                  }
                  if (Array.isArray(obj)) obj.forEach(item => scan(item, parentKey));
                  else Object.entries(obj).forEach(([key, val]) => scan(val, key));
              }
              scan(json);
              const seen = new Set();
              return list.filter(item => {
                  const isDup = seen.has(item.url);
                  seen.add(item.url); return !isDup;
              });
          }
  
          async function sync() { await fetch('/api/save-all', { method: 'POST', body: JSON.stringify(apiData) }); render(); }
          
          function copyLink(input) {
              input.select();
              document.execCommand('copy');
              const btn = input.nextElementSibling;
              const oldText = btn.innerHTML;
              btn.innerText = '已复制';
              setTimeout(() => btn.innerHTML = oldText, 1500);
          }
  
          function batchAction(type) {
              if (type === 'delete_invalid') {
                  apiData = apiData.filter(i => i.status !== '无效' && i.status !== '超时');
              } else if (type === 'enable_adult') {
                  apiData.forEach(i => { if(i.is_adult) i.enabled = true; });
              } else if (type === 'disable_adult') {
                  apiData.forEach(i => { if(i.is_adult) i.enabled = false; });
              }
              sync();
          }
  
          function toggleEnable(idx) { apiData[idx].enabled = !apiData[idx].enabled; sync(); }
          function move(idx, step) { [apiData[idx], apiData[idx + step]] = [apiData[idx + step], apiData[idx]]; sync(); }
          function removeApi(idx) { if(confirm('删除？')) { apiData.splice(idx, 1); sync(); } }
          function clearList() { if(confirm('清空？')) { apiData = []; sync(); } }
  
          function showAddModal() {
              document.getElementById('editIdx').value = '';
              document.getElementById('editName').value = '';
              document.getElementById('editUrl').value = '';
              document.getElementById('editAdult').checked = false;
              modal.show();
          }
  
          function showEditModal(idx) {
              document.getElementById('editIdx').value = idx;
              document.getElementById('editName').value = apiData[idx].name;
              document.getElementById('editUrl').value = apiData[idx].url;
              document.getElementById('editAdult').checked = !!apiData[idx].is_adult;
              modal.show();
          }
  
          function saveApi() {
              const idx = document.getElementById('editIdx').value;
              const item = { 
                  name: document.getElementById('editName').value, 
                  url: document.getElementById('editUrl').value.trim(),
                  is_adult: document.getElementById('editAdult').checked,
                  enabled: true, status: '未检查'
              };
              if(!item.name || !item.url) return;
              if(idx === '') apiData.unshift(item);
              else apiData[idx] = { ...apiData[idx], ...item };
              modal.hide(); sync();
          }
  
          async function checkAll() {
              const btn = document.querySelector('button[onclick="checkAll()"]');
              btn.disabled = true; btn.innerHTML = '<i class="spinner-border spinner-border-sm"></i>';
              await fetch('/api/check-all');
              init();
              btn.disabled = false; btn.innerHTML = '<i class="bi bi-activity"></i> 全量自检';
          }
  
          function handleImport(input) {
              const reader = new FileReader();
              reader.onload = async (e) => {
                  try {
                      const list = parseApiList(JSON.parse(e.target.result));
                      list.forEach(item => {
                          if(!apiData.some(a => a.url === item.url)) apiData.push(item);
                      });
                      await sync();
                  } catch(err) { alert('失败'); }
                  input.value = '';
              };
              reader.readAsText(input.files[0]);
          }
  
          async function fetchRemote() {
              const url = document.getElementById('fetchUrl').value;
              if(!url) return;
              const btn = document.querySelector('button[onclick="fetchRemote()"]');
              btn.disabled = true;
              try {
                  const res = await fetch('/?url=' + encodeURIComponent(url));
                  const text = await res.text();
                  let list = [];
                  try { list = parseApiList(JSON.parse(text)); }
                  catch { list = (text.match(/https?:\\/\\/[^\\s"']+/g) || []).map(u => ({ name: '抓取', url: u, enabled: true, status: '未检查' })); }
                  fetchedTemp = list;
                  document.getElementById('fetchCount').innerText = fetchedTemp.length;
                  document.getElementById('fetchList').innerHTML = fetchedTemp.map((i, idx) => \`
                      <div class="px-2 py-1 border-bottom d-flex align-items-center">
                          <input class="form-check-input me-2" type="checkbox" value="\${idx}" id="f\${idx}" checked>
                          <label class="form-check-label flex-grow-1" for="f\${idx}">\${i.name} \${i.is_adult ? '🔞' : ''}</label>
                      </div>\`).join('');
                  document.getElementById('fetchStatus').style.display = 'block';
              } finally { btn.disabled = false; }
          }
  
          function batchSave() {
              Array.from(document.querySelectorAll('#fetchList input:checked')).forEach(c => {
                  const s = fetchedTemp[c.value];
                  if(!apiData.some(a => a.url === s.url)) apiData.push(s);
              });
              document.getElementById('fetchStatus').style.display = 'none';
              sync();
          }
  
          init();
      </script>
  </body>
  </html>
  `;
  }