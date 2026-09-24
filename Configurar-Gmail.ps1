$ErrorActionPreference = 'Stop'
Write-Host 'Configurar Gmail para Maraton: alba.ortiz129@gmail.com'
Write-Host 'Introduce la contrasena de APLICACION de Google (16 letras).'
$secret = Read-Host 'Contrasena de aplicacion' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
try {
    $payload = @{ password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) } | ConvertTo-Json -Compress
    $payload | ssh -i "$env:USERPROFILE\.ssh\oracle-a1.key" -o BatchMode=yes opc@204.216.210.136 'python3 /home/opc/Maraton/tools/configure-gmail.py'
    if ($LASTEXITCODE -ne 0) { throw 'La configuracion no se ha completado.' }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $payload = $null
    $secret.Dispose()
}
