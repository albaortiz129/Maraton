<?php
declare(strict_types=1);

$configuredDir=trim((string)getenv('MARATON_DATA_DIR'));
$dir=$configuredDir!==''?$configuredDir:__DIR__.DIRECTORY_SEPARATOR.'data';
if(!is_dir($dir))mkdir($dir,0775,true);
$resolvedDir=realpath($dir);
if($resolvedDir===false||!is_writable($resolvedDir))throw new RuntimeException('El directorio de datos no está disponible');
$dir=$resolvedDir;
$sessionDir=$dir.DIRECTORY_SEPARATOR.'sessions';
if(!is_dir($sessionDir))mkdir($sessionDir,0700,true);
if(!is_writable($sessionDir))throw new RuntimeException('El directorio de sesiones no está disponible');
session_save_path($sessionDir);

session_name('maraton_session');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||getenv('MARATON_HTTPS')==='1';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>$https,'path'=>'/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$db=new PDO('sqlite:'.$dir.DIRECTORY_SEPARATOR.'maraton.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS user_data(user_id INTEGER PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS login_attempts(client_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, window_started INTEGER NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS user_profiles(user_id INTEGER PRIMARY KEY, display_name TEXT NOT NULL, bio TEXT NOT NULL DEFAULT "", avatar_color TEXT NOT NULL DEFAULT "#ff2d74", updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS saved_shows(user_id INTEGER NOT NULL, show_id INTEGER NOT NULL, tmdb_id INTEGER, payload TEXT NOT NULL, PRIMARY KEY(user_id,show_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS show_tracking(user_id INTEGER NOT NULL, show_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "watching", in_watchlist INTEGER NOT NULL DEFAULT 0, rating INTEGER, progress_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL, PRIMARY KEY(user_id,show_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS watched_episodes(user_id INTEGER NOT NULL, show_id INTEGER NOT NULL, season INTEGER NOT NULL, episode INTEGER NOT NULL, watched_at TEXT NOT NULL, PRIMARY KEY(user_id,show_id,season,episode), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS watch_history(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, show_id INTEGER NOT NULL, title TEXT NOT NULL, episode_label TEXT NOT NULL, watched_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS user_settings(user_id INTEGER PRIMARY KEY, tmdb_secret TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');

function out(array $value,int $code=200):never { http_response_code($code);echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function body():array { if((int)($_SERVER['CONTENT_LENGTH']??0)>2097152)out(['error'=>'La solicitud es demasiado grande'],413);$raw=file_get_contents('php://input')?:'{}';if(strlen($raw)>2097152)out(['error'=>'La solicitud es demasiado grande'],413);$value=json_decode($raw,true);if(!is_array($value))out(['error'=>'JSON no válido'],400);return $value; }
function userId():int { if(empty($_SESSION['user_id']))out(['error'=>'Debes iniciar sesión'],401);return (int)$_SESSION['user_id']; }
function cleanUser(array $user):array { return ['id'=>(int)$user['id'],'name'=>$user['name'],'username'=>$user['username']]; }
function csrfToken():string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf']; }
function requireCsrf():void { $given=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');if($given===''||!hash_equals(csrfToken(),$given))out(['error'=>'Solicitud no autorizada'],403); }
function serveTmdbImage(string $path):never {
  global $dir;
  userId();
  if(!preg_match('~^/t/p/(w500|original)/([a-zA-Z0-9._-]+)$~',$path,$parts))out(['error'=>'Imagen no válida'],422);
  $cacheDir=$dir.DIRECTORY_SEPARATOR.'posters';
  if(!is_dir($cacheDir))mkdir($cacheDir,0700,true);
  $cacheFile=$cacheDir.DIRECTORY_SEPARATOR.hash('sha256',$path).'.image';
  if(!is_file($cacheFile)||filesize($cacheFile)===0){
    $context=stream_context_create(['http'=>['timeout'=>10,'follow_location'=>0,'header'=>"User-Agent: MaratonLocal/1.0\r\nAccept: image/avif,image/webp,image/png,image/jpeg\r\n"]]);
    $bytes=@file_get_contents('https://image.tmdb.org'.$path,false,$context);
    if($bytes===false||strlen($bytes)>10485760)out(['error'=>'No se pudo cargar la imagen'],502);
    $info=@getimagesizefromstring($bytes);$mime=(string)($info['mime']??'');
    if(!in_array($mime,['image/jpeg','image/png','image/webp','image/avif'],true))out(['error'=>'Formato de imagen no válido'],502);
    $temporary=$cacheFile.'.'.bin2hex(random_bytes(4)).'.tmp';
    if(file_put_contents($temporary,$bytes,LOCK_EX)===false)out(['error'=>'No se pudo guardar la imagen'],500);
    if(!@rename($temporary,$cacheFile)){@unlink($temporary);if(!is_file($cacheFile))out(['error'=>'No se pudo guardar la imagen'],500);}
  }
  $info=@getimagesize($cacheFile);$mime=(string)($info['mime']??'application/octet-stream');
  header('Content-Type: '.$mime);
  header('Cache-Control: private, max-age=2592000, immutable');
  header('Content-Length: '.filesize($cacheFile));
  readfile($cacheFile);
  exit;
}
function secretKey():string { global $dir;$environment=trim((string)getenv('MARATON_SECRET_KEY'));if($environment!==''){$key=base64_decode($environment,true);if($key===false||strlen($key)!==32)throw new RuntimeException('MARATON_SECRET_KEY debe ser una clave de 32 bytes en base64');return $key;}$file=$dir.DIRECTORY_SEPARATOR.'.secret-key';if(!is_file($file))file_put_contents($file,base64_encode(random_bytes(32)),LOCK_EX);$key=base64_decode((string)file_get_contents($file),true);if($key===false||strlen($key)!==32)throw new RuntimeException('Clave local no válida');return $key; }
function encryptToken(string $token):string { if($token==='')return '';$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',secretKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('No se pudo cifrar el token');return base64_encode($iv.$tag.$cipher); }
function decryptToken(string $value):string { if($value==='')return '';$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)return '';$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',secretKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));return $plain===false?'':$plain; }
function validateState(array $state):bool { if(!isset($state['watching'],$state['watchlist'])||!is_array($state['watching'])||!is_array($state['watchlist']))return false;if(count($state['watching'])>5000||count($state['watchlist'])>5000)return false;foreach($state['watching'] as $id=>$count){if(!ctype_digit((string)$id)||(!is_numeric($count)&&!is_array($count)))return false;}foreach($state['watchlist'] as $id){if(!is_int($id)&&!ctype_digit((string)$id))return false;}return true; }
function checkLoginLimit(PDO $db,string $key):void { $now=time();$query=$db->prepare('SELECT attempts,window_started FROM login_attempts WHERE client_key=?');$query->execute([$key]);$row=$query->fetch(PDO::FETCH_ASSOC);if($row&&$now-(int)$row['window_started']<900&&(int)$row['attempts']>=5)out(['error'=>'Demasiados intentos. Espera 15 minutos'],429);if($row&&$now-(int)$row['window_started']>=900){$query=$db->prepare('DELETE FROM login_attempts WHERE client_key=?');$query->execute([$key]);} }
function recordLoginFailure(PDO $db,string $key):void { $now=time();$query=$db->prepare('INSERT INTO login_attempts(client_key,attempts,window_started) VALUES(?,1,?) ON CONFLICT(client_key) DO UPDATE SET attempts=CASE WHEN ?-window_started>=900 THEN 1 ELSE attempts+1 END,window_started=CASE WHEN ?-window_started>=900 THEN ? ELSE window_started END');$query->execute([$key,$now,$now,$now,$now]); }
function saveNormalized(PDO $db,int $uid,array $input):string {
  $state=$input['state'];$catalog=array_values($input['catalog']??[]);$now=gmdate('c');$profile=is_array($state['profile']??null)?$state['profile']:[];$name=trim((string)($state['name']??''));if($name===''){$q=$db->prepare('SELECT name FROM users WHERE id=?');$q->execute([$uid]);$name=(string)$q->fetchColumn();}$bio=mb_substr((string)($profile['bio']??''),0,240);$color=preg_match('/^#[0-9a-f]{6}$/i',(string)($profile['avatarColor']??''))?(string)$profile['avatarColor']:'#ff2d74';
  $db->beginTransaction();try{
    $q=$db->prepare('UPDATE users SET name=? WHERE id=?');$q->execute([$name,$uid]);$q=$db->prepare('INSERT INTO user_profiles(user_id,display_name,bio,avatar_color,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET display_name=excluded.display_name,bio=excluded.bio,avatar_color=excluded.avatar_color,updated_at=excluded.updated_at');$q->execute([$uid,$name,$bio,$color,$now]);
    foreach(['saved_shows','show_tracking','watched_episodes','watch_history'] as $table){$q=$db->prepare("DELETE FROM $table WHERE user_id=?");$q->execute([$uid]);}
    $showInsert=$db->prepare('INSERT INTO saved_shows(user_id,show_id,tmdb_id,payload) VALUES(?,?,?,?)');foreach($catalog as $show){if(!is_array($show)||!isset($show['id']))continue;$encoded=json_encode($show,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($encoded!==false)$showInsert->execute([$uid,(int)$show['id'],isset($show['tmdbId'])?(int)$show['tmdbId']:null,$encoded]);}
    $ids=[];foreach(array_keys($state['watching']??[]) as $id)$ids[(int)$id]=true;foreach($state['watchlist']??[] as $id)$ids[(int)$id]=true;foreach(array_keys($state['showStatus']??[]) as $id)$ids[(int)$id]=true;foreach(array_keys($state['ratings']??[]) as $id)$ids[(int)$id]=true;
    $track=$db->prepare('INSERT INTO show_tracking(user_id,show_id,status,in_watchlist,rating,progress_count,updated_at) VALUES(?,?,?,?,?,?,?)');foreach(array_keys($ids) as $id){$status=(string)($state['showStatus'][$id]??'watching');if(!in_array($status,['watching','completed','paused','dropped'],true))$status='watching';$rating=isset($state['ratings'][$id])?(int)$state['ratings'][$id]:null;if($rating!==null&&($rating<1||$rating>10))$rating=null;$progress=is_array($state['watching'][$id]??null)?count($state['watching'][$id]):(int)($state['watching'][$id]??0);$track->execute([$uid,$id,$status,in_array($id,$state['watchlist']??[],true)?1:0,$rating,max(0,$progress),$now]);}
    $episodeInsert=$db->prepare('INSERT INTO watched_episodes(user_id,show_id,season,episode,watched_at) VALUES(?,?,?,?,?)');foreach($state['watchedEpisodes']??[] as $id=>$keys){if(!is_array($keys))continue;foreach($keys as $key){if(preg_match('/^(\d+)-(\d+)$/',(string)$key,$m))$episodeInsert->execute([$uid,(int)$id,(int)$m[1],(int)$m[2],$now]);}}
    $historyInsert=$db->prepare('INSERT INTO watch_history(user_id,show_id,title,episode_label,watched_at) VALUES(?,?,?,?,?)');foreach(array_slice(is_array($state['history']??null)?$state['history']:[],0,200) as $item){if(is_array($item))$historyInsert->execute([$uid,(int)($item['showId']??0),mb_substr((string)($item['title']??''),0,200),mb_substr((string)($item['episode']??''),0,80),(string)($item['at']??$now)]);}
    $token=(string)($input['tmdbToken']??'');$q=$db->prepare('INSERT INTO user_settings(user_id,tmdb_secret,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET tmdb_secret=excluded.tmdb_secret,updated_at=excluded.updated_at');$q->execute([$uid,encryptToken($token),$now]);
    $db->commit();return $now;
  }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function loadNormalized(PDO $db,int $uid):?array {
  $q=$db->prepare('SELECT * FROM user_profiles WHERE user_id=?');$q->execute([$uid]);$profile=$q->fetch(PDO::FETCH_ASSOC);if(!$profile)return null;$state=['watching'=>[],'watchlist'=>[],'watched'=>[],'watchedEpisodes'=>[],'showStatus'=>[],'ratings'=>[],'history'=>[],'profile'=>['bio'=>$profile['bio'],'avatarColor'=>$profile['avatar_color']],'name'=>$profile['display_name']];
  $q=$db->prepare('SELECT * FROM show_tracking WHERE user_id=?');$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(string)$row['show_id'];$state['watching'][$id]=(int)$row['progress_count'];$state['showStatus'][$id]=$row['status'];if((int)$row['in_watchlist'])$state['watchlist'][]=(int)$row['show_id'];if($row['rating']!==null)$state['ratings'][$id]=(int)$row['rating'];}
  $q=$db->prepare('SELECT show_id,season,episode FROM watched_episodes WHERE user_id=? ORDER BY season,episode');$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$state['watchedEpisodes'][(string)$row['show_id']][]=$row['season'].'-'.$row['episode'];
  $q=$db->prepare('SELECT show_id,title,episode_label,watched_at FROM watch_history WHERE user_id=? ORDER BY id DESC LIMIT 200');$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$state['history'][]=['showId'=>(int)$row['show_id'],'title'=>$row['title'],'episode'=>$row['episode_label'],'at'=>$row['watched_at']];
  $catalog=[];$q=$db->prepare('SELECT payload FROM saved_shows WHERE user_id=?');$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $payload){$show=json_decode($payload,true);if(is_array($show))$catalog[]=$show;}$q=$db->prepare('SELECT tmdb_secret,updated_at FROM user_settings WHERE user_id=?');$q->execute([$uid]);$settings=$q->fetch(PDO::FETCH_ASSOC)?:['tmdb_secret'=>'','updated_at'=>$profile['updated_at']];return ['exists'=>true,'state'=>$state,'catalog'=>$catalog,'tmdbToken'=>decryptToken((string)$settings['tmdb_secret']),'savedAt'=>$settings['updated_at']];
}

$action=$_GET['action']??'data';
if($action==='image'&&$_SERVER['REQUEST_METHOD']==='GET')serveTmdbImage((string)($_GET['path']??''));
if($action==='status'){
  $count=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
  if(empty($_SESSION['user_id']))out(['authenticated'=>false,'hasUsers'=>$count>0,'csrfToken'=>csrfToken()]);
  $query=$db->prepare('SELECT id,name,username FROM users WHERE id=?');$query->execute([$_SESSION['user_id']]);$user=$query->fetch(PDO::FETCH_ASSOC);
  if(!$user){$_SESSION=[];session_destroy();out(['authenticated'=>false,'hasUsers'=>$count>0],401);}
  out(['authenticated'=>true,'user'=>cleanUser($user),'csrfToken'=>csrfToken()]);
}
if($action==='register'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$input=body();$name=trim((string)($input['name']??''));$username=trim((string)($input['username']??''));$password=(string)($input['password']??'');
  if(mb_strlen($name)<2||mb_strlen($name)>80||preg_match('/[<>\x00-\x1F\x7F]/u',$name)||!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/',$username)||strlen($password)<8||strlen($password)>1024)out(['error'=>'Revisa el nombre, usuario y contraseña'],422);
  try{$query=$db->prepare('INSERT INTO users(name,username,password,created_at) VALUES(?,?,?,?)');$query->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),gmdate('c')]);}catch(PDOException){out(['error'=>'Ese nombre de usuario ya existe'],409);}
  $_SESSION['user_id']=(int)$db->lastInsertId();$now=gmdate('c');$query=$db->prepare('INSERT INTO user_profiles(user_id,display_name,bio,avatar_color,updated_at) VALUES(?, ?, "", "#ff2d74", ?)');$query->execute([$_SESSION['user_id'],$name,$now]);$query=$db->prepare('INSERT INTO user_settings(user_id,tmdb_secret,updated_at) VALUES(?,"",?)');$query->execute([$_SESSION['user_id'],$now]);session_regenerate_id(true);out(['ok'=>true,'user'=>['id'=>$_SESSION['user_id'],'name'=>$name,'username'=>$username],'csrfToken'=>csrfToken()]);
}
if($action==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$input=body();$username=trim((string)($input['username']??''));$key=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.mb_strtolower($username));checkLoginLimit($db,$key);$query=$db->prepare('SELECT * FROM users WHERE username=?');$query->execute([$username]);$user=$query->fetch(PDO::FETCH_ASSOC);
  if(!$user||!password_verify((string)($input['password']??''),$user['password'])){recordLoginFailure($db,$key);out(['error'=>'Usuario o contraseña incorrectos'],401);}
  $query=$db->prepare('DELETE FROM login_attempts WHERE client_key=?');$query->execute([$key]);$_SESSION['user_id']=(int)$user['id'];session_regenerate_id(true);out(['ok'=>true,'user'=>cleanUser($user),'csrfToken'=>csrfToken()]);
}
if($action==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}session_destroy();out(['ok'=>true]);
}
if($action==='track'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$uid=userId();$input=body();$id=(int)($input['showId']??0);if($id<=0)out(['error'=>'Serie no válida'],422);$status=(string)($input['status']??'watching');if(!in_array($status,['watching','completed','paused','dropped'],true))$status='watching';$rating=isset($input['rating'])?(int)$input['rating']:null;if($rating!==null&&($rating<1||$rating>10))$rating=null;$watchlist=!empty($input['inWatchlist'])?1:0;$show=is_array($input['show']??null)?$input['show']:null;$now=gmdate('c');
  $db->beginTransaction();try{if($show){$encoded=json_encode($show,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($encoded===false)throw new RuntimeException('Serie no válida');$q=$db->prepare('INSERT INTO saved_shows(user_id,show_id,tmdb_id,payload) VALUES(?,?,?,?) ON CONFLICT(user_id,show_id) DO UPDATE SET tmdb_id=excluded.tmdb_id,payload=excluded.payload');$q->execute([$uid,$id,isset($show['tmdbId'])?(int)$show['tmdbId']:null,$encoded]);}$q=$db->prepare('INSERT INTO show_tracking(user_id,show_id,status,in_watchlist,rating,progress_count,updated_at) VALUES(?,?,?,?,?,0,?) ON CONFLICT(user_id,show_id) DO UPDATE SET status=excluded.status,in_watchlist=excluded.in_watchlist,rating=excluded.rating,updated_at=excluded.updated_at');$q->execute([$uid,$id,$status,$watchlist,$rating,$now]);$db->commit();out(['ok'=>true,'savedAt'=>$now]);}catch(Throwable $error){if($db->inTransaction())$db->rollBack();out(['error'=>'No se pudo guardar la serie'],500);}
}
if($action==='episode'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$uid=userId();$input=body();$id=(int)($input['showId']??0);$season=(int)($input['season']??0);$episode=(int)($input['episode']??0);$watched=!empty($input['watched']);if($id<=0||$season<=0||$episode<=0)out(['error'=>'Episodio no válido'],422);$show=is_array($input['show']??null)?$input['show']:null;$now=gmdate('c');
  $db->beginTransaction();try{if($show){$encoded=json_encode($show,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$q=$db->prepare('INSERT INTO saved_shows(user_id,show_id,tmdb_id,payload) VALUES(?,?,?,?) ON CONFLICT(user_id,show_id) DO UPDATE SET tmdb_id=excluded.tmdb_id,payload=excluded.payload');$q->execute([$uid,$id,isset($show['tmdbId'])?(int)$show['tmdbId']:null,$encoded]);}$q=$db->prepare('INSERT INTO show_tracking(user_id,show_id,status,in_watchlist,rating,progress_count,updated_at) VALUES(?,? ,"watching",0,NULL,0,?) ON CONFLICT(user_id,show_id) DO UPDATE SET updated_at=excluded.updated_at');$q->execute([$uid,$id,$now]);if($watched){$q=$db->prepare('INSERT OR IGNORE INTO watched_episodes(user_id,show_id,season,episode,watched_at) VALUES(?,?,?,?,?)');$q->execute([$uid,$id,$season,$episode,$now]);$title=mb_substr((string)($show['title']??''),0,200);$q=$db->prepare('DELETE FROM watch_history WHERE user_id=? AND show_id=? AND episode_label=?');$q->execute([$uid,$id,"T$season · E$episode"]);$q=$db->prepare('INSERT INTO watch_history(user_id,show_id,title,episode_label,watched_at) VALUES(?,?,?,?,?)');$q->execute([$uid,$id,$title,"T$season · E$episode",$now]);}else{$q=$db->prepare('DELETE FROM watched_episodes WHERE user_id=? AND show_id=? AND season=? AND episode=?');$q->execute([$uid,$id,$season,$episode]);$q=$db->prepare('DELETE FROM watch_history WHERE user_id=? AND show_id=? AND episode_label=?');$q->execute([$uid,$id,"T$season · E$episode"]);}$q=$db->prepare('UPDATE show_tracking SET progress_count=(SELECT COUNT(*) FROM watched_episodes WHERE user_id=? AND show_id=?),updated_at=? WHERE user_id=? AND show_id=?');$q->execute([$uid,$id,$now,$uid,$id]);$db->commit();out(['ok'=>true,'savedAt'=>$now]);}catch(Throwable $error){if($db->inTransaction())$db->rollBack();out(['error'=>'No se pudo guardar el episodio'],500);}
}
if($action==='profile'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$uid=userId();$input=body();$name=trim((string)($input['name']??''));$bio=trim((string)($input['bio']??''));$color=(string)($input['avatarColor']??'');if(mb_strlen($name)<2||mb_strlen($name)>80||preg_match('/[<>\x00-\x1F\x7F]/u',$name)||mb_strlen($bio)>240||!preg_match('/^#[0-9a-f]{6}$/i',$color))out(['error'=>'Perfil no válido'],422);$now=gmdate('c');$db->beginTransaction();try{$q=$db->prepare('UPDATE users SET name=? WHERE id=?');$q->execute([$name,$uid]);$q=$db->prepare('INSERT INTO user_profiles(user_id,display_name,bio,avatar_color,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET display_name=excluded.display_name,bio=excluded.bio,avatar_color=excluded.avatar_color,updated_at=excluded.updated_at');$q->execute([$uid,$name,$bio,$color,$now]);$db->commit();out(['ok'=>true,'savedAt'=>$now]);}catch(Throwable){if($db->inTransaction())$db->rollBack();out(['error'=>'No se pudo guardar el perfil'],500);}
}
if($action==='settings'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$uid=userId();$input=body();$token=(string)($input['tmdbToken']??'');if(strlen($token)>4096)out(['error'=>'Token no válido'],422);$now=gmdate('c');$q=$db->prepare('INSERT INTO user_settings(user_id,tmdb_secret,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET tmdb_secret=excluded.tmdb_secret,updated_at=excluded.updated_at');$q->execute([$uid,encryptToken($token),$now]);out(['ok'=>true,'savedAt'=>$now]);
}
if($action==='history_clear'&&$_SERVER['REQUEST_METHOD']==='POST'){
  requireCsrf();$uid=userId();$q=$db->prepare('DELETE FROM watch_history WHERE user_id=?');$q->execute([$uid]);out(['ok'=>true,'deleted'=>$q->rowCount()]);
}
if($action==='data'){
  $uid=userId();
  if($_SERVER['REQUEST_METHOD']==='GET'){
    $normalized=loadNormalized($db,$uid);if($normalized)out($normalized);
    $query=$db->prepare('SELECT payload FROM user_data WHERE user_id=?');$query->execute([$uid]);$legacy=$query->fetchColumn();if($legacy!==false){$payload=json_decode((string)$legacy,true)?:[];$migration=['state'=>is_array($payload['state']??null)?$payload['state']:['watching'=>[],'watchlist'=>[]],'catalog'=>is_array($payload['catalog']??null)?$payload['catalog']:[],'tmdbToken'=>decryptToken((string)($payload['_tmdbSecret']??''))];saveNormalized($db,$uid,$migration);out(loadNormalized($db,$uid)??['exists'=>false]);}out(['exists'=>false]);
  }
  if($_SERVER['REQUEST_METHOD']==='POST'){
    requireCsrf();$input=body();if(!isset($input['state'])||!is_array($input['state'])||!validateState($input['state']))out(['error'=>'Datos no válidos'],422);if(isset($input['catalog'])&&(!is_array($input['catalog'])||count($input['catalog'])>5000))out(['error'=>'Catálogo no válido'],422);$token=(string)($input['tmdbToken']??'');if(strlen($token)>4096)out(['error'=>'Token no válido'],422);
    $encoded=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($encoded===false||strlen($encoded)>2097152)out(['error'=>'Los datos superan el límite permitido'],413);$now=saveNormalized($db,$uid,$input);out(['ok'=>true,'savedAt'=>$now]);
  }
}
out(['error'=>'Ruta no encontrada'],404);
