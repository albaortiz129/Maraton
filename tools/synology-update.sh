#!/bin/sh
# Run with "check" first. "update" deploys main without replacing NAS files.
set -eu
umask 077
PATH=/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:/var/packages/ContainerManager/target/usr/bin
export PATH
BASE=/volume1/docker/Maraton
STATE=/volume1/docker/maraton-updater
CONTAINER=maraton-maraton-1
REPO=albaortiz129/Maraton
MODE=${1:-check}
case "$MODE" in check|update) ;; *) echo "Use check or update"; exit 1;; esac
mkdir -p "$STATE"
chmod 700 "$STATE"
if ! mkdir "$STATE/lock" 2>/dev/null; then
  echo "Another run is active, or a previous run left a lock. Check before removing it."
  exit 1
fi
trap 'rmdir "$STATE/lock"' EXIT
test -f "$BASE/compose.yaml"
test -f "$BASE/.env.oracle"
test -s "$BASE/data/maraton.sqlite"
docker compose version
HEALTH=$(docker inspect --format '{{.State.Health.Status}}' "$CONTAINER")
test "$HEALTH" = healthy
OLD=$(docker inspect --format '{{.Image}}' "$CONTAINER")
curl -fLsS --connect-timeout 15 --max-time 60 \
  "https://api.github.com/repos/$REPO/commits/main" -o "$STATE/commit.json"
SHA=$(docker exec -i "$CONTAINER" php -r '
$j=json_decode(stream_get_contents(STDIN),true);
$s=$j["sha"]??"";
if(!preg_match("/^[a-f0-9]{40}$/",$s))exit(1);
echo $s;
' < "$STATE/commit.json")
echo "GitHub main: $SHA"
echo "Container: $HEALTH"
echo "Compose: $BASE/compose.yaml"
docker exec "$CONTAINER" php -r '
$p=getenv("MARATON_DATA_DIR")."/maraton.sqlite";
if(!is_file($p))exit(1);
$db=new PDO("sqlite:".$p);
$r=$db->query("PRAGMA integrity_check")->fetchColumn();
echo "Database integrity: ".$r.PHP_EOL;
if($r!=="ok")exit(1);
'
if [ "$MODE" = check ]; then
  echo "CHECK OK. No deployment performed."
  exit 0
fi
if [ -f "$STATE/deployed-sha" ] && [ "$(cat "$STATE/deployed-sha")" = "$SHA" ]; then
  echo "No changes."
  exit 0
fi
RELEASE="$STATE/release-$SHA"
mkdir -p "$RELEASE"
curl -fLsS --connect-timeout 15 --max-time 300 \
  "https://api.github.com/repos/$REPO/tarball/$SHA" -o "$STATE/source.tar.gz"
tar -xzf "$STATE/source.tar.gz" -C "$RELEASE" --strip-components=1
test -f "$RELEASE/Dockerfile"
# Never send local secrets or the live database to the build.
test ! -e "$RELEASE/.env.oracle"
test ! -e "$RELEASE/data/maraton.sqlite"
IMAGE="maraton-synology:$SHA"
docker build --memory=256m -t "$IMAGE" "$RELEASE"
# Keep deployment backups separately from the application's rotating backups.
docker exec "$CONTAINER" php -r '
$dir=getenv("MARATON_DATA_DIR");
$db=new PDO("sqlite:".$dir."/maraton.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA busy_timeout=10000");
$out=$dir."/deployment-backups";
if(!is_dir($out)&&!mkdir($out,0700,true))throw new RuntimeException("Backup directory failed");
$path=$out."/before-".gmdate("Ymd-His")."-".bin2hex(random_bytes(3)).".sqlite";
$db->exec("VACUUM INTO ".$db->quote($path));
$copy=new PDO("sqlite:".$path);
if($copy->query("PRAGMA integrity_check")->fetchColumn()!=="ok")throw new RuntimeException("Backup invalid");
chmod($path,0600);
echo "Verified deployment backup: ".basename($path).PHP_EOL;
'
OVERRIDE="$STATE/image.yaml"
printf 'services:\n  maraton:\n    image: %s\n' "$IMAGE" > "$OVERRIDE"
dc() {
  docker compose --project-directory "$BASE" -p maraton \
    -f "$BASE/compose.yaml" -f "$OVERRIDE" "$@"
}
wait_healthy() {
  n=0
  while [ "$n" -lt 30 ]; do
    h=$(docker inspect --format '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null || true)
    [ "$h" != healthy ] || return 0
    sleep 5
    n=$((n+1))
  done
  return 1
}
if dc up -d --no-build --no-deps maraton && wait_healthy; then
  printf '%s\n' "$SHA" > "$STATE/deployed-sha"
  printf '%s\n' "$OLD" > "$STATE/previous-image"
  echo "Deployment healthy. NAS configuration and data preserved."
else
  echo "Deployment failed. Restoring previous image, NOT overwriting database."
  printf 'services:\n  maraton:\n    image: %s\n' "$OLD" > "$OVERRIDE"
  dc up -d --no-build --no-deps maraton
  wait_healthy || echo "URGENT: previous image is not healthy; manual recovery required."
  echo "Database migrations may require manual recovery from the verified backup."
  exit 1
fi
