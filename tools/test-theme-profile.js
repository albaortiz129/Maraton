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
    assert.equal(await page.locator('text=Días con actividad').count(),0);
    assert.equal(await page.locator('text=Valoración media').count(),0);
    assert.equal(await page.locator('text=Equivalencia aproximada:').count(),0);
    assert.equal(await page.locator('#changePasswordForm').count(),0);
    await page.locator('.profile-head [data-action="toggle-profile-edit"]').click();
    await page.locator('#profileForm').waitFor({state:'visible'});
    assert.equal(await page.locator('.profile-content-group').count(),0);
    assert.equal(await page.locator('#changePasswordForm').count(),1);
    await page.locator('[data-view="profile"]').filter({hasText:'Volver al perfil'}).click();
    assert.equal(await page.locator('.search kbd').count(),0);
    assert.equal(await page.locator('.search svg').count(),1);
    await page.route('**/api.php?action=image*',route=>route.fulfill({status:502,json:{error:'Image unavailable'}}));
    await page.route('https://image.tmdb.org/t/p/w500/test.jpg',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>'}));
    await page.evaluate(()=>{
      app.insertAdjacentHTML('beforeend',`<img id="poster-test" src="${safeImage('https://image.tmdb.org/t/p/w500/test.jpg')}">`);
    });
    try{await page.waitForFunction(()=>{const image=document.querySelector('#poster-test');return image?.src==='https://image.tmdb.org/t/p/w500/test.jpg'&&image.naturalWidth>0},{},{timeout:5000})}catch(error){console.error(await page.locator('#poster-test').evaluate(image=>({html:image.outerHTML,direct:directPosterUrl(image.src)})));throw error}
    assert.equal(await page.evaluate(()=>directPosterUrl('https://evil.example/t/p/w500/test.jpg')),'');
    await page.evaluate(async()=>{
      SHOWS.push({id:20000088,contentType:'movie',title:'Película de prueba',episodes:1,poster:'icon.svg',backdrop:'',desc:'Prueba',rating:7});
      state.watching[20000088]=1;state.watchedEpisodes[20000088]=['1-1'];
      await openShow(20000088);
    });
    await page.locator('#personalRating').selectOption('8');
    await page.locator('[data-rating-status]').filter({hasText:'Tu valoración está guardada'}).waitFor();
    const rated=await (await first.request.get('http://127.0.0.1:4297/api.php?action=data')).json();
    assert.equal(rated.state.ratings[20000088],8);
    await page.evaluate(()=>dialog.close());
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
    assert.equal(await page.locator('.bottom-nav button').count(),4);
    assert.equal(await page.locator('.bottom-nav [data-view="library"]').count(),0);
    await page.evaluate(()=>{
      SHOWS=Array.from({length:8},(_,i)=>({id:100+i,contentType:'tv',title:'Serie '+i,episodes:2,seasonMeta:[{number:1,count:2}],poster:'icon.svg'}));
      state.watchlist=[];state.watching={};state.watchedEpisodes={};state.showStatus={};
      for(const show of SHOWS){state.watching[show.id]=1;state.watchedEpisodes[show.id]=['1-1']}
      render('profile');
    });
    const expand=page.locator('[data-library-open="watching"][data-library-media="tv"]');
    assert.equal(await page.locator('[data-profile-media="tv"] .poster-card').count(),6);
    await expand.click();
    assert.equal(await page.locator('[data-profile-media="tv"] .poster-card').count(),8);
    assert.equal(await page.evaluate(()=>currentView),'profile');
    await expand.click();
    assert.equal(await page.locator('[data-profile-media="tv"] .poster-card').count(),6);
    assert.equal(await page.getByText('Las estadísticas se calculan con tu actividad guardada.').count(),0);
    console.log('theme_profile_tests=ok: persistence, account isolation, validation, CSRF, failure recovery, profile editor, personal rating, home list removal');
  } finally {
    if(browser)await browser.close();
    server.kill();
    await new Promise(resolve=>server.once('exit',resolve));
    fs.rmSync(directory,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1});
