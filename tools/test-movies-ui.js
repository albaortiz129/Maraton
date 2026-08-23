const { chromium } = require('playwright');

const baseUrl = process.argv[2] || 'http://127.0.0.1:4202';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  const errors = [];
  const requests = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://api.themoviedb.org/3/**', async route => {
    const path = new URL(route.request().url()).pathname;
    requests.push(path);
    let body = {};
    if (path.endsWith('/configuration')) body = { images: {} };
    else if (path.endsWith('/search/multi')) body = { results: [
      { id: 11, media_type: 'movie', title: 'Película de prueba', release_date: '2024-01-02', overview: 'Una película.', vote_average: 8, poster_path: null, backdrop_path: null },
      { id: 22, media_type: 'tv', name: 'Serie de prueba', first_air_date: '2023-01-02', overview: 'Una serie.', vote_average: 7, poster_path: null, backdrop_path: null }
    ] };
    else if (path.endsWith('/movie/11/watch/providers')) body = { results: { ES: { flatrate: [{ provider_id: 8, provider_name: 'Netflix', logo_path: null }], link: 'https://www.themoviedb.org/movie/11/watch?locale=ES' } } };
    else if (path.endsWith('/movie/11')) body = { id: 11, title: 'Película de prueba', original_title: 'Test Movie', release_date: '2024-01-02', overview: 'Una película.', vote_average: 8, runtime: 120, status: 'Released', genres: [{ name: 'Drama' }], production_countries: [{ iso_3166_1: 'ES' }], production_companies: [{ name: 'Estudio' }], poster_path: null, backdrop_path: null };
    else if (path.includes('/trending/')) body = { results: [] };
    else if (path.includes('/recommendations')) body = { results: [] };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });

  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.locator('[name="name"]').fill('Prueba');
  await page.locator('[name="username"]').fill('prueba_movie');
  await page.locator('[name="email"]').fill('prueba@example.com');
  await page.locator('[name="password"]').fill('ClaveSegura123');
  await page.locator('[name="confirmPassword"]').fill('ClaveSegura123');
  await page.locator('#authSubmit').click();
  await page.locator('#authScreen').waitFor({ state: 'hidden' });

  await page.locator('[data-view="profile"]').first().click();
  await page.locator('#tmdbToken').fill('token-de-prueba');
  await page.locator('[data-action="save-token"]').click();
  await page.waitForTimeout(300);
  await page.locator('#searchInput').fill('Película');
  const card = page.locator('.poster-card', { hasText: 'Película de prueba' });
  try { await card.waitFor({ timeout: 10000 }); } catch (error) {
    console.error(`Solicitudes TMDB: ${requests.join(', ')}`);
    console.error(`Pantalla: ${(await page.locator('body').innerText()).slice(0, 2000)}`);
    throw error;
  }

  for (let attempt = 0; attempt < 3; attempt++) {
    await card.click();
    await page.locator('[data-action="start-watching"]', { hasText: 'Añadir película' }).waitFor();
    await page.locator('[data-action="close"]').click();
  }
  let data = await page.evaluate(async () => (await fetch('./api.php?action=data')).json());
  if (Object.prototype.hasOwnProperty.call(data.state.watching, '20000011') || data.state.watchlist.includes(20000011)) throw new Error('Abrir la película la añadió sin permiso');

  await card.click();
  await page.locator('[data-action="start-watching"]').click();
  await page.locator('[data-action="movie-watched"]').waitFor();
  data = await page.evaluate(async () => (await fetch('./api.php?action=data')).json());
  if (data.state.watching['20000011'] !== 0 || !data.state.watchlist.includes(20000011)) throw new Error('La película no se guardó en Ver más tarde');

  await page.locator('[data-action="movie-watched"]').click();
  await page.locator('[data-action="movie-watched"]', { hasText: 'Marcar como no vista' }).waitFor();
  data = await page.evaluate(async () => (await fetch('./api.php?action=data')).json());
  if (data.state.showStatus['20000011'] !== 'completed' || data.state.watching['20000011'] !== 1) throw new Error('La película no se marcó como finalizada');
  await page.locator('[data-action="close"]').click();
  await page.locator('[data-view="profile"]').first().click();
  const movieGroup = page.locator('[data-profile-media="movie"]');
  const seriesGroup = page.locator('[data-profile-media="tv"]');
  await movieGroup.getByText('Mis películas', { exact: true }).waitFor();
  if (await movieGroup.locator('.poster-card', { hasText: 'Película de prueba' }).count() !== 1) throw new Error('La película no apareció en su apartado');
  if (await seriesGroup.locator('.poster-card', { hasText: 'Película de prueba' }).count() !== 0) throw new Error('La película sigue mezclada con las series');
  if (errors.length) throw new Error(`Errores de pagina: ${errors.join(' | ')}`);
  await browser.close();
  console.log('movies_ui_tests=ok');
})().catch(async error => {
  console.error(error.stack || error.message);
  process.exit(1);
});
