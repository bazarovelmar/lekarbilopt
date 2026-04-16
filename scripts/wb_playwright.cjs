const { chromium } = require('playwright');

function getArg(name) {
  const idx = process.argv.indexOf(name);
  if (idx === -1) return null;
  return process.argv[idx + 1] || null;
}

function parseProxy(proxy) {
  if (!proxy) return null;
  const match = proxy.match(/^([a-z0-9+.-]+):\/\/(.+)$/i);
  if (!match) return null;
  const protocol = match[1].toLowerCase();
  const rest = match[2];
  const atIdx = rest.lastIndexOf('@');
  let auth = null;
  let hostPart = rest;
  if (atIdx !== -1) {
    auth = rest.slice(0, atIdx);
    hostPart = rest.slice(atIdx + 1);
  }
  let username = '';
  let password = '';
  if (auth) {
    const [u, p] = auth.split(':');
    username = decodeURIComponent(u || '');
    password = decodeURIComponent(p || '');
  }
  const serverProto = protocol === 'socks5h' ? 'socks5' : protocol;
  return {
    server: `${serverProto}://${hostPart}`,
    username: username || undefined,
    password: password || undefined,
  };
}

async function run() {
  const url = getArg('--url');
  const userAgent = getArg('--userAgent') || 'Mozilla/5.0';
  const timeoutMs = parseInt(getArg('--timeout') || '30000', 10);
  const headersRaw = getArg('--headers') || '{}';
  const proxyRaw = getArg('--proxy');

  if (!url) {
    console.log(JSON.stringify({ ok: false, error: 'missing url' }));
    return;
  }

  let headers = {};
  try {
    headers = JSON.parse(headersRaw);
  } catch {
    headers = {};
  }

  let browser;
  try {
    const proxy = parseProxy(proxyRaw);
    browser = await chromium.launch({
      headless: true,
      proxy: proxy || undefined,
    });

    const context = await browser.newContext({
      userAgent,
      locale: 'ru-RU',
      extraHTTPHeaders: headers,
      viewport: { width: 1280, height: 800 },
    });

    const page = await context.newPage();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    const status = response ? response.status() : null;
    const bodyText = await page.evaluate(() => document.body.innerText || '');

    if (!status || status >= 400) {
      console.log(JSON.stringify({
        ok: false,
        status,
        error: `bad status ${status}`,
        body_snippet: bodyText.slice(0, 200),
      }));
      return;
    }

    try {
      const payload = JSON.parse(bodyText);
      console.log(JSON.stringify({ ok: true, status, payload }));
      return;
    } catch (e) {
      console.log(JSON.stringify({
        ok: false,
        status,
        error: 'json parse failed',
        body_snippet: bodyText.slice(0, 200),
      }));
      return;
    }
  } catch (e) {
    console.log(JSON.stringify({ ok: false, error: e.message || String(e) }));
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

run();
