# Publicar Maratón gratis en Oracle Cloud

Esta configuración usa una máquina Oracle Always Free, Docker y Caddy. Caddy publica la aplicación con HTTPS y renueva el certificado automáticamente.

## 1. Preparar Oracle Cloud

1. Crea una cuenta en Oracle Cloud Free Tier.
2. Elige con cuidado la región principal. Los recursos Always Free solo se pueden crear en esa región.
3. Crea una red con `Start VCN Wizard` y selecciona `Create VCN with Internet Connectivity`.
4. Crea una instancia de Compute con estas opciones:
   - Nombre: `maraton`
   - Imagen: Oracle Linux 9 o Ubuntu
   - Shape: `VM.Standard.A1.Flex` marcada como Always Free
   - Recursos recomendados: 1 OCPU y 6 GB de memoria
   - Red pública con una dirección IPv4 pública
5. Descarga y guarda la clave SSH privada. No la compartas.

En la Security List o en el Network Security Group permite estas entradas:

- TCP 22 desde tu propia IP para SSH.
- TCP 80 desde `0.0.0.0/0` para la validación y redirección HTTPS.
- TCP 443 desde `0.0.0.0/0` para HTTPS.

No abras el puerto 4173 ni el puerto 80 del contenedor de Maratón directamente.

## 2. Crear un nombre gratuito

1. Crea una cuenta en DuckDNS.
2. Reserva un nombre, por ejemplo `mi-maraton.duckdns.org`.
3. Configura ese nombre con la IP pública de la instancia Oracle.
4. Espera hasta que el nombre resuelva a la IP correcta.

## 3. Preparar una copia coherente en el PC

Detén Maratón antes de copiar la base:

```powershell
cd C:\Users\Alba\Documents\GitHub\Maraton
docker compose down
```

Sube la carpeta completa. Sustituye la ruta de la clave y la IP:

```powershell
scp -i "C:\ruta\oracle-ssh.key" -r "C:\Users\Alba\Documents\GitHub\Maraton" opc@IP_PUBLICA:~/
```

La carpeta `data` incluye la cuenta, el progreso, la base SQLite y la clave que cifra el token de TMDB.

## 4. Instalar Docker en Oracle

Conecta por SSH:

```powershell
ssh -i "C:\ruta\oracle-ssh.key" opc@IP_PUBLICA
```

Dentro de Oracle ejecuta:

```bash
cd ~/Maraton
chmod +x deploy/oracle-bootstrap.sh
./deploy/oracle-bootstrap.sh
exit
```

Vuelve a conectarte por SSH para que se aplique el permiso del grupo Docker.

## 5. Configurar los secretos

Dentro de `~/Maraton` crea `.env.oracle`:

```bash
cd ~/Maraton
cp .env.oracle.example .env.oracle
nano .env.oracle
```

Configura:

```text
MARATON_DOMAIN=mi-maraton.duckdns.org
MARATON_SECRET_KEY=contenido_exacto_de_data/.secret-key
```

Para mostrar la clave que debes copiar dentro del servidor:

```bash
cat data/.secret-key
```

No generes una clave distinta. Una clave diferente impediría descifrar el token de TMDB ya guardado. Protege el archivo:

```bash
chmod 600 .env.oracle
```

## 6. Activar el cortafuegos del servidor

En Oracle Linux 9:

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

En Ubuntu:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
```

## 7. Arrancar la aplicación

```bash
docker compose --env-file .env.oracle -f compose.oracle.yaml up -d --build
docker compose --env-file .env.oracle -f compose.oracle.yaml ps
```

Cuando los servicios estén funcionando, abre:

```text
https://mi-maraton.duckdns.org
```

Caddy solicitará el certificado HTTPS automáticamente. La primera emisión puede tardar uno o dos minutos después de que el DNS sea correcto.

## Comandos útiles en Oracle

Ver estado:

```bash
docker compose --env-file .env.oracle -f compose.oracle.yaml ps
```

Ver registros:

```bash
docker compose --env-file .env.oracle -f compose.oracle.yaml logs --tail 100
```

Actualizar después de subir código nuevo:

```bash
docker compose --env-file .env.oracle -f compose.oracle.yaml up -d --build
```

Detener:

```bash
docker compose --env-file .env.oracle -f compose.oracle.yaml down
```

`down` no borra la carpeta `data` ni los volúmenes con los certificados.

## Copia externa

Las copias automáticas dentro de `data/backups` protegen frente a fallos de SQLite, pero siguen estando en el mismo servidor. Descarga periódicamente la carpeta `data` a otro directorio del PC:

```powershell
scp -i "C:\ruta\oracle-ssh.key" -r opc@IP_PUBLICA:~/Maraton/data "C:\Copias\Maraton-Oracle"
```

No ejecutes a la vez la copia local y la copia de Oracle como si fueran la misma aplicación. Cuando publiques Oracle, utiliza Oracle como versión principal.
