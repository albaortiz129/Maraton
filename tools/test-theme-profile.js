const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'maraton-theme-'));
  const server = spawn('C:\\xampp\\php\\php.exe', ['-S', '127.0.0.1:4297', '-t', path.join(__dirname, '..')], {
    env: { ...process.env, MARATON_DATA_DIR: directory, MARATON_HTTPS: '0', MARATON_ALLOW_REGISTRATION: '1' }, stdio: 'ignore'
  });
  let browser;
  try {
    for (let i=0;i<50;i++) {
      try { if ((await fetch('http://127.0.0.1:4297/api.php?action=health')).ok) break; } catch {}
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    browser = await chromium.launch({headless:true, executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
    const first=await browser.newContext({serviceWorkers:'block'}),second=await browser.newContext({serviceWorkers:'block'});
    async function register(context,name) {
      const status=await (await context.request.get('http://127.0.0.1:4297/api.php?action=status')).json();
      const response=await context.request.post('http://127.0.0.1:4297/api.php?action=register',{headers:{'X-CSRF-Token':status.csrfToken},data:{name,username:name,email:name+'@example.com',password:'TestPassword123!'}});
      assert.equal(response.ok(),true,await response.text());
      return (await response.json()).csrfToken;
    }
    const token=await register(first,'ThemeOne');await register(second,'ThemeTwo');
    const page=await first.newPage();
    await page.goto('http://127.0.0.1:4297/');
    await page.locator('#authScreen').waitFor({state:'hidden'});
    await page.locator('.bottom-nav [data-view="profile"]').click();
    await page.locator('#profileTheme').selectOption('dark');
    await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
    await page.reload();
    await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
    await page.locator('.bottom-nav [data-view="profile"]').click();
    assert.equal(await page.locator('#profileTheme').inputValue(),'dark');
    const other=await (await second.request.get('http://127.0.0.1:4297/api.php?action=data')).json();
    assert.equal(other.state.profile.theme,'light');
    assert.equal((await first.request.post('http://127.0.0.1:4297/api.php?action=theme',{headers:{'X-CSRF-Token':token},data:{theme:'invalid'}})).status(),422);
    assert.equal((await first.request.post('http://127.0.0.1:4297/api.php?action=theme',{data:{theme:'light'}})).status(),403);
    await page.setViewportSize({width:390,height:844});
    const gradients=await page.evaluate(()=>[...document.querySelectorAll('*')].filter(el=>el.getClientRects().length&&getComputedStyle(el).backgroundImage.includes('gradient')).length);
    assert.equal(gradients,0);
    await page.screenshot({path:path.join(os.tmpdir(),'maraton-dark-profile.png'),fullPage:true});
    await page.locator('#profileTheme').selectOption('light');
    await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
    await page.route('**/api.php?action=theme',route=>route.fulfill({status:500,json:{error:'Error de prueba'}}));
    await page.locator('#profileTheme').selectOption('dark');
    await page.locator('#themeSaveStatus').filter({hasText:'Error de prueba'}).waitFor();
    assert.equal(await page.locator('#profileTheme').inputValue(),'light');
    assert.equal(await page.evaluate(()=>document.documentElement.dataset.theme),'light');
    await page.evaluate(()=>{SHOWS=[{id:100,title:'Serie',episodes:10,seasons:1,poster:'icon.svg',backdrop:'icon.svg'}];state.watching={100:1};state.watchlist=[100];render('home')});
    assert.equal(await page.locator('h2').filter({hasText:'Tu lista'}).count(),0);
    assert.deepEqual(await page.evaluate(()=>state.watchlist),[100]);
    console.log('theme_profile_tests=ok: persistence, account isolation, validation, CSRF, failure recovery, home list removal');
  } finally {
    if(browser)await browser.close();
    server.kill();
    await new Promise(resolve=>server.once('exit',resolve));
    fs.rmSync(directory,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1});
