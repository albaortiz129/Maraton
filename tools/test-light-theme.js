const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

// Isolated UI fixture: no NAS, account, network API or production database.
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
  try {
    const page = await browser.newPage({ serviceWorkers: 'block' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/api.php')) return route.fulfill({ json: { authenticated: false, csrfToken: 'fixture' } });
      const name = url.pathname === '/' ? 'index.html' : path.basename(url.pathname);
      const file = path.join(__dirname, '..', name);
      const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json' };
      if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
      return route.fulfill({ contentType: types[path.extname(file)], body: fs.readFileSync(file) });
    });
    await page.goto('http://maraton.test/');
    await page.locator('#authScreen').waitFor({ state: 'visible' });
    async function check(label) {
      const result = await page.evaluate(() => {
        const visible = [...document.querySelectorAll('*')].filter(el => el.getClientRects().length);
        const gradients = visible.filter(el => ['', '::before', '::after'].some(pseudo => getComputedStyle(el, pseudo || null).backgroundImage.includes('gradient'))).map(el => el.className);
        return { gradients, overflow: document.documentElement.scrollWidth > innerWidth, background: getComputedStyle(document.body).backgroundColor };
      });
      assert.deepEqual(result.gradients, [], `${label}: gradients`);
      assert.equal(result.overflow, false, `${label}: overflow`);
      assert.equal(result.background, 'rgb(247, 245, 242)');
    }
    await check('login desktop');
    await page.screenshot({ path: path.join(process.env.TEMP, 'maraton-light-login.png') });
    await page.evaluate(() => {
      authScreen.hidden = true;
      currentUser = { id: 1, name: 'Alba', username: 'alba' };
      SHOWS = [{ id: 100, title: 'Una historia pendiente', genre: 'Drama', year: 2026, episodes: 12, seasons: 1, rating: 8, poster: 'icon.svg', backdrop: 'icon.svg' }];
      state = structuredClone(defaultState);
      state.name = 'Alba';
      state.watching = { 100: 3 };
      render('home');
    });
    await check('home desktop');
    await page.screenshot({ path: path.join(process.env.TEMP, 'maraton-light-home.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await check('home mobile');
    await page.screenshot({ path: path.join(process.env.TEMP, 'maraton-light-mobile.png') });
    await page.evaluate(() => render('profile'));
    await check('profile mobile');
    await page.evaluate(() => {
      dialog.querySelector('#dialogContent').innerHTML = '<div class="dialog-hero"><div class="dialog-title"><p class="eyebrow">Drama</p><h2>Una historia pendiente</h2></div></div><div class="dialog-body"><p class="muted">Descripción de la serie</p><div class="episode">Episodio pendiente<button>○</button></div><div class="episode watched">Episodio visto<button>✓</button></div></div>';
      dialog.showModal();
    });
    await check('dialog mobile');
    assert.deepEqual(errors, []);
    console.log('light_theme_tests=ok (login, home, profile, dialog; desktop/mobile)');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
