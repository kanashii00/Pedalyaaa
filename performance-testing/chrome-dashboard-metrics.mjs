import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { mkdir, writeFile } from 'node:fs/promises';

const chrome = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const baseUrl = process.env.BASE_URL || 'http://127.0.0.1:8000';
const email = process.env.ADMIN_EMAIL;
const password = process.env.ADMIN_PASSWORD;
const output = process.env.OUTPUT_FILE || 'performance-testing/evidence/pt001-chrome-metrics.json';

if (!email || !password) throw new Error('Set ADMIN_EMAIL and ADMIN_PASSWORD through the environment.');

const port = 9225;
const profile = `${process.cwd()}/performance-testing/.chrome-profile`;
const child = spawn(chrome, [
  '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', 'about:blank',
], { stdio: 'ignore' });

async function target() {
  for (let i = 0; i < 40; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
      return list.find((item) => item.type === 'page');
    } catch { await delay(250); }
  }
  throw new Error('Chrome DevTools endpoint did not start.');
}

const page = await target();
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
let sequence = 0;
const pending = new Map();
ws.onmessage = ({ data }) => {
  const message = JSON.parse(data);
  if (message.id && pending.has(message.id)) {
    pending.get(message.id)(message);
    pending.delete(message.id);
  }
};
const cdp = (method, params = {}) => new Promise((resolve) => {
  const id = ++sequence;
  pending.set(id, resolve);
  ws.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
  const result = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  return result.result.result.value;
};
const waitForUrl = async (fragment) => {
  for (let i = 0; i < 120; i++) {
    const url = await evaluate('location.href');
    if (url.includes(fragment)) return url;
    await delay(250);
  }
  throw new Error(`Timed out waiting for ${fragment}.`);
};

try {
  await cdp('Page.enable');
  await cdp('Runtime.enable');
  await cdp('Page.addScriptToEvaluateOnNewDocument', {
    source: `window.__pedalyaLcp=0; new PerformanceObserver((list)=>{for(const e of list.getEntries()) window.__pedalyaLcp=e.startTime}).observe({type:'largest-contentful-paint', buffered:true});`,
  });
  await cdp('Page.navigate', { url: `${baseUrl}/login` });
  await delay(1200);
  await evaluate(`document.querySelector('[name=email]').value=${JSON.stringify(email)}; document.querySelector('[name=password]').value=${JSON.stringify(password)}; document.querySelector('form').requestSubmit();`);
  await waitForUrl('/admin/dashboard');
  await delay(2500);
  const metrics = await evaluate(`JSON.stringify({url:location.href,lcp:window.__pedalyaLcp,navigation:(()=>{const n=performance.getEntriesByType('navigation')[0];return n&&{duration:n.duration,domContentLoaded:n.domContentLoadedEventEnd,loadEventEnd:n.loadEventEnd,responseStart:n.responseStart}})()})`);
  await mkdir('performance-testing/evidence', { recursive: true });
  await writeFile(output, `${metrics}\n`, 'utf8');
  console.log(metrics);
} finally {
  ws.close();
  child.kill();
}
