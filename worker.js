/**
 * Cloudflare Worker: API Manager & Aggregator (密码保护安全版)
 */

export default {
    async fetch(request, env) {
      const url = new URL(request.url);
      const params = url.searchParams;
  
      // --- 1. 公开路径 (不需要密码) ---
      // 聚合导出链接必须公开，否则播放器无法访问
      if (url.pathname === '/export') return handleExport(env, params.get('adult') === '1', params.get('format'));
      // CORS 代理链接
      if (params.get('url')) {
        if (request.method === 'OPTIONS') {
          return new Response(null, {
            headers: {
              'Access-Control-Allow-Origin': '*',
              'Access-Control-Allow-Methods': 'GET, HEAD, POST, OPTIONS',
              'Access-Control-Allow-Headers': '*',
              'Access-Control-Max-Age': '86400',
            }
          });
        }
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
        case '/api/check-single': return handleCheckSingle(request, env);
        default: return new Response('Not Found', { status: 404 });
      }
    }
  };
  
  // --- 身份验证逻辑 ---
  function handleAuth(request, env) {
    const authHeader = request.headers.get('Authorization');
    
    // 从环境变量读取，若未设置则默认为 admin/admin
    const expectedUser = (env && env.ADMIN_USER) || 'admin';
    const expectedPass = (env && env.ADMIN_PASS) || 'admin';
    // 预计算期望的认证字符串
    const expectedAuth = 'Basic ' + btoa(expectedUser + ':' + expectedPass);

    if (authHeader === expectedAuth) {
      return null; // 验证通过
    }

    // 只要验证不通过（包括没带头信息或信息错误），都返回 401 并带上 WWW-Authenticate 头
    // 更改 realm 为 "API Manager" 以强制浏览器重新弹出登录框，清除旧缓存
    return new Response('Unauthorized: Please login with correct credentials.', {
      status: 401,
      headers: { 
        'WWW-Authenticate': 'Basic realm="API Manager"' 
      }
    });
  }
  
  // --- 代理逻辑 ---
  async function handleProxy(targetUrl, request) {
    try {
      const headers = new Headers();
      headers.set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
      headers.set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8');
      headers.set('Accept-Language', 'zh-CN,zh;q=0.9,en;q=0.8');
      
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 15000); // 15秒超时

      const response = await fetch(targetUrl, {
        headers: headers,
        signal: controller.signal,
        redirect: 'follow'
      });
      
      clearTimeout(timeoutId);

      const resHeaders = new Headers(response.headers);
      resHeaders.set('Access-Control-Allow-Origin', '*');
      resHeaders.set('Access-Control-Allow-Methods', 'GET, HEAD, POST, OPTIONS');
      resHeaders.set('Access-Control-Allow-Headers', '*');
      
      // 移除安全相关的头部，防止干扰代理
      resHeaders.delete('content-security-policy');
      resHeaders.delete('x-frame-options');

      return new Response(response.body, { status: response.status, headers: resHeaders });
    } catch (e) { 
      return new Response(JSON.stringify({ error: 'Proxy Error', message: e.message }), { 
        status: 502, 
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' } 
      }); 
    }
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
  
  async function handleCheckSingle(request, env) {
    const url = new URL(request.url);
    const idx = parseInt(url.searchParams.get('idx'));
    let list = JSON.parse(await env.DB.get('api_data') || '[]');
    
    if (isNaN(idx) || idx < 0 || idx >= list.length) {
      return new Response(JSON.stringify({ error: 'Invalid index' }), { status: 400 });
    }

    let item = list[idx];
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 12000);

    try {
      if (!item.url || !item.url.startsWith('http')) {
        item.status = '无效 (URL格式错误)';
        item.code = 0;
      } else {
        const res = await fetch(item.url, { 
          method: 'GET',
          headers: {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': '*/*',
            'Accept-Language': 'zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control': 'no-cache'
          },
          signal: controller.signal,
          redirect: 'follow'
        });

        if (res.status >= 200 && res.status < 400) {
          let isValid = true; 
          let checkNote = '';

          try {
            const contentLength = parseInt(res.headers.get('content-length') || '0');
            if (contentLength > 2 * 1024 * 1024) {
                checkNote = ' (大文件跳过校验)';
            } else {
                const text = await res.text();
                const lowerText = text.toLowerCase();
                
                let contentMatch = false;
                if (text.trim().startsWith('{') || text.trim().startsWith('[')) {
                    try {
                        const json = JSON.parse(text);
                        if (json.sites || json.urls || json.list || json.data || (Array.isArray(json) && json.length > 0)) {
                            contentMatch = true;
                        }
                    } catch (e) {}
                }
                if (!contentMatch && (lowerText.includes('<video>') || lowerText.includes('<rss') || lowerText.includes('<vod>') || lowerText.includes('<?xml'))) {
                    contentMatch = true;
                }
                if (!contentMatch && (lowerText.includes('vod') || lowerText.includes('api') || lowerText.includes('url')) && text.length > 50) {
                    contentMatch = true;
                }

                if (!contentMatch && text.length > 0 && text.length < 1000) {
                    isValid = false;
                    checkNote = ' (内容校验失败)';
                }
            }
          } catch (e) {
            checkNote = ' (内容读取失败)';
          }

          item.status = isValid ? '有效' : '无效内容';
          if (checkNote) item.status += checkNote;
          item.code = res.status;
        } else {
          item.status = '无效 (' + res.status + ')';
          item.code = res.status;
        }
      }
    } catch (e) { 
      if (e.name === 'AbortError') {
        item.status = '超时';
      } else {
        item.status = '错误 (' + e.message.substring(0, 20) + ')';
      }
      item.code = 0; 
    } finally {
      clearTimeout(timeoutId);
    }

    list[idx] = item;
    await env.DB.put('api_data', JSON.stringify(list));
    return new Response(JSON.stringify({ success: true, item: item }));
  }
  
  async function handleExport(env, includeAdult, format = 'standard') {
    const list = JSON.parse(await env.DB.get('api_data') || '[]');
    const filtered = list.filter(i => i.enabled && (includeAdult || !i.is_adult));
    
    if (format === '95') {
      // 导出为 95.json 格式 (数组)
      const data95 = filtered.map(i => {
        let key = '';
        try { key = new URL(i.url).hostname.replace(/\./g, '_'); } catch(e) { key = i.name; }
        return {
          name: i.name,
          key: key,
          api: i.url,
          detail: '',
          disabled: false,
          is_adult: !!i.is_adult
        };
      });
      return new Response(JSON.stringify(data95, null, 2), { 
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' } 
      });
    } else if (format === '95configplus') {
      // 导出为 95configplus.json 格式 (对象)
      const api_site = {};
      filtered.forEach(i => {
        let key = '';
        try { key = new URL(i.url).hostname.replace(/\./g, '_'); } catch(e) { key = i.name; }
        api_site[key] = {
          api: i.url,
          name: i.name,
          detail: '',
          is_adult: !!i.is_adult
        };
      });
      return new Response(JSON.stringify({ cache_time: 7200, api_site }, null, 2), { 
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' } 
      });
    }

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
                  <button onclick="checkUntested()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-play-circle"></i> 自检未检测</button>
                  <button onclick="document.getElementById('importFile').click()" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-arrow-up"></i> 导入</button>
                  <button onclick="showConverterModal()" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-left-right"></i> 格式转换</button>
                  <button onclick="showBatchModal()" class="btn btn-outline-info btn-sm"><i class="bi bi-link-45deg"></i> 批量添加</button>
                  <button onclick="showAddModal()" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> 手动添加</button>
                  <input type="file" id="importFile" accept=".json" style="display:none" onchange="handleImport(this)">
              </div>
          </div>
  
          <!-- 聚合链接 -->
          <div class="row g-3 mb-4">
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm">
                      <div class="card-body p-2">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                              <label class="small text-muted">标准订阅 (无 18+)</label>
                          </div>
                          <div class="input-group input-group-sm">
                              <input type="text" id="linkStandard" class="form-control copy-input" value="${origin}/export" readonly onclick="copyLink(this)">
                              <button class="btn btn-dark" onclick="copyLink(this.previousElementSibling)">复制</button>
                              <button class="btn btn-outline-dark dropdown-toggle" type="button" data-bs-toggle="dropdown">导出</button>
                              <ul class="dropdown-menu dropdown-menu-end shadow">
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('standard', false)">标准格式 (JSON)</a></li>
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('95', false)">95.json (数组)</a></li>
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('95configplus', false)">95configplus.json (对象)</a></li>
                              </ul>
                          </div>
                      </div>
                  </div>
              </div>
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm">
                      <div class="card-body p-2 text-danger">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                              <label class="small text-danger">全量订阅 (含 18+)</label>
                          </div>
                          <div class="input-group input-group-sm">
                              <input type="text" id="linkAdult" class="form-control copy-input border-danger text-danger" value="${origin}/export?adult=1" readonly onclick="copyLink(this)">
                              <button class="btn btn-danger" onclick="copyLink(this.previousElementSibling)">复制</button>
                              <button class="btn btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">导出</button>
                              <ul class="dropdown-menu dropdown-menu-end shadow border-danger">
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('standard', true)">标准格式 (JSON)</a></li>
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('95', true)">95.json (数组)</a></li>
                                  <li><a class="dropdown-item small" href="javascript:void(0)" onclick="downloadSubscriptionJson('95configplus', true)">95configplus.json (对象)</a></li>
                              </ul>
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
                      <button id="btnDeleteInvalid" onclick="batchAction('delete_invalid')" class="btn btn-outline-danger">删除无效</button>
                      <button id="btnDeleteError" onclick="batchAction('delete_error')" class="btn btn-outline-danger">删除错误</button>
                      <button onclick="batchAction('disable_adult')" class="btn btn-outline-dark">禁用成人</button>
                      <button onclick="batchAction('enable_adult')" class="btn btn-outline-warning text-dark">开启成人</button>
                      <button onclick="clearList()" class="btn btn-danger">清空列表</button>
                  </div>
              </div>
              <div id="fetchStatus" class="card-footer bg-white border-top-0 pt-0" style="display:none;">
                  <div class="d-flex justify-content-between align-items-center small mb-2 border-top pt-2">
                      <div class="d-flex align-items-center gap-2">
                          <span>发现 <b id="fetchCount" class="text-primary">0</b> 个接口</span>
                          <div class="btn-group btn-group-sm">
                              <button onclick="toggleAllFetch(true)" class="btn btn-link p-0 text-decoration-none small">全选</button>
                              <span class="text-muted mx-1">/</span>
                              <button onclick="toggleAllFetch(false)" class="btn btn-link p-0 text-decoration-none small">取消</button>
                          </div>
                      </div>
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

      <!-- 批量添加模态框 -->
      <div class="modal fade" id="batchModal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content border-0 shadow">
                  <div class="modal-header py-2 border-bottom-0"><h6 class="modal-title">批量添加接口</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body pt-0">
                      <div class="mb-2">
                          <label class="form-label small mb-1">内容 (每行一个: 名称 链接)</label>
                          <textarea id="batchInput" class="form-control form-control-sm" rows="10" placeholder="示例：&#10;Google https://google.com&#10;Baidu https://baidu.com"></textarea>
                      </div>
                      <div class="form-check"><input class="form-check-input" type="checkbox" id="batchAdult"><label class="form-check-label small" for="batchAdult">统一标记为成人内容 (18+)</label></div>
                  </div>
                  <div class="modal-footer border-0 pt-0"><button type="button" onclick="saveBatchApi()" class="btn btn-primary btn-sm w-100">批量保存</button></div>
              </div>
          </div>
      </div>

      <!-- 格式转换模态框 -->
      <div class="modal fade" id="converterModal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content border-0 shadow">
                  <div class="modal-header py-2 border-bottom-0"><h6 class="modal-title">JSON 格式转换工具</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body pt-0">
                      <div class="mb-3">
                          <label class="form-label small mb-1">选择源文件</label>
                          <input type="file" id="convertInput" class="form-control form-control-sm" accept=".json">
                          <div class="form-text" style="font-size:0.7em;">支持 JSON 数组格式, JSON 对象格式, 或标准格式</div>
                      </div>
                      <div class="mb-3">
                          <label class="form-label small mb-1">目标格式</label>
                          <select id="targetFormat" class="form-select form-select-sm">
                              <option value="95">转换为 JSON 数组格式</option>
                              <option value="95configplus">转换为 JSON 对象格式</option>
                              <option value="standard">转换为 标准格式</option>
                          </select>
                      </div>
                  </div>
                  <div class="modal-footer border-0 pt-0">
                      <button type="button" onclick="doConversion()" class="btn btn-warning btn-sm w-100 fw-bold">开始转换并下载</button>
                  </div>
              </div>
          </div>
      </div>
  
      <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
      <script>
          let apiData = [];
          let fetchedTemp = [];
          const modal = new bootstrap.Modal(document.getElementById('apiModal'));
          const batchModal = new bootstrap.Modal(document.getElementById('batchModal'));
          const converterModal = new bootstrap.Modal(document.getElementById('converterModal'));
  
          async function downloadSubscriptionJson(format, isAdult) {
              let url = '/export?format=' + format;
              if (isAdult) url += '&adult=1';
              
              try {
                  const res = await fetch(url);
                  const data = await res.json();
                  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                  const downloadUrl = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = downloadUrl;
                  const filename = \`apis_\${format}_\${isAdult ? 'adult' : 'standard'}.json\`;
                  a.download = filename;
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(downloadUrl);
              } catch (e) {
                  alert('导出失败: ' + e.message);
              }
          }

          async function init() {
              try {
                  const res = await fetch('/api/list');
                  if (res.status === 401) return location.reload();
                  const text = await res.text();
                  try {
                      apiData = JSON.parse(text);
                  } catch (e) {
                      console.error('JSON Parse Error:', e, text);
                      alert('数据解析失败，请检查控制台');
                      return;
                  }
                  render();
              } catch (e) {
                  console.error('Init Error:', e);
                  alert('初始化失败: ' + e.message);
              }
          }
  
          function render() {
              const tbody = document.getElementById('apiTable');
              if (!tbody) return;
              if (!Array.isArray(apiData)) {
                  console.error('apiData is not an array:', apiData);
                  tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">数据格式错误</td></tr>';
                  return;
              }

              // 更新批量操作按钮数量
              const invalidCount = apiData.filter(i => {
                  const s = i.status || '';
                  return s.startsWith('无效') || s.startsWith('超时');
              }).length;
              const errorCount = apiData.filter(i => {
                  const s = i.status || '';
                  return s.startsWith('错误');
              }).length;
              const btnInvalid = document.getElementById('btnDeleteInvalid');
              const btnError = document.getElementById('btnDeleteError');
              if (btnInvalid) btnInvalid.innerHTML = \`删除无效 \${invalidCount > 0 ? \`(\${invalidCount})\` : ''}\`;
              if (btnError) btnError.innerHTML = \`删除错误 \${errorCount > 0 ? \`(\${errorCount})\` : ''}\`;

              if (apiData.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无接口，请点击右上角添加</td></tr>';
                  return;
              }
              tbody.innerHTML = apiData.map((item, idx) => \`
                  <tr class="api-row \${!item.enabled ? 'disabled-api' : ''}">
                      <td class="text-muted small">\${idx + 1}</td>
                      <td>
                          <div class="d-flex align-items-center">
                              <strong class="\${item.is_adult ? 'text-danger' : ''} text-truncate" style="max-width: 150px;">\${item.name}</strong>
                              \${item.is_adult ? '<span class="badge badge-adult ms-1">18+</span>' : ''}
                          </div>
                      </td>
                      <td><span class="url-text" title="\${item.url}">\${item.url}</span></td>
                      <td><span class="badge bg-\${getStatusColor(item.status)}">\${item.status || '-'}</span></td>
                      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" \${item.enabled ? 'checked' : ''} onclick="toggleEnable(\${idx})"></div></td>
                      <td class="text-center">
                          <div class="btn-group btn-group-sm">
                              <button class="btn btn-light" title="上移" onclick="move(\${idx}, -1)" \${idx === 0 ? 'disabled' : ''}><i class="bi bi-chevron-up"></i></button>
                              <button class="btn btn-light" title="下移" onclick="move(\${idx}, 1)" \${idx === apiData.length - 1 ? 'disabled' : ''}><i class="bi bi-chevron-down"></i></button>
                              <button class="btn btn-light text-primary" title="编辑" onclick="showEditModal(\${idx})"><i class="bi bi-pencil-square"></i></button>
                              <button class="btn btn-light text-danger" title="删除" onclick="removeApi(\${idx})"><i class="bi bi-trash"></i></button>
                          </div>
                      </td>
                  </tr>
              \`).join('');
          }
  
          function getStatusColor(s) {
              return s === '有效' ? 'success' : (s === '无效' || s === '错误' ? 'danger' : (s === '超时' ? 'warning' : 'secondary'));
          }
  
          function parseApiList(json) {
            let list = [];
            const seen = new Set();

            // 检查是否是 95configplus 格式 (对象包含 api_site)
            if (json && json.api_site && typeof json.api_site === 'object') {
                Object.entries(json.api_site).forEach(([key, val]) => {
                    if (val && val.api && !seen.has(val.api)) {
                        list.push({
                            name: val.name || key,
                            url: val.api,
                            is_adult: !!val.is_adult,
                            enabled: true,
                            status: '未检查'
                        });
                        seen.add(val.api);
                    }
                });
                return list;
            }

            // 检查是否是 95.json 格式 (数组包含 api 字段)
            if (Array.isArray(json) && json.length > 0 && json[0].api) {
                json.forEach(val => {
                    if (val && val.api && !seen.has(val.api)) {
                        list.push({
                            name: val.name || val.key || '未命名',
                            url: val.api,
                            is_adult: !!val.is_adult,
                            enabled: true,
                            status: '未检查'
                        });
                        seen.add(val.api);
                    }
                });
                return list;
            }

            function scan(obj, parentKey = '') {
                if (!obj) return;
                if (typeof obj === 'string') {
                    // 处理带反引号和空格的非标准 URL
                    const trimmed = obj.replace(/[\\x60\\"']/g, '').trim();
                    if (trimmed.startsWith('http')) {
                        if (!seen.has(trimmed)) {
                            let name = parentKey || '未命名';
                            // 尝试从 URL 提取名称
                            if (!parentKey || /^[0-9]+$/.test(parentKey)) {
                                try {
                                    const u = new URL(trimmed);
                                    const pathName = u.pathname.split('/').pop();
                                    if (pathName && pathName.includes('.')) {
                                        name = pathName.split('.').slice(0, -1).join('.');
                                    } else {
                                        name = u.hostname;
                                    }
                                } catch(e) {}
                            }
                            list.push({ name: name, url: trimmed, enabled: true, status: '未检查' });
                            seen.add(trimmed);
                        }
                    }
                    return;
                }
                if (typeof obj !== 'object') return;
                
                let rawUrl = obj.api || obj.url || obj.api_url || obj.source;
                let rawName = obj.name || obj.title || obj.key || parentKey;
                
                if (rawUrl && typeof rawUrl === 'string') {
                    // 清理反引号、引号和反斜杠
                    const cleanUrl = rawUrl.replace(/[\\x60\\"']/g, '').replace(/\\\\/g, '').trim();
                    if (cleanUrl.startsWith('http') && !seen.has(cleanUrl)) {
                        // 如果名称太通用或为空，尝试从 URL 提取
                        if (!rawName || rawName === '未命名' || /^[0-9]+$/.test(rawName.toString())) {
                            try {
                                const u = new URL(cleanUrl);
                                const pathName = u.pathname.split('/').pop();
                                if (pathName && pathName.includes('.')) {
                                    rawName = pathName.split('.').slice(0, -1).join('.');
                                }
                            } catch(e) {}
                        }
                        list.push({
                            name: (rawName || '未命名').toString().trim(),
                            url: cleanUrl,
                            is_adult: !!obj.is_adult || (parentKey && parentKey.toLowerCase().includes('adult')) || cleanUrl.includes('adult'),
                            enabled: true, status: '未检查'
                        });
                        seen.add(cleanUrl);
                    }
                }
                
                // 特殊处理：如果对象中有 ext 字段且也是 URL
                if (obj.ext && typeof obj.ext === 'string') {
                    const extUrl = obj.ext.replace(/[\\x60\\"']/g, '').replace(/\\\\/g, '').trim();
                    if (extUrl.startsWith('http') && !seen.has(extUrl)) {
                        list.push({
                            name: (rawName ? rawName + '-ext' : '扩展接口').toString().trim(),
                            url: extUrl,
                            is_adult: !!obj.is_adult || extUrl.includes('adult'),
                            enabled: true, status: '未检查'
                        });
                        seen.add(extUrl);
                    }
                }

                if (Array.isArray(obj)) obj.forEach(item => scan(item, parentKey));
                else Object.entries(obj).forEach(([key, val]) => scan(val, key));
            }
            scan(json);
            return list;
        }

        function fuzzyExtract(text) {
            let list = [];
            const seen = new Set();
            let processedText = text;
            try { processedText = decodeURIComponent(text); } catch(e) {}
            
            // 提取所有 URL，并尝试在附近寻找名称
            const urlPattern = /https?:\\/\\/[^\\s"'<>(){}\\[\\]\\\\^|]+/g;
            let match;
            const lines = text.split('\\n');
            
            while ((match = urlPattern.exec(processedText)) !== null) {
                const extractedUrl = match[0];
                const urlIndex = match.index;
                
                // 清理 URL：移除末尾标点和可能包裹的反引号
                const cleanUrl = extractedUrl.replace(/[.,;!\\x60]$/, '').replace(/^[ \\x60"']+|[ \\x60"']+$/g, '').trim();
                if (cleanUrl.length < 10 || seen.has(cleanUrl)) continue;
                
                const pureUrl = cleanUrl.split('?')[0].toLowerCase();
                if (/\\.(png|jpg|jpeg|gif|webp|svg|css|ico)$/.test(pureUrl)) continue;

                let name = '';
                let isAdult = cleanUrl.includes('adult');

                // 1. 尝试从 URL 前面的 key 提取 (例如 "name": "xxx")
                // 搜索范围：URL 之前 200 字符
                const searchArea = processedText.substring(Math.max(0, urlIndex - 200), urlIndex);
                
                // 优先寻找 "name": "xxx" 这种明确的对
                const nameMatch = [...searchArea.matchAll(/["' ]?(?:name|title|key)["' ]?\\s*[:=]\\s*["' ]?([^"'{}:,\\n]+)["' ]?/gi)].pop();
                if (nameMatch) {
                    name = nameMatch[1].trim();
                }
                
                // 检查附近是否有 is_adult: true
                if (!isAdult) {
                    isAdult = /is_adult["' ]?\\s*[:=]\\s*(?:true|1)/i.test(searchArea);
                }
                
                // 2. 如果没找到，检查 URL 紧邻的前缀 (排除通用 key)
                if (!name || /^(url|api|source|link|ext|api_url)$/i.test(name)) {
                    const prefixMatch = searchArea.match(/["' ]?([^"'\\s:=]+)["' ]?\\s*[:=]\\s*["' ]?$/);
                    if (prefixMatch && prefixMatch[1]) {
                        const key = prefixMatch[1].toLowerCase();
                        if (!['url', 'api', 'source', 'link', 'ext', 'api_url'].includes(key)) {
                            name = prefixMatch[1];
                        }
                    }
                }
                
                // 3. 兜底逻辑：代码注释
                if (!name || name.length < 2 || /^(url|api|source|link|ext|api_url)$/i.test(name)) {
                    for (let i = 0; i < lines.length; i++) {
                        if (lines[i].includes(extractedUrl)) {
                            const codeMatch = lines[i].match(/(?:import|const|let|var)\\s+([a-zA-Z0-9_$]+)\\s+(?:from|=)/);
                            if (codeMatch && codeMatch[1]) {
                                name = codeMatch[1];
                                break;
                            }
                            if (i > 0) {
                                const prevLine = lines[i - 1].trim();
                                const commentMatch = prevLine.match(/\\/\\/\\s*(.+)$/);
                                if (commentMatch && commentMatch[1]) {
                                    name = commentMatch[1].trim();
                                    break;
                                }
                            }
                        }
                    }
                }

                // 4. 兜底逻辑：文件名或域名
                if (!name || name.length < 2 || /^(url|api|source|link|ext|api_url)$/i.test(name)) {
                    try {
                        const urlObj = new URL(cleanUrl);
                        let filename = urlObj.pathname.split('/').pop();
                        if (filename) {
                            try { filename = decodeURIComponent(filename); } catch(e) {}
                            if (filename.includes('.')) {
                                name = filename.split('.').slice(0, -1).join('.');
                            } else {
                                name = filename;
                            }
                        }
                        if (!name || name.length < 2) name = urlObj.hostname;
                    } catch(e) {
                        name = '抓取';
                    }
                }

                if (name.length > 30) name = name.substring(0, 30);

                list.push({ 
                    name: name, url: cleanUrl, 
                    is_adult: isAdult,
                    enabled: true, status: '未检查' 
                });
                seen.add(cleanUrl);
            }
            return list;
        }

          function dedupe(list) {
              const seen = new Set();
              return list.filter(item => {
                  const isDup = seen.has(item.url);
                  seen.add(item.url); return !isDup;
              });
          }

          async function sync() { await fetch('/api/save-all', { method: 'POST', body: JSON.stringify(apiData) }); render(); }
          
          function updateLink(format, isAdult) {
              const origin = window.location.origin;
              let url = origin + '/export';
              const params = [];
              if (isAdult) params.push('adult=1');
              if (format !== 'standard') params.push('format=' + format);
              if (params.length > 0) url += '?' + params.join('&');
              
              const id = isAdult ? 'linkAdult' : 'linkStandard';
              document.getElementById(id).value = url;
          }

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
                  apiData = apiData.filter(i => {
                      const s = i.status || '';
                      return !s.startsWith('无效') && !s.startsWith('超时');
                  });
              } else if (type === 'delete_error') {
                  apiData = apiData.filter(i => {
                      const s = i.status || '';
                      return !s.startsWith('错误');
                  });
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
              if (!confirm('确定开始全量自检吗？这将逐一检测所有启用接口的状态。')) return;
              
              const btn = document.querySelector('button[onclick="checkAll()"]');
              const oldText = btn.innerHTML;
              btn.disabled = true;
              
              const enabledIndices = [];
              apiData.forEach((item, idx) => {
                  if (item.enabled) enabledIndices.push(idx);
              });

              let count = 0;
              for (const idx of enabledIndices) {
                  count++;
                  btn.innerHTML = \`\<i class="bi bi-hourglass-split"\>\</i\> 自检中 (\${count}/\${enabledIndices.length})\`;
                  try {
                      const res = await fetch('/api/check-single?idx=' + idx);
                      const result = await res.json();
                      if (result.success) {
                          apiData[idx] = result.item;
                          render(); // 实时更新 UI
                      }
                  } catch (e) {
                      console.error('Check failed for idx ' + idx, e);
                  }
              }

              btn.innerHTML = oldText;
              btn.disabled = false;
              alert('全量自检完成！');
          }

          async function checkUntested() {
              const targetIndices = [];
              apiData.forEach((item, idx) => {
                  if (item.enabled && (item.status === '未检查' || !item.status)) {
                      targetIndices.push(idx);
                  }
              });

              if (targetIndices.length === 0) {
                  alert('没有发现需要检测的接口（状态为“未检查”且已启用的接口）。');
                  return;
              }

              if (!confirm(\`发现 \${targetIndices.length} 个未检测接口，确定开始自检吗？\`)) return;
              
              const btn = document.querySelector('button[onclick="checkUntested()"]');
              const oldText = btn.innerHTML;
              btn.disabled = true;
              
              let count = 0;
              for (const idx of targetIndices) {
                  count++;
                  btn.innerHTML = \`\<i class="bi bi-hourglass-split"\>\</i\> 自检中 (\${count}/\${targetIndices.length})\`;
                  try {
                      const res = await fetch('/api/check-single?idx=' + idx);
                      const result = await res.json();
                      if (result.success) {
                          apiData[idx] = result.item;
                          render();
                      }
                  } catch (e) {
                      console.error('Check failed for idx ' + idx, e);
                  }
              }

              btn.innerHTML = oldText;
              btn.disabled = false;
              alert('未检测接口自检完成！');
          }
  
          function handleImport(input) {
              if (!input.files || input.files.length === 0) return;
              const reader = new FileReader();
              reader.onload = async (e) => {
                  try {
                      const text = e.target.result;
                      let list = [];
                      try {
                          const json = JSON.parse(text);
                          list = parseApiList(json);
                      } catch (err) {
                          // 如果不是纯 JSON，尝试模糊提取
                          list = fuzzyExtract(text);
                      }
                      
                      if (list.length === 0) {
                          alert('未在文件中找到有效的 API 链接');
                          return;
                      }

                      let addedCount = 0;
                      list.forEach(item => {
                          if(!apiData.some(a => a.url === item.url)) {
                              apiData.push(item);
                              addedCount++;
                          }
                      });
                      
                      if (addedCount > 0) {
                          await sync();
                          alert(\`成功导入 \${addedCount} 个新链接\`);
                      } else {
                          alert('未发现新链接（可能已存在）');
                      }
                  } catch(err) { 
                      alert('导入失败: ' + err.message); 
                  }
                  input.value = '';
              };
              reader.readAsText(input.files[0]);
          }
  
          async function fetchRemote(targetUrl = null) {
              const urlInput = document.getElementById('fetchUrl');
              const urlToFetch = targetUrl || urlInput.value.trim();
              if(!urlToFetch) return;
              
              const btn = document.querySelector('button[onclick="fetchRemote()"]');
              const listContainer = document.getElementById('fetchList');
              const statusDiv = document.getElementById('fetchStatus');
              
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 抓取中...';
              
              try {
                  const res = await fetch('/?url=' + encodeURIComponent(urlToFetch));
                  if (!res.ok) throw new Error('代理请求失败: ' + res.status);
                  const text = await res.text();
                  
                  let list = [];
                  try {
                      // 优先尝试 JSON 解析
                      const json = JSON.parse(text);
                      list = parseApiList(json);
                  } catch (e) {
                      // 降级使用模糊抓取
                      list = fuzzyExtract(text);
                  }
                  
                  if (list.length === 0) {
                      alert('未能从该链接提取到有效的 API 链接');
                      statusDiv.style.display = 'none';
                      return;
                  }

                  fetchedTemp = list;
                  document.getElementById('fetchCount').innerText = fetchedTemp.length;
                  listContainer.innerHTML = fetchedTemp.map((i, idx) => \`
                      <div class="px-2 py-1 border-bottom d-flex align-items-center">
                          <input class="form-check-input me-2" type="checkbox" value="\${idx}" id="f\${idx}" checked>
                          <label class="form-check-label flex-grow-1 text-truncate" for="f\${idx}" title="\${i.url}">
                              \${i.name} <small class="text-muted">\${i.url.substring(0, 30)}...</small> \${i.is_adult ? '🔞' : ''}
                          </label>
                      </div>\`).join('');
                  statusDiv.style.display = 'block';
                  // 自动滚动到抓取状态区域
                  statusDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
              } catch (err) {
                  alert('抓取失败: ' + err.message);
              } finally {
                  btn.disabled = false;
                  btn.innerHTML = '抓取';
              }
          }

          function showBatchModal() {
              document.getElementById('batchInput').value = '';
              document.getElementById('batchAdult').checked = false;
              batchModal.show();
          }

          function showConverterModal() {
              document.getElementById('convertInput').value = '';
              converterModal.show();
          }

          function doConversion() {
              const fileInput = document.getElementById('convertInput');
              const targetFormat = document.getElementById('targetFormat').value;
              
              if (!fileInput.files[0]) {
                  alert('请选择要转换的文件');
                  return;
              }

              const reader = new FileReader();
              reader.onload = function(e) {
                  try {
                      const content = JSON.parse(e.target.result);
                      let apis = [];
                      
                      // 识别并解析源格式
                    if (Array.isArray(content)) {
                        // JSON 数组格式
                        apis = content.map(item => ({
                            name: item.name || '未知',
                            url: item.api || item.url || '',
                            is_adult: !!item.is_adult
                        }));
                    } else if (content.api_site) {
                        // JSON 对象格式
                        for (const key in content.api_site) {
                            const site = content.api_site[key];
                            apis.push({
                                name: site.name || '未知',
                                url: site.api || '',
                                is_adult: !!site.is_adult
                            });
                        }
                    } else if (content.urls) {
                          // 标准 JSON 格式 (带 urls 键)
                          apis = content.urls.map(item => ({
                              name: item.name,
                              url: item.url,
                              is_adult: !!item.is_adult
                          }));
                      } else {
                          throw new Error('无法识别的源格式');
                      }

                      // 转换为目标格式
                      let output;
                      let filename = 'converted_apis.json';
                      
                      const generateKey = (url, name) => {
                          try { return new URL(url).hostname.replace(/\\./g, '_'); } catch(e) { return name.replace(/\\s+/g, '_'); }
                      };

                      if (targetFormat === '95') {
                        // 转换为 JSON 数组
                        output = apis.map(item => ({
                            name: item.name,
                            key: generateKey(item.url, item.name),
                            api: item.url,
                            detail: "",
                            disabled: false,
                            is_adult: item.is_adult
                        }));
                        filename = 'array_apis.json';
                    } else if (targetFormat === '95configplus') {
                        // 转换为 JSON 对象
                        const api_site = {};
                        apis.forEach(item => {
                            const key = generateKey(item.url, item.name);
                            api_site[key] = {
                                api: item.url,
                                name: item.name,
                                detail: "",
                                is_adult: item.is_adult
                            };
                        });
                        output = { cache_time: 7200, api_site };
                        filename = 'object_apis.json';
                    } else {
                          // 转换为标准格式 (对象含 urls 数组)
                          output = { urls: apis.map(i => ({ name: i.name, url: i.url, is_adult: i.is_adult })) };
                          filename = 'standard_apis.json';
                      }

                      // 下载文件
                      const blob = new Blob([JSON.stringify(output, null, 2)], { type: 'application/json' });
                      const url = URL.createObjectURL(blob);
                      const a = document.createElement('a');
                      a.href = url;
                      a.download = filename;
                      document.body.appendChild(a);
                      a.click();
                      document.body.removeChild(a);
                      URL.revokeObjectURL(url);
                      
                      alert('转换成功并已开始下载！');
                      converterModal.hide();
                  } catch (err) {
                      alert('转换失败: ' + err.message);
                  }
              };
              reader.readAsText(fileInput.files[0]);
          }

          async function saveBatchApi() {
              const text = document.getElementById('batchInput').value;
              const isAdult = document.getElementById('batchAdult').checked;
              if (!text.trim()) return;

              const lines = text.split(/[\\r\\n]+/);
              let addedCount = 0;
              
              lines.forEach(line => {
                  const trimmedLine = line.trim();
                  if (!trimmedLine) return;
                  
                  // 更加鲁棒的解析：寻找最后一个 http 出现的索引
                  const httpIndex = trimmedLine.lastIndexOf('http');
                  if (httpIndex !== -1) {
                      const url = trimmedLine.substring(httpIndex).trim();
                      let name = trimmedLine.substring(0, httpIndex).trim();
                      
                      // 如果名称为空或包含特殊分隔符，进一步清理
                      if (!name) {
                          try {
                              const u = new URL(url);
                              name = u.hostname;
                          } catch(e) {
                              name = '未命名';
                          }
                      } else {
                          // 移除末尾的可能分隔符如 : , \t
                          name = name.replace(/[:：,，\\s]+$/, '');
                      }

                      if (!Array.isArray(apiData)) apiData = [];
                      if (!apiData.some(a => a.url === url)) {
                          apiData.push({
                              name: name,
                              url: url,
                              is_adult: isAdult,
                              enabled: true,
                              status: '未检查'
                          });
                          addedCount++;
                      }
                  }
              });

              if (addedCount > 0) {
                  batchModal.hide();
                  await sync();
                  alert(\`成功批量添加 \${addedCount} 个接口\`);
              } else {
                  alert('未发现有效的新接口（请确保格式为：名称 链接）');
              }
          }

          function importFromUrl() {
              const url = prompt('请输入远程 JSON 或网页链接:');
              if (url && url.startsWith('http')) {
                  fetchRemote(url);
              }
          }
  
          function toggleAllFetch(checked) {
              document.querySelectorAll('#fetchList input[type="checkbox"]').forEach(cb => cb.checked = checked);
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