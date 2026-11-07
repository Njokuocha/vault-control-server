import './style.css';
import './app.css';
import QRCode from 'qrcode';
import {
  BackupDatabase,
  ClearLogs,
  GetLANIP,
  GetLogs,
  GetMetrics,
  GetStatus,
  GetTableRows,
  GetUptime,
  ListTables,
  RestartService,
  StartStack,
  StopStack,
} from '../wailsjs/go/main/App';

const services = [
  ['mariadb', 'MariaDB'],
  ['web', 'FrankenPHP'],
  ['reverb', 'Reverb'],
  ['queue1', 'Queue Broadcasts'],
  ['queue2', 'Queue Video'],
];

document.querySelector('#app').innerHTML = `
  <main class="shell">
    <header class="topbar glass">
      <div class="brand">
        <div class="brand-mark">V</div>
        <div>
          <p class="eyebrow">CLASSIFIED // TOP SECRET</p>
          <h1>VAULT CONTROL SERVER</h1>
        </div>
      </div>
      <div class="clock-wrap"><span class="live-dot"></span><span id="clock"></span><small>LOCAL CONTROL</small></div>
    </header>

    <section class="server-card glass">
      <div class="server-copy">
        <p class="eyebrow">SECURE LAN ACCESS</p>
        <h2>Server address</h2>
        <div class="address-row">
          <code id="server-address">Detecting local network…</code>
          <button class="button button-quiet" id="copy-address" type="button">COPY</button>
        </div>
        <p class="muted">Share this address with authorized devices on the same network.</p>
      </div>
      <div class="qr-panel">
        <canvas id="qr-code" width="116" height="116" aria-label="QR code for the server address"></canvas>
        <span>SCAN TO CONNECT</span>
      </div>
    </section>

    <section class="panel glass">
      <div class="section-heading">
        <div><p class="eyebrow">SYSTEMS MONITOR</p><h2>Service status</h2></div>
        <span id="overall-status" class="overall-status">ALL SERVICES OFFLINE</span>
      </div>
      <div id="service-list" class="service-list">
        ${services.map(([id, label]) => `
          <div class="service-row" data-service="${id}">
            <span class="led led-offline"></span>
            <span class="service-name">${label}</span>
            <span class="service-state">OFFLINE</span>
            ${['reverb', 'queue1', 'queue2'].includes(id) ? `<button type="button" class="text-button" data-restart="${id}">RESTART</button>` : '<span class="service-spacer"></span>'}
          </div>
        `).join('')}
      </div>
      <div class="controls">
        <button class="button button-primary" id="start-all" type="button">START ALL</button>
        <button class="button button-secondary" id="stop-all" type="button">STOP ALL</button>
        <button class="button button-quiet" id="restart-all" type="button">RESTART ALL</button>
        <span id="action-message" class="action-message" role="status"></span>
      </div>
    </section>

    <section class="metrics-grid">
      <article class="metric-card glass"><span>UPTIME</span><strong id="uptime">—</strong></article>
      <article class="metric-card glass"><span>CPU LOAD</span><strong id="cpu">—</strong></article>
      <article class="metric-card glass"><span>MEMORY</span><strong id="memory">—</strong></article>
      <article class="metric-card glass"><span>ACTIVE CONNECTIONS</span><strong id="connections">—</strong></article>
    </section>

    <div class="lower-grid">
      <section class="panel glass database-panel">
        <div class="section-heading">
          <div><p class="eyebrow">LOCAL ONLY · READ ONLY</p><h2>Database viewer</h2></div>
          <span class="lock-tag">HOST PC</span>
        </div>
        <p class="muted">Queries run through the local desktop controller and are never exposed over the LAN. Results are limited to 100 rows.</p>
        <div class="db-controls">
          <select id="table-select" aria-label="Database table"><option value="">Select a table</option></select>
          <button class="button button-quiet" id="load-table" type="button">LOAD</button>
        </div>
        <div class="table-wrap" id="table-results"><p class="placeholder">Start MariaDB to browse records.</p></div>
      </section>

      <section class="panel glass logs-panel">
        <div class="section-heading">
          <div><p class="eyebrow">APPLICATION DIAGNOSTICS</p><h2>Laravel logs</h2></div>
          <div class="log-actions">
            <button class="button button-quiet" id="refresh-logs" type="button">REFRESH</button>
            <button class="button button-danger" id="clear-logs" type="button">CLEAR</button>
          </div>
        </div>
        <pre id="log-output" class="log-output">Waiting for application log…</pre>
        <div class="backup-row">
          <span><strong>DATABASE BACKUP</strong><small>Saved outside the application folder</small></span>
          <button class="button button-primary" id="backup-database" type="button">BACKUP DATABASE NOW</button>
        </div>
      </section>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
  </main>
`;

const addressElement = document.querySelector('#server-address');
const actionMessage = document.querySelector('#action-message');
const toast = document.querySelector('#toast');

function showMessage(message, isError = false) {
  actionMessage.textContent = message;
  actionMessage.classList.toggle('is-error', isError);
  toast.textContent = message;
  toast.classList.add('is-visible');
  window.setTimeout(() => toast.classList.remove('is-visible'), 3800);
}

function setServiceStatuses(statuses) {
  let online = 0;
  for (const [id] of services) {
    const row = document.querySelector(`[data-service="${id}"]`);
    const isOnline = statuses[id] === true;
    online += Number(isOnline);
    row.querySelector('.led').className = `led ${isOnline ? 'led-online' : 'led-offline'}`;
    row.querySelector('.service-state').textContent = isOnline ? 'ONLINE' : 'OFFLINE';
    row.classList.toggle('is-online', isOnline);
  }
  const overall = document.querySelector('#overall-status');
  overall.textContent = online === services.length ? 'ALL SERVICES ONLINE' : `${online} / ${services.length} ONLINE`;
  overall.classList.toggle('is-online', online === services.length);
}

async function refreshStatus() {
  try {
    setServiceStatuses(await GetStatus());
    const metrics = await GetMetrics();
    document.querySelector('#uptime').textContent = metrics.uptime || await GetUptime();
    document.querySelector('#cpu').textContent = `${Number(metrics.cpuPercent).toFixed(1)}%`;
    document.querySelector('#memory').textContent = metrics.memoryTotalMB
      ? `${metrics.memoryUsedMB.toLocaleString()} / ${metrics.memoryTotalMB.toLocaleString()} MB`
      : `${metrics.memoryUsedMB.toLocaleString()} MB`;
    document.querySelector('#connections').textContent = metrics.activeConnections.toLocaleString();
  } catch (error) {
    showMessage(`Status refresh failed: ${error}`, true);
  }
}

async function refreshAddress() {
  try {
    const ip = await GetLANIP();
    const address = ip ? `http://${ip}:8000` : 'No LAN IPv4 address detected';
    addressElement.textContent = address;
    if (ip) {
      await QRCode.toCanvas(document.querySelector('#qr-code'), address, {
        width: 116,
        margin: 1,
        color: { dark: '#102031', light: '#ffffff' },
      });
    }
  } catch (error) {
    addressElement.textContent = 'Unable to detect LAN address';
    showMessage(`Address detection failed: ${error}`, true);
  }
}

async function refreshLogs() {
  const output = document.querySelector('#log-output');
  try {
    output.textContent = await GetLogs();
    output.scrollTop = output.scrollHeight;
  } catch (error) {
    output.textContent = `Unable to read Laravel log: ${error}`;
  }
}

async function loadTables() {
  const select = document.querySelector('#table-select');
  const results = document.querySelector('#table-results');
  try {
    const tables = await ListTables();
    select.replaceChildren(new Option('Select a table', ''));
    for (const table of tables) select.add(new Option(table, table));
    if (tables.length === 0) results.innerHTML = '<p class="placeholder">No tables found in the configured database.</p>';
  } catch (error) {
    results.textContent = `Database tables unavailable: ${error}`;
  }
}

async function showTableRows() {
  const table = document.querySelector('#table-select').value;
  const results = document.querySelector('#table-results');
  if (!table) {
    results.innerHTML = '<p class="placeholder">Select a table first.</p>';
    return;
  }
  try {
    const result = await GetTableRows(table);
    const renderedTable = document.createElement('table');
    const header = renderedTable.createTHead().insertRow();
    for (const column of result.columns) {
      const cell = document.createElement('th');
      cell.textContent = column;
      header.append(cell);
    }
    const body = renderedTable.createTBody();
    for (const row of result.rows) {
      const tr = body.insertRow();
      for (const value of row) {
        const cell = tr.insertCell();
        cell.textContent = value === null ? 'NULL' : String(value);
      }
    }
    results.replaceChildren(renderedTable);
  } catch (error) {
    results.textContent = `Unable to load table: ${error}`;
  }
}

async function runAction(action, successMessage) {
  actionMessage.textContent = 'Working…';
  actionMessage.classList.remove('is-error');
  try {
    const message = await action();
    const isError = String(message).startsWith('ERROR:');
    showMessage(message || successMessage, isError);
    await refreshStatus();
    if (!isError && successMessage.includes('started')) await loadTables();
    return !isError;
  } catch (error) {
    showMessage(`${successMessage}: ${error}`, true);
    return false;
  }
}

document.querySelector('#start-all').addEventListener('click', () => {
  runAction(StartStack, 'Services started.');
});
document.querySelector('#stop-all').addEventListener('click', () => {
  runAction(StopStack, 'Services stopped.');
});
document.querySelector('#restart-all').addEventListener('click', async () => {
  if (!await runAction(StopStack, 'Services stopped.')) return;
  await runAction(StartStack, 'Services started.');
});
document.querySelectorAll('[data-restart]').forEach((button) => {
  button.addEventListener('click', () => {
    const service = button.dataset.restart;
    runAction(() => RestartService(service), `${service} restarted.`);
  });
});
document.querySelector('#copy-address').addEventListener('click', async () => {
  const address = addressElement.textContent;
  if (!address.startsWith('http')) return showMessage('No server address is available.', true);
  try {
    await navigator.clipboard.writeText(address);
    showMessage('Server address copied.');
  } catch (error) {
    showMessage(`Could not copy server address: ${error}`, true);
  }
});
document.querySelector('#refresh-logs').addEventListener('click', refreshLogs);
document.querySelector('#clear-logs').addEventListener('click', async () => {
  if (!window.confirm('Clear the Laravel application log?')) return;
  const result = await ClearLogs();
  showMessage(result, result.startsWith('ERROR:'));
  await refreshLogs();
});
document.querySelector('#backup-database').addEventListener('click', () => {
  runAction(BackupDatabase, 'Database backup completed.');
});
document.querySelector('#load-table').addEventListener('click', showTableRows);
document.querySelector('#table-select').addEventListener('change', showTableRows);

function updateClock() {
  document.querySelector('#clock').textContent = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(new Date());
}

updateClock();
refreshAddress();
refreshStatus();
refreshLogs();
loadTables();
window.setInterval(updateClock, 1000);
window.setInterval(refreshStatus, 2000);
window.setInterval(refreshLogs, 5000);
