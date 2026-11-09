import './style.css';
import logo from './assets/vault-client-primary-logo.png';
import icon from './assets/VaultClient.png';
import * as appBindings from '../wailsjs/go/main/App';
import {
  WindowGetPosition,
  WindowGetSize,
  WindowFullscreen,
  WindowUnfullscreen,
} from '../wailsjs/runtime/runtime';

const app = document.querySelector('#app');
let settings = { lastServer: '', rememberServer: true, autoConnect: false };
let busy = false;
let proxyOrigin = '';
let vaultFrameReady = false;
let fullscreenState = true;

app.innerHTML = `
  <main class="connect-screen">
    <section class="connect-card" aria-labelledby="brand-title">
      <img class="brand-emblem" src="${logo}" alt="Vault emblem" />
      <p class="eyebrow">VAULT CLIENT</p>
      <h1 id="brand-title">Connect to Server</h1>
      <p class="intro">Enter the server IP address provided by your administrator.</p>
      <form id="connect-form" novalidate>
        <label for="server-ip">Server IP Address</label>
        <input id="server-ip" name="server-ip" type="text" inputmode="decimal"
          autocomplete="off" spellcheck="false" placeholder="e.g. 192.168.1.40" />
        <label class="check-row" for="remember-server">
          <input id="remember-server" type="checkbox" checked />
          <span>Remember this server</span>
        </label>
        <button id="connect-button" class="primary-button" type="submit">Connect</button>
      </form>
      <div id="connect-feedback" class="feedback" aria-live="polite"></div>
      <div id="error-actions" class="error-actions" hidden>
        <button id="retry-button" class="secondary-button" type="button">Retry</button>
        <button id="change-ip-button" class="text-button" type="button">Change IP</button>
      </div>
      <details id="technical-details" class="technical" hidden>
        <summary>Technical details</summary>
        <pre id="technical-output"></pre>
      </details>
      <footer>VaultClient • Private LAN only • Your IP is stored on this device only</footer>
    </section>
  </main>
`;

const form = document.querySelector('#connect-form');
const input = document.querySelector('#server-ip');
const remember = document.querySelector('#remember-server');
const feedback = document.querySelector('#connect-feedback');
const connectButton = document.querySelector('#connect-button');
const errorActions = document.querySelector('#error-actions');
const technical = document.querySelector('#technical-details');

function normalizeInput(raw) {
  let value = raw.trim();
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
    try {
      const parsed = new URL(value);
      if (!['http:', 'https:'].includes(parsed.protocol) ||
        (parsed.port && parsed.port !== '8000') ||
        (parsed.pathname !== '/' && parsed.pathname !== '') ||
        parsed.search || parsed.hash || parsed.username || parsed.password) return null;
      value = parsed.hostname;
    } catch {
      return null;
    }
  } else {
    value = value.replace(/\/+$/, '').replace(/:8000$/, '');
  }
  const octets = value.split('.');
  if (octets.length !== 4 || octets.some((part) => !/^\d{1,3}$/.test(part) || Number(part) > 255)) return null;
  return octets.map(Number).join('.');
}

function setFeedback(message = '', isError = false, details = '') {
  feedback.textContent = message;
  feedback.classList.toggle('is-error', isError);
  errorActions.hidden = !isError;
  technical.hidden = !details;
  document.querySelector('#technical-output').textContent = details;
}

function callApp(method, ...args) {
  const fn = appBindings[method];
  if (!fn) return Promise.reject(new Error('Vault Client is not running inside its desktop shell.'));
  return fn(...args);
}

async function connect() {
  if (busy) return;
  const ip = normalizeInput(input.value);
  if (!input.value.trim()) {
    setFeedback('Enter the Server IP provided by your administrator.', true);
    input.focus();
    return;
  }
  if (!ip) {
    setFeedback('Enter a valid IP address, e.g. 192.168.1.40', true);
    input.focus();
    return;
  }
  busy = true;
  connectButton.disabled = true;
  connectButton.textContent = 'Connecting…';
  setFeedback('Checking the server address…');
  try {
    const result = await callApp('Connect', ip, remember.checked);
    if (!result.connected) {
      setFeedback(result.message, true, result.technical);
      return;
    }
    settings.lastServer = remember.checked ? ip : '';
    settings.rememberServer = remember.checked;
    proxyOrigin = await callApp('ProxyURL');
    renderConnected();
  } catch (error) {
    setFeedback('The connection could not be completed. Check the address and try again.', true, String(error));
  } finally {
    busy = false;
    connectButton.disabled = false;
    connectButton.textContent = 'Connect';
  }
}

function renderConnected() {
  app.innerHTML = `
    <main class="connected-screen">
      <header class="client-bar">
        <img src="${icon}" alt="" />
        <div><strong>VAULT CLIENT</strong><small>Connected to Vault server</small></div>
        <label class="auto-connect-control" for="auto-connect">
          <input id="auto-connect" type="checkbox" ${settings.rememberServer ? '' : 'disabled'} ${settings.autoConnect ? 'checked' : ''} />
          <span>Connect automatically next time</span>
        </label>
        <span id="client-notice" role="status"></span>
        <button id="disconnect-button" class="secondary-button" type="button">Change Server</button>
      </header>
      <iframe id="vault-frame" title="Vault" src="${proxyOrigin}/_vault_proxy/" allow="clipboard-read; clipboard-write"
        sandbox="allow-forms allow-scripts allow-same-origin allow-modals allow-downloads"></iframe>
    </main>
  `;
  document.querySelector('#disconnect-button').addEventListener('click', disconnect);
  document.querySelector('#auto-connect').addEventListener('change', async (event) => {
    try {
      await callApp('SetAutoConnect', event.target.checked);
      settings.autoConnect = event.target.checked;
    } catch (error) {
      event.target.checked = false;
      const notice = document.querySelector('#client-notice');
      if (notice) notice.textContent = 'Automatic connection could not be saved.';
    }
  });
  const frame = document.querySelector('#vault-frame');
  frame.addEventListener('load', () => {
    vaultFrameReady = true;
    document.title = 'Vault Client';
  });
  window.addEventListener('message', (event) => {
    if (event.origin !== proxyOrigin || event.source !== frame.contentWindow) return;
    if (event.data === 'vault-client-disconnect') disconnect();
    if (event.data === 'vault-client-session-cleared') window.dispatchEvent(new Event('vault-client-session-cleared'));
    if (event.data === 'vault-client-toggle-fullscreen') toggleFullscreen();
  });
}

async function disconnect() {
  try {
    await callApp('Disconnect');
    const frame = document.querySelector('#vault-frame');
    if (frame && vaultFrameReady) {
      await new Promise((resolve) => {
        const timer = window.setTimeout(resolve, 1200);
        const onCleared = () => {
          window.clearTimeout(timer);
          window.removeEventListener('vault-client-session-cleared', onCleared);
          resolve();
        };
        window.addEventListener('vault-client-session-cleared', onCleared, { once: true });
        frame.contentWindow.postMessage('vault-client-clear-session', proxyOrigin);
      });
    }
    const response = await fetch(`${proxyOrigin}/_client/clear`, { credentials: 'include' });
    if (!response.ok) throw new Error('The saved web session could not be cleared.');
    location.reload();
  } catch (error) {
    const notice = document.querySelector('#client-notice');
    if (notice) notice.textContent = 'Could not finish changing servers. Try again or restart Vault Client.';
  }
}

async function loadSettings() {
  try {
    settings = await callApp('InitialState');
    fullscreenState = settings.window?.fullscreen !== false;
    if (settings.error) setFeedback(settings.error, true);
    input.value = settings.lastServer || '';
    remember.checked = settings.rememberServer !== false;
    if (settings.autoConnect && settings.lastServer) await connect();
  } catch (error) {
    setFeedback('Client settings are unavailable. You can still enter the server IP.', true, String(error));
  }
}

form.addEventListener('submit', (event) => {
  event.preventDefault();
  connect();
});
document.querySelector('#retry-button').addEventListener('click', connect);
document.querySelector('#change-ip-button').addEventListener('click', () => {
  input.value = '';
  input.focus();
  setFeedback();
});
loadSettings();

window.addEventListener('keydown', (event) => {
  if (event.key === 'F11') {
    event.preventDefault();
    toggleFullscreen();
  }
});

function toggleFullscreen() {
  fullscreenState = !fullscreenState;
  if (fullscreenState) WindowFullscreen();
  else WindowUnfullscreen();
}

window.addEventListener('resize', saveWindowState);
window.addEventListener('blur', saveWindowState);
let lastWindowState = '';
async function saveWindowState() {
  try {
    const size = await WindowGetSize();
    const position = await WindowGetPosition();
    const current = JSON.stringify({ width: size.w, height: size.h, x: position.x, y: position.y, fullscreen: fullscreenState });
    if (current === lastWindowState) return;
    lastWindowState = current;
    await callApp('SaveWindowState', size.w, size.h, position.x, position.y, fullscreenState);
  } catch {
    // Window persistence is best-effort while the window is closing.
  }
}
window.setInterval(saveWindowState, 1500);
