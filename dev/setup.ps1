# Installs or updates the local development runtimes: the PHP and MySQL
# versions live runs, set like live's, in C:\tvtakip-dev - beside XAMPP, which
# it doesn't touch - and Composer with the development tools it installs into
# the checkout's vendor\ (PHPUnit, PHPStan: check.bat). Run it by
# setup-dev.bat (README.md, "Local development").
#
#   setup-dev.bat                     the versions live runs (asked over SSH)
#   setup-dev.bat -PhpVersion 8.3.37 -MysqlVersion 8.0.43
#                                     these versions, without asking live
#
# Running it again is safe: what is installed in the right version stays, the
# settings (dev\php.ini, dev\my.ini) are copied again, the database and its
# data stay. What C:\tvtakip-dev holds afterwards:
#   php\         PHP, non-thread-safe x64 (as live's PHP-FPM), + php.ini
#   mysql\       MySQL + my.ini
#   data\        MySQL's data directory: the database tvtakip and the account
#                tvtakip@localhost (no password) with every right on it alone,
#                as live's account has on live's database - and on
#                tvtakip_test, the tests' own (emptied at every test run)
#   tmp\         PHP's session files and temporary files
#   cacert.pem   the certificates PHP checks HTTPS against
#   composer.phar  Composer ($ComposerVersion below)
#
# A program or the dev server running from php\ or mysql\ blocks an update of
# that one: stop it first.

param(
    [string]$PhpVersion = '',
    [string]$MysqlVersion = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Dev = 'C:\tvtakip-dev'
$Repo = Split-Path -Parent $PSScriptRoot
$Tar = "$env:SystemRoot\System32\tar.exe"
$PhpReleases = 'https://downloads.php.net/~windows/releases'
# MySQL's release signing key ("MySQL Release Engineering"), as MySQL's manual
# lists it: a download counts only with a good signature by this key.
$MysqlKey = 'BCA43417C3B485DD128EC6D4B7B3B788A8D3785C'
# Composer: this version, signed by its release key ("Packagist Conductors").
# A newer one is a change here (getcomposer.org/download lists them).
$ComposerVersion = '2.11.2'
$ComposerKey = '161DFBE342889F01DDAC4E61CBB3D576F2A0946F'
$Database = 'tvtakip'
$Account = 'tvtakip'

function Fail([string]$Message) {
    Write-Host "`nSETUP FAILED: $Message" -ForegroundColor Red
    # The MySQL this run started, if any, goes again.
    if ($script:started) { & "$Dev\mysql\bin\mysqladmin.exe" -u root shutdown }
    exit 1
}

# $Quiet: no message when it fails (a first place to look).
function Download([string]$Url, [string]$File, [switch]$Quiet) {
    $show = '-S'
    if ($Quiet) { $show = '-s' }
    & curl.exe -fsL $show --retry 3 -o "$File.part" $Url
    if ($LASTEXITCODE -ne 0) {
        Remove-Item -Force -ErrorAction SilentlyContinue "$File.part"
        return $false
    }
    Move-Item -Force "$File.part" $File
    return $true
}

# A text file without a byte-order mark, which neither PHP nor MySQL expects.
function Write-Plain([string]$Path, [string]$Text) {
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding $false))
}

# One of the repository's settings files, its {dev} put in.
function Copy-Settings([string]$From, [string]$To, [string]$Dev) {
    Write-Plain $To ((Get-Content -Raw "$Repo\dev\$From").Replace('{dev}', $Dev))
}

# Unpack a zip into a new folder; $Inner: the zip's own top folder, if it has one.
function Unpack([string]$Zip, [string]$Folder, [string]$Inner = '') {
    if (Test-Path $Folder) { Remove-Item -Recurse -Force $Folder }
    New-Item -ItemType Directory $Folder | Out-Null
    & $Tar -xf $Zip -C $Folder
    if ($LASTEXITCODE -ne 0) { Fail "could not unpack $Zip" }
    if ($Inner) {
        Get-ChildItem -Force "$Folder\$Inner" | Move-Item -Destination $Folder
        Remove-Item "$Folder\$Inner"
    }
}

# Put a freshly unpacked folder in the place of the installed one.
function Move-Unpacked([string]$New, [string]$Target) {
    if (Test-Path $Target) {
        try {
            Rename-Item $Target ((Split-Path -Leaf $Target) + '.old')
        } catch {
            Remove-Item -Recurse -Force $New
            Fail "$Target is in use: stop what runs from it (the dev server, MySQL, a script) and run this again."
        }
        Remove-Item -Recurse -Force "$Target.old"
    }
    Rename-Item $New (Split-Path -Leaf $Target)
}

# Whether $File has a good signature ($Signature) by the key $Key, checked by
# the GnuPG that comes with Git for Windows, in a keyring of its own. Whatever
# the key server sends, only a signature by that very key counts.
function Test-Signature([string]$File, [string]$Signature, [string]$Key) {
    $gpg = Join-Path (Split-Path (Split-Path (Get-Command git).Source)) 'usr\bin\gpg.exe'
    if (-not (Test-Path $gpg)) { Fail "no GnuPG at $gpg (Git for Windows has it) to check signatures with" }
    $keyring = "$Dev\downloads\gnupg"
    if (Test-Path $keyring) { Remove-Item -Recurse -Force $keyring }
    New-Item -ItemType Directory $keyring | Out-Null
    if (-not (Download "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x$Key" "$keyring\key.asc")) {
        Fail "could not fetch the key $Key from keyserver.ubuntu.com"
    }
    # Git's GnuPG takes its home as a path of its own kind: /c/... for C:\...
    $env:GNUPGHOME = '/' + $keyring.Substring(0, 1).ToLower() + $keyring.Substring(2).Replace('\', '/')
    & $gpg --batch --quiet --no-autostart --import "$keyring\key.asc"
    $status = @(& $gpg --batch --no-autostart --status-fd 1 --verify $Signature $File)
    Remove-Item Env:\GNUPGHOME
    Remove-Item -Recurse -Force $keyring
    # VALIDSIG ends with the fingerprint of the signing key's primary key.
    return [bool]($status | Where-Object { $_ -match "^\[GNUPG:\] VALIDSIG .* $Key$" })
}

function Get-PhpVersion {
    if (-not (Test-Path "$Dev\php\php.exe")) { return '' }
    return [string](& "$Dev\php\php.exe" -n -r 'echo PHP_VERSION;')
}

function Get-ComposerVersion {
    if (-not (Test-Path "$Dev\composer.phar") -or -not (Test-Path "$Dev\php\php.exe")) { return '' }
    # It adds lines on its error output (see Get-PortServer for the 'Continue').
    $ErrorActionPreference = 'Continue'
    if ([string](& "$Dev\php\php.exe" "$Dev\composer.phar" --version --no-ansi 2>$null) -match 'Composer version (\S+)') { return $Matches[1] }
    return ''
}

function Get-MysqlVersion {
    if (-not (Test-Path "$Dev\mysql\bin\mysqld.exe")) { return '' }
    if ([string](& "$Dev\mysql\bin\mysqld.exe" --no-defaults --version) -match 'Ver (\d+\.\d+\.\d+)') { return $Matches[1] }
    return ''
}

# The server on MySQL's port: '' when there is none, else "<version> <basedir>"
# as it answers ours - or a description when it doesn't let ours in.
function Get-PortServer {
    $socket = New-Object Net.Sockets.TcpClient
    try {
        $socket.Connect('127.0.0.1', 3306)
    } catch {
        return ''
    } finally {
        $socket.Close()
    }
    if (-not (Test-Path "$Dev\mysql\bin\mysql.exe")) { return 'a server this setup did not install' }
    # Windows PowerShell turns a program's error output into errors of its own
    # once it is redirected - and 'Stop' would end the script at the first.
    $ErrorActionPreference = 'Continue'
    $answer = & "$Dev\mysql\bin\mysql.exe" --no-defaults -h 127.0.0.1 -P 3306 -u root --connect-timeout=3 -N -r -e 'SELECT VERSION(), @@basedir' 2>$null
    if ($LASTEXITCODE -ne 0) { return 'a server that does not let root in without a password' }
    return [string]$answer
}

function Invoke-Sql([string]$Sql) {
    & "$Dev\mysql\bin\mysql.exe" -u root -e $Sql
    if ($LASTEXITCODE -ne 0) { Fail "this SQL failed: $Sql" }
}

# --- 1. The versions -------------------------------------------------------

if (-not $PhpVersion -or -not $MysqlVersion) {
    Write-Host 'Asking live for its versions (ssh hostuno) ...'
    $answer = @(& ssh -o BatchMode=yes -o ConnectTimeout=20 hostuno "php -r 'echo PHP_VERSION, PHP_EOL;' && mysql -N -e 'SELECT VERSION()'")
    if ($LASTEXITCODE -ne 0 -or $answer.Count -lt 2) {
        Fail 'could not ask live over SSH. Name the versions instead: setup-dev.bat -PhpVersion 8.3.37 -MysqlVersion 8.0.43'
    }
    if (-not $PhpVersion) { $PhpVersion = $answer[0].Trim() }
    if (-not $MysqlVersion) { $MysqlVersion = $answer[1].Trim() }
}
foreach ($version in @($PhpVersion, $MysqlVersion)) {
    if ($version -notmatch '^\d+\.\d+\.\d+$') { Fail "not a version: $version" }
}
Write-Host "PHP $PhpVersion and MySQL $MysqlVersion, in $Dev"

foreach ($folder in @($Dev, "$Dev\tmp", "$Dev\downloads")) {
    if (-not (Test-Path $folder)) { New-Item -ItemType Directory $folder | Out-Null }
}

# --- 2. PHP ----------------------------------------------------------------

if ((Get-PhpVersion) -eq $PhpVersion) {
    Write-Host "PHP $PhpVersion is installed."
} else {
    # PHP 8.4 and later are built with Visual Studio 17, earlier ones with 16.
    $vs = 'vs16'
    if ([version]$PhpVersion -ge [version]'8.4.0') { $vs = 'vs17' }
    $name = "php-$PhpVersion-nts-Win32-$vs-x64.zip"
    $zip = "$Dev\downloads\$name"
    Write-Host "Downloading $name ..."
    # The newest release of each branch is in releases\, with its SHA-256 in
    # sha256sum.txt; older ones are in archives\, for which there is no such list.
    $sums = @(& curl.exe -fsSL "$PhpReleases/sha256sum.txt")
    $line = $sums | Where-Object { $_.Trim() -match "^[0-9a-f]{64} \*$([regex]::Escape($name))$" } | Select-Object -First 1
    if ($line) {
        $sha256 = $line.Substring(0, 64)
        if (-not (Download "$PhpReleases/$name" $zip)) { Fail "could not download $PhpReleases/$name" }
        if ((Get-FileHash -Algorithm SHA256 $zip).Hash -ne $sha256.ToUpper()) {
            Remove-Item $zip
            Fail "$name arrived different from the SHA-256 php.net publishes for it"
        }
        Write-Host 'SHA-256 as php.net publishes it.'
    } else {
        if (-not (Download "$PhpReleases/archives/$name" $zip)) { Fail "no such PHP for Windows: $name" }
        Write-Host 'An archived release: php.net publishes no SHA-256 for those.' -ForegroundColor Yellow
    }
    Unpack $zip "$Dev\php.new"
    Move-Unpacked "$Dev\php.new" "$Dev\php"
    Remove-Item $zip
    if ((Get-PhpVersion) -ne $PhpVersion) {
        Fail "the new PHP does not run. It needs the Microsoft Visual C++ Redistributable (x64): https://aka.ms/vs/17/release/vc_redist.x64.exe"
    }
    Write-Host "PHP $PhpVersion installed."
}

Write-Host 'Certificates for HTTPS (curl.se/ca/cacert.pem) ...'
if (-not (Download 'https://curl.se/ca/cacert.pem' "$Dev\downloads\cacert.pem")) { Fail 'could not download https://curl.se/ca/cacert.pem' }
$expected = ([string](& curl.exe -fsSL 'https://curl.se/ca/cacert.pem.sha256')).Split(' ')[0]
if ((Get-FileHash -Algorithm SHA256 "$Dev\downloads\cacert.pem").Hash -ne $expected.ToUpper()) {
    Fail 'cacert.pem arrived different from the SHA-256 curl.se publishes for it'
}
Move-Item -Force "$Dev\downloads\cacert.pem" "$Dev\cacert.pem"

Copy-Settings 'php.ini' "$Dev\php\php.ini" $Dev
# (No double quotes in a program's arguments: Windows PowerShell passes them on mangled.)
$missing = & "$Dev\php\php.exe" -r 'foreach ([''curl'', ''intl'', ''mbstring'', ''openssl'', ''pdo_mysql'', ''Zend OPcache''] as $e) if (!extension_loaded($e)) echo $e, '' '';'
if ($LASTEXITCODE -ne 0 -or $missing) { Fail "PHP did not load these extensions: $missing" }

# --- 3. MySQL --------------------------------------------------------------

$onPort = Get-PortServer
if ($onPort -and $onPort -notlike "*$Dev\mysql\") {
    Fail "another MySQL server answers on port 3306: $onPort. Stop it first (XAMPP's: its Control Panel, or C:\xampp\mysql_stop.bat)."
}
$running = [bool]$onPort

if ((Get-MysqlVersion) -eq $MysqlVersion) {
    Write-Host "MySQL $MysqlVersion is installed."
} else {
    $series = $MysqlVersion -replace '^(\d+\.\d+)\..*$', '$1'
    $name = "mysql-$MysqlVersion-winx64.zip"
    $zip = "$Dev\downloads\$name"
    Write-Host "Downloading $name (about 250 MB) ..."
    # A version still current is under Downloads\, an older one under archives\.
    $base = "https://cdn.mysql.com/Downloads/MySQL-$series"
    if (-not (Download "$base/$name" $zip -Quiet)) {
        $base = "https://cdn.mysql.com/archives/mysql-$series"
        if (-not (Download "$base/$name" $zip)) { Fail "no such MySQL for Windows: $name" }
    }
    if (-not (Download "$base/$name.asc" "$zip.asc")) { Fail "could not download $base/$name.asc" }
    if (-not (Test-Signature $zip "$zip.asc" $MysqlKey)) {
        Remove-Item $zip, "$zip.asc"
        Fail "$name is not signed by MySQL's key $MysqlKey"
    }
    Write-Host "Signed by MySQL's release key."

    if ($running) {
        Write-Host 'Stopping MySQL, to replace it ...'
        & "$Dev\mysql\bin\mysqladmin.exe" -u root shutdown
        Start-Sleep -Seconds 2
        while (Get-Process mysqld -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$Dev\mysql\*" }) { Start-Sleep -Milliseconds 500 }
        $running = $false
    }
    Unpack $zip "$Dev\mysql.new" ($name -replace '\.zip$', '')
    Move-Unpacked "$Dev\mysql.new" "$Dev\mysql"
    Remove-Item $zip, "$zip.asc"
    if ((Get-MysqlVersion) -ne $MysqlVersion) {
        Fail "the new MySQL does not run. It needs the Microsoft Visual C++ Redistributable (x64): https://aka.ms/vs/17/release/vc_redist.x64.exe"
    }
    Write-Host "MySQL $MysqlVersion installed."
}
Copy-Settings 'my.ini' "$Dev\mysql\my.ini" ($Dev -replace '\\', '/')

if (-not (Test-Path "$Dev\data")) {
    Write-Host 'Creating the data directory (root@localhost, no password) ...'
    & "$Dev\mysql\bin\mysqld.exe" --initialize-insecure --console
    if ($LASTEXITCODE -ne 0) { Fail 'mysqld --initialize-insecure failed (its output is above)' }
}

$started = $null
if (-not $running) {
    Write-Host 'Starting MySQL for the moment ...'
    $started = Start-Process -FilePath "$Dev\mysql\bin\mysqld.exe" -ArgumentList '--console' -WindowStyle Minimized -PassThru
    # A newer version upgrades the data directory as it starts: that takes a while.
    $until = (Get-Date).AddMinutes(5)
    while (-not (Get-PortServer)) {
        if ($started.HasExited -or (Get-Date) -gt $until) { Fail "MySQL did not start: see $Dev\data\*.err" }
        Start-Sleep -Milliseconds 500
    }
}

# Live's database has utf8mb4_unicode_ci as its default (its server, like
# this one, utf8mb4_general_ci): a table created without a collation of its
# own gets that one, there as here.
Invoke-Sql "CREATE DATABASE IF NOT EXISTS $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
Invoke-Sql "CREATE USER IF NOT EXISTS '$Account'@'localhost' IDENTIFIED BY ''"
Invoke-Sql "GRANT ALL PRIVILEGES ON $Database.* TO '$Account'@'localhost'"
# The tests' own database (tests/bootstrap.php), emptied and built anew by
# every test run.
Invoke-Sql "CREATE DATABASE IF NOT EXISTS ${Database}_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
Invoke-Sql "GRANT ALL PRIVILEGES ON ${Database}_test.* TO '$Account'@'localhost'"

if (Test-Path "$Repo\config.php") {
    Write-Host 'Applying the migrations (scripts\migrate.php) ...'
    & "$Dev\php\php.exe" "$Repo\scripts\migrate.php"
    if ($LASTEXITCODE -ne 0) { Fail "scripts\migrate.php failed: is config.php's database this one (DB_HOST localhost, DB_NAME $Database, DB_USER $Account, DB_PASS '')?" }
}

if ($started) {
    & "$Dev\mysql\bin\mysqladmin.exe" -u root shutdown
    $started.WaitForExit(60000) | Out-Null
}

# --- 4. Composer and the development tools ---------------------------------

if ((Get-ComposerVersion) -eq $ComposerVersion) {
    Write-Host "Composer $ComposerVersion is installed."
} else {
    $phar = "$Dev\downloads\composer.phar"
    $base = "https://getcomposer.org/download/$ComposerVersion"
    Write-Host "Downloading Composer $ComposerVersion ..."
    if (-not (Download "$base/composer.phar" $phar)) { Fail "no such Composer: $base/composer.phar" }
    if (-not (Download "$base/composer.phar.asc" "$phar.asc")) { Fail "could not download $base/composer.phar.asc" }
    if (-not (Test-Signature $phar "$phar.asc" $ComposerKey)) {
        Remove-Item $phar, "$phar.asc"
        Fail "composer.phar $ComposerVersion is not signed by Composer's key $ComposerKey"
    }
    Write-Host "Signed by Composer's release key."
    Remove-Item "$phar.asc"
    Move-Item -Force $phar "$Dev\composer.phar"
}

# What composer.lock names, into the checkout's vendor\ (nothing of it goes
# to live: the site itself needs no package).
Write-Host 'Installing the development tools (composer install) ...'
& "$Dev\php\php.exe" "$Dev\composer.phar" install --no-interaction --working-dir="$Repo"
if ($LASTEXITCODE -ne 0) { Fail 'composer install failed (its output is above)' }

Write-Host "`nDone: PHP $(Get-PhpVersion), MySQL $(Get-MysqlVersion) and Composer $(Get-ComposerVersion) in $Dev."
if (-not (Test-Path "$Repo\config.php")) {
    Write-Host 'Next: copy config.sample.php config.php and set TMDB_API_KEY in it, then run this again (it creates the tables).'
}
Write-Host 'start-dev.bat starts the site; C:\tvtakip-dev\php\php.exe scripts\load_live.php fills the database from the newest live backup.'
Write-Host 'check.bat runs the static analysis and the tests (with MySQL running); deploy.bat runs them too.'
