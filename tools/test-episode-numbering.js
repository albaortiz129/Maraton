const fs = require('fs');

const source = fs.readFileSync('app.js', 'utf8');
const names = ['hasContinuousNumbering', 'episodeNumbersForSeason', 'previousEpisodeKeys', 'knownEpisodeCount', 'isFinishedSeries', 'watchedCountForShow', 'showCategory', 'reconcileShowStatus'];
const definitions = names.map(name => {
  const match = source.match(new RegExp(`^(?:function ${name}|const ${name}=).*$`, 'm'));
  if (!match) throw new Error(`No se encontro ${name}`);
  return match[0];
}).join('\n');

const test = `
const SHOWS = [{id: 1, seasonMeta: [
  {number: 1, count: 32}, {number: 2, count: 21}, {number: 3, count: 18}
]}];
const dialog = {querySelector: () => null};
const state = {showStatus: {}, watchedEpisodes: {}, watching: {}, watchlist: []};
${definitions}
const continuous = previousEpisodeKeys(1, 3, 60, ['3-60']);
if (continuous.length !== 59 || !continuous.includes('2-33') || continuous.includes('2-1')) {
  throw new Error('Fallo en la numeracion continua');
}
SHOWS[0] = {id: 2, seasonMeta: [
  {number: 1, count: 2}, {number: 2, count: 2}, {number: 3, count: 5}
]};
const standard = previousEpisodeKeys(2, 3, 4, []);
if (standard.length !== 7 || !standard.includes('2-1') || standard.includes('2-3')) {
  throw new Error('Fallo en la numeracion normal');
}
const ended = {id: 3, status: 'Ended', seasonMeta: [{number: 1, count: 2}]};
state.watchedEpisodes['3'] = ['1-1', '1-2'];
state.showStatus['3'] = 'watching';
if (!reconcileShowStatus(ended) || state.showStatus['3'] !== 'completed') {
  throw new Error('No se finalizo automaticamente');
}
const renewed = {id: 3, status: 'Returning Series', seasonMeta: [{number: 1, count: 2}, {number: 2, count: 1}]};
if (!reconcileShowStatus(renewed, {catalogGrew: true}) || state.showStatus['3'] !== 'watching') {
  throw new Error('No volvio automaticamente a viendo');
}
const current = {id: 4, status: 'Returning Series', seasonMeta: [{number: 1, count: 2}]};
state.watchedEpisodes['4'] = ['1-1', '1-2'];
state.showStatus['4'] = 'watching';
if (!reconcileShowStatus(current) || state.showStatus['4'] !== 'completed') {
  throw new Error('No se finalizo una serie al dia');
}
const unseen = {id: 5, status: 'Returning Series', seasonMeta: [{number: 1, count: 2}]};
state.watching['5'] = 0;
state.showStatus['5'] = 'watching';
if (!reconcileShowStatus(unseen) || showCategory(unseen) !== 'watchlist' || !state.watchlist.includes(5)) {
  throw new Error('No se clasifico una serie sin empezar');
}
state.watchedEpisodes['5'] = ['1-1'];
state.watching['5'] = 1;
if (!reconcileShowStatus(unseen) || showCategory(unseen) !== 'watching' || state.watchlist.includes(5)) {
  throw new Error('No se movio automaticamente a viendo');
}
state.showStatus['5'] = 'dropped';
if (reconcileShowStatus(unseen) || showCategory(unseen) !== 'dropped' || state.watchedEpisodes['5'].length !== 1) {
  throw new Error('No se conservo una serie dejada con su progreso');
}
state.showStatus['5'] = 'watching';
if (reconcileShowStatus(unseen) || showCategory(unseen) !== 'watching' || state.watchedEpisodes['5'].length !== 1) {
  throw new Error('No se pudo retomar la serie');
}
`;

eval(test);
console.log('episode_numbering_tests=ok');
