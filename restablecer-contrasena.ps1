$ErrorActionPreference = 'Stop'

function ConvertFrom-SecureValue([Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

$username = Read-Host 'Nombre de usuario'
$firstSecure = Read-Host 'Nueva contraseña (mínimo 8 caracteres)' -AsSecureString
$secondSecure = Read-Host 'Repite la nueva contraseña' -AsSecureString
$first = ConvertFrom-SecureValue $firstSecure
$second = ConvertFrom-SecureValue $secondSecure

try {
    if ($first -cne $second) {
        throw 'Las contraseñas no coinciden.'
    }

    $payload = @{
        username = $username
        password = $first
    } | ConvertTo-Json -Compress

    $payload | php (Join-Path $PSScriptRoot 'tools\reset-password.php')
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo cambiar la contraseña.'
    }
} finally {
    $first = $null
    $second = $null
    $payload = $null
}
