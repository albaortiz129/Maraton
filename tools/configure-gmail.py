"""Receive Gmail credentials on stdin; never print them."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

root = Path('/home/opc/Maraton')
env_file = root / '.env.oracle'
try:
    data = json.load(sys.stdin)
    password = ''.join(str(data['password']).split())
    if not re.fullmatch(r'[a-zA-Z]{16}', password):
        raise ValueError('La contraseña de aplicación debe tener 16 letras.')
    values = {
        'MARATON_SMTP_HOST': 'smtp.gmail.com',
        'MARATON_SMTP_PORT': '465',
        'MARATON_SMTP_USER': 'alba.ortiz129@gmail.com',
        'MARATON_SMTP_FROM': 'alba.ortiz129@gmail.com',
        'MARATON_SMTP_PASSWORD': password,
    }
    # Check authentication without sending any email or changing configuration.
    import smtplib
    import ssl
    with smtplib.SMTP_SSL('smtp.gmail.com', 465, timeout=20,
                          context=ssl.create_default_context()) as smtp:
        smtp.login(values['MARATON_SMTP_USER'], password)
    original = env_file.read_text()
    lines = [line for line in original.splitlines()
             if line.split('=', 1)[0].strip() not in values]
    lines.extend(f'{key}={value}' for key, value in values.items())
    fd, temporary = tempfile.mkstemp(dir=root, prefix='.env.gmail-')
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write('\n'.join(lines) + '\n')
        os.chmod(temporary, 0o600)
        os.replace(temporary, env_file)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    subprocess.run(['docker', 'compose', '--env-file', '.env.oracle', '-f',
                    'compose.oracle.yaml', 'up', '-d', '--no-deps', 'maraton'],
                   cwd=root, check=True)
    print('Gmail configurado. Autenticación con Google comprobada.')
except Exception:
    print('No se pudo completar la configuración. Comprueba la contraseña de aplicación y la conexión con Google.', file=sys.stderr)
    sys.exit(1)
