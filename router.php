<?php
declare(strict_types=1);
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$path=str_replace('\\','/',$path);
if(preg_match('#(?:^|/)\.[^/]*#',$path)||preg_match('#^/data(?:/|$)#i',$path)||preg_match('/\.(?:sqlite|db|key)$/i',$path)){http_response_code(404);exit;}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https://image.tmdb.org data:; connect-src 'self' https://api.themoviedb.org; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$file=__DIR__.$path;
if($path!=='/'&&is_file($file))return false;
require __DIR__.'/index.html';
