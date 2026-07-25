# Maratón

Aplicación web local para llevar el seguimiento de series. Incluye cuentas protegidas con contraseña, datos separados por usuario, seguimiento por temporada y episodio, agenda de emisiones de TMDB e importación de TV Time.

## Ejecutar

Desde esta carpeta:

```powershell
php -S 127.0.0.1:4173 router.php
```

Después abre `http://127.0.0.1:4173`. Es imprescindible incluir `router.php`: impide que el navegador acceda directamente a la base de datos o a las claves locales. No uses `php -S 127.0.0.1:4173` sin el último argumento.

## Ejecutar con Docker (recomendado)

Instala Docker Desktop y, desde esta carpeta, ejecuta:

```powershell
docker compose up -d --build
```

Antes, detén cualquier servidor PHP que siga usando el puerto 4173 (`Ctrl + C` en su terminal). Si el puerto está ocupado, Docker no podrá iniciar el contenedor.

Abre `http://127.0.0.1:4173`. La base de datos se monta desde `./data` dentro de `/var/lib/maraton`, fuera de la carpeta pública del contenedor. Por tanto, conserva las cuentas y el progreso actuales y los datos sobreviven a reconstrucciones.

Comandos útiles:

```powershell
docker compose ps
docker compose logs -f maraton
docker compose restart maraton
docker compose down
```

`docker compose down` detiene el servicio pero no borra `./data`. Para actualizar el código, vuelve a ejecutar `docker compose up -d --build`.

## Datos y seguridad

- Los usuarios, contraseñas y bibliotecas se guardan en `data/maraton.sqlite`.
- Docker crea al arrancar una copia coherente de SQLite en `data/backups` y conserva las cinco más recientes.
- Las contraseñas se almacenan con `password_hash`; nunca se guarda la contraseña original.
- El token de TMDB se cifra con AES-256-GCM y una clave local situada en `data/.secret-key`.
- `router.php` bloquea el acceso web a toda la carpeta `data`.
- La aplicación limita los intentos fallidos de acceso y protege las escrituras con un token CSRF.
- Para una publicación real todavía se deben configurar HTTPS, copias externas y una clave en variable de entorno o gestor de secretos.

Si olvidas la contraseña, ejecuta `.\restablecer-contrasena.ps1` desde PowerShell. La nueva contraseña se solicita de forma oculta y el proceso no modifica las series ni el progreso.

## Preparar una publicación

En producción configura `MARATON_DATA_DIR` con una ruta fuera de la carpeta pública, `MARATON_SECRET_KEY` con 32 bytes aleatorios codificados en base64 y `MARATON_HTTPS=1` si HTTPS termina en un proxy inverso. Consulta `.env.example` como referencia; PHP no carga ese archivo automáticamente, las variables deben configurarse en el servidor.

No publiques el servidor integrado de PHP. Utiliza Apache o Nginx con HTTPS, limita la carpeta pública a los archivos necesarios y programa copias de seguridad de `MARATON_DATA_DIR`.

No borres la carpeta `data`. Guarda una copia periódica de esa carpeta y conserva también las exportaciones JSON generadas desde el perfil.

## Conectar TMDB

1. Crea una cuenta en TMDB y solicita acceso a la API.
2. Copia el **API Read Access Token** desde los ajustes de API.
3. Abre Perfil → Conectar con TMDB, pega el token y pulsa “Guardar y probar”.

## Importar TV Time

TV Time indica que residentes en la UE pueden pedir sus datos portables escribiendo a `support@tvtime.com`. Descomprime el ZIP recibido y selecciona sus archivos `.json` o `.csv` desde Perfil → Traer mis datos de TV Time. La aplicación presenta las coincidencias antes de guardarlas.
