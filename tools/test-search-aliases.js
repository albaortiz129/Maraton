const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('app.js', 'utf8');
const start = source.indexOf('const cleanSearchText');
const end = source.indexOf('async function searchTmdb');

if (start < 0 || end < 0 || end <= start) {
  throw new Error('No se encontraron las funciones de búsqueda.');
}

const context = {};
vm.runInNewContext(`${source.slice(start, end)};result=fallbackSearchQueries('Cuatro manos, dos sonatas')`, context);

if (!Array.from(context.result).includes('Four Hands, Two Sonatas')) {
  throw new Error('La búsqueda española no incluye el título internacional.');
}

const searchEnd = source.indexOf('function manualSearchAction');
const calls = [];
const searchContext = {
  tmdb: async path => {
    calls.push(path);
    if (path.includes('Four%20Hands%2C%20Two%20Sonatas')) {
      return { results: [{ id: 99, media_type: 'tv', name: 'Cuatro manos, dos sonatas' }] };
    }
    return { results: Array.from({ length: 10 }, (_, index) => ({ id: index + 1, media_type: 'movie' })) };
  },
  fromTmdb: item => item,
  enrichMissingPosters: async items => items,
  storeShows: () => {}
};

vm.runInNewContext(source.slice(start, searchEnd), searchContext);

(async () => {
  const results = await searchContext.searchTmdb('Cuatro manos, dos sonatas');
  if (!calls.some(path => path.includes('Four%20Hands%2C%20Two%20Sonatas'))) {
    throw new Error('El alias internacional no se consultó.');
  }
  if (results[0]?.id !== 99) {
    throw new Error('El resultado del alias internacional no tiene prioridad.');
  }
  console.log('search_alias_tests=ok');
})().catch(error => {
  console.error(error.message);
  process.exitCode = 1;
});
