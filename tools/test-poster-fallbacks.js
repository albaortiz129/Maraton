const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('app.js', 'utf8');
const start = source.indexOf("const tmdbImage=");
const end = source.indexOf('async function tmdb');

if (start < 0 || end <= start) {
  throw new Error('No se encontraron las funciones de portada.');
}

const context = {};
vm.runInNewContext(`${source.slice(start, end)};result={fromTmdb,posterMissing}`, context);

const fromTmdb = context.result.fromTmdb;
const withPoster = fromTmdb({ id: 1, media_type: 'tv', name: 'Serie', poster_path: '/poster.jpg', backdrop_path: '/fondo.jpg' }, 'tv');
const withBackdrop = fromTmdb({ id: 2, media_type: 'movie', title: 'Película', poster_path: null, backdrop_path: '/fondo.jpg' }, 'movie');
const withoutImages = fromTmdb({ id: 3, media_type: 'tv', name: 'Sin imágenes' }, 'tv');

if (withPoster.poster !== 'https://image.tmdb.org/t/p/w500/poster.jpg') {
  throw new Error('No se usa la portada oficial como primera opción.');
}
if (withBackdrop.poster !== 'https://image.tmdb.org/t/p/w500/fondo.jpg') {
  throw new Error('No se usa el fondo como portada alternativa.');
}
if (withoutImages.poster !== 'icon.svg') {
  throw new Error('La imagen segura de último recurso no está configurada.');
}

console.log('poster_fallback_tests=ok');
