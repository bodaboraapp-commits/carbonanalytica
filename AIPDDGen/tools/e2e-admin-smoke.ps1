# End-to-end smoke test for the platform console and review queue (port 8000).
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File tools/e2e-admin-smoke.ps1
$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000'
$script:failures = @()

function New-Client { New-Object Microsoft.PowerShell.Commands.WebRequestSession }

function Invoke-Request {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [ValidateSet('GET', 'POST')] [string] $Method = 'GET',
        [hashtable] $Body,
        [Microsoft.PowerShell.Commands.WebRequestSession] $Session,
        [switch] $NoRedirect
    )
    $uri = if ($Path.StartsWith('http')) { $Path } else { "$base$Path" }
    $params = @{
        Uri                = $uri
        Method             = $Method
        WebSession         = $Session
        MaximumRedirection = if ($NoRedirect) { 0 } else { 5 }
        UseBasicParsing    = $true
    }
    if ($Method -eq 'POST' -and $Body) {
        $params['Body'] = $Body
        $params['ContentType'] = 'application/x-www-form-urlencoded'
    }
    try {
        $response = Invoke-WebRequest @params
        return [pscustomobject]@{ Status = [int]$response.StatusCode; Location = $response.Headers['Location']; Content = $response.Content }
    } catch {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }
        $content = ''
        try { $stream = $response.GetResponseStream(); $reader = New-Object System.IO.StreamReader($stream); $content = $reader.ReadToEnd(); $reader.Close() } catch { }
        return [pscustomobject]@{ Status = [int]$response.StatusCode; Location = $response.Headers['Location']; Content = $content }
    }
}

function Get-CsrfToken {
    param([string] $Html)
    $match = [regex]::Match($Html, 'name="_csrf" value="([A-Fa-f0-9]+)"')
    if (-not $match.Success) { throw 'CSRF token not found' }
    return $match.Groups[1].Value
}

function Check {
    param([string] $Name, [bool] $Condition, [string] $Detail = '')
    if ($Condition) { Write-Output "  OK   $Name" } else { $script:failures += "$Name $Detail"; Write-Output "  FAIL $Name $Detail" }
}

Write-Output '--- non-staff access is hidden ---'
$tenant = New-Client
$loginPage = Invoke-Request '/login' -Session $tenant
$tenantToken = Get-CsrfToken $loginPage.Content
$tenantLogin = Invoke-Request '/login' -Method POST -Session $tenant -NoRedirect -Body @{
    _csrf = $tenantToken; email = 'demo@pddgen.local'; password = 'DemoPassw0rd!123'; next = '/dashboard'
}
Check 'demo tenant can sign in' ($tenantLogin.Status -eq 303) "status=$($tenantLogin.Status)"
foreach ($consolePath in @('/admin', '/admin/tasks', '/admin/workspaces', '/admin/kyc', '/admin/users', '/admin/templates', '/admin/audit', '/admin/database')) {
    $tenantConsole = Invoke-Request $consolePath -Session $tenant
    Check "tenant gets 404 on $consolePath" ($tenantConsole.Status -eq 404) "status=$($tenantConsole.Status)"
}

Write-Output '--- staff sign in ---'
$staff = New-Client
$staffLoginPage = Invoke-Request '/login' -Session $staff
$staffToken = Get-CsrfToken $staffLoginPage.Content
$staffLogin = Invoke-Request '/login' -Method POST -Session $staff -NoRedirect -Body @{
    _csrf = $staffToken; email = 'admin@pddgen.local'; password = 'AdminPassw0rd!123'; next = '/admin'
}
Check 'staff sign in redirects' ($staffLogin.Status -eq 303) "status=$($staffLogin.Status)"

Write-Output '--- Platform console overview ---'
$dashboard = Invoke-Request '/admin' -Session $staff
Check 'super admin dashboard renders' ($dashboard.Status -eq 200 -and $dashboard.Content -match 'Super admin dashboard')
Check 'KPI tiles present' ($dashboard.Content -match 'Platform statistics')
Check 'growth stats present' ($dashboard.Content -match 'Growth and activity')
Check 'system health present' ($dashboard.Content -match 'System health')
Check 'platform controls present' ($dashboard.Content -match 'Platform controls' -and $dashboard.Content -match 'Maintenance mode')
Check 'quick actions present' ($dashboard.Content -match 'Review KYC' -and $dashboard.Content -match 'Open queue')
Check 'KYC status present' ($dashboard.Content -match 'KYC waiting')
Check 'recent activity present' ($dashboard.Content -match 'Recent activity')
Check 'security card present' ($dashboard.Content -match 'Two-factor sign-in')
Check 'left side-panel menu present' ($dashboard.Content -match 'admin-sidebar' -and $dashboard.Content -match 'admin-nav-group__title')
Check 'mobile menu toggle present' ($dashboard.Content -match 'admin-nav-toggle-input')

Write-Output '--- platform controls ---'
$adminCsrf = Get-CsrfToken $dashboard.Content
$guest = New-Client
$guestHome = Invoke-Request '/' -Session $guest
Check 'guest home is live before maintenance' ($guestHome.Status -eq 200)
Invoke-Request '/admin/settings/maintenance_mode' -Session $staff -Method POST -Body @{
    _csrf = $adminCsrf; value = '1'
} | Out-Null
$guestBlocked = Invoke-Request '/' -Session $guest -NoRedirect
Check 'guest gets 503 in maintenance mode' ($guestBlocked.Status -eq 503)
$staffDuring = Invoke-Request '/admin' -Session $staff
Check 'staff keep access during maintenance' ($staffDuring.Status -eq 200)
$offCsrf = Get-CsrfToken $staffDuring.Content
Invoke-Request '/admin/settings/maintenance_mode' -Session $staff -Method POST -Body @{
    _csrf = $offCsrf; value = '0'
} | Out-Null
$guestBack = Invoke-Request '/' -Session $guest
Check 'guest home restored after maintenance' ($guestBack.Status -eq 200)

$consolePages = @{
    '/admin/workspaces' = 'Workspaces'
    '/admin/kyc'        = 'KYC queue'
    '/admin/users'      = 'Users'
    '/admin/templates'  = 'Template library'
    '/admin/audit'      = 'Audit'
    '/admin/database'   = 'Database'
}
foreach ($page in $consolePages.GetEnumerator()) {
    $res = Invoke-Request $page.Key -Session $staff
    Check "console page $($page.Key)" ($res.Status -eq 200 -and $res.Content -match [regex]::Escape($page.Value)) "status=$($res.Status)"
}

$search = Invoke-Request '/admin/workspaces?q=demo' -Session $staff
Check 'workspace search responds' ($search.Status -eq 200)
$adminsOnly = Invoke-Request '/admin/users?admin=1' -Session $staff
Check 'admin filter responds' ($adminsOnly.Status -eq 200 -and $adminsOnly.Content -match 'Platform admin')
$auditFiltered = Invoke-Request '/admin/audit?action=auth.signin' -Session $staff
Check 'audit filter responds' ($auditFiltered.Status -eq 200)
Write-Output '--- Job queue ---'
$queue = Invoke-Request '/admin/tasks' -Session $staff
Check 'job queue renders' ($queue.Status -eq 200 -and $queue.Content -match 'Job queue')
Check 'queue lists tasks' ($queue.Content -match 'Gold Standard')

$taskId = [regex]::Match($queue.Content, '/admin/tasks/([0-9a-f-]{36})/assign').Groups[1].Value
Check 'task action found' ($taskId -ne '')
$queueCsrf = Get-CsrfToken $queue.Content

$assign = Invoke-Request "/admin/tasks/$taskId/assign" -Method POST -Session $staff -NoRedirect -Body @{
    _csrf = $queueCsrf; return = '/admin/tasks'
}
Check 'assign redirects back' ($assign.Status -eq 303) "status=$($assign.Status)"
$queueAfter = Invoke-Request '/admin/tasks' -Session $staff
Check 'assignment flashed' ($queueAfter.Content -match 'Task assigned to you')

$completeCsrf = Get-CsrfToken $queueAfter.Content
$complete = Invoke-Request "/admin/tasks/$taskId/complete" -Method POST -Session $staff -NoRedirect -Body @{
    _csrf = $completeCsrf; return = '/admin/tasks'
}
Check 'complete redirects back' ($complete.Status -eq 303) "status=$($complete.Status)"
$queueCompleted = Invoke-Request '/admin/tasks?scope=completed' -Session $staff
Check 'completed task appears in closed scope' ($queueCompleted.Status -eq 200 -and $queueCompleted.Content -match 'Completed')

Write-Output '--- search + breach filter ---'
$search = Invoke-Request '/admin/tasks?q=template' -Session $staff
Check 'search returns results' ($search.Status -eq 200 -and $search.Content -match 'Template review')
$breached = Invoke-Request '/admin/tasks?scope=breached' -Session $staff
Check 'breach filter is scoped' ($breached.Status -eq 200)

Write-Output '--- CSRF protection on admin actions ---'
$noCsrf = Invoke-Request "/admin/tasks/$taskId/assign" -Method POST -Session $staff -NoRedirect -Body @{ return = '/admin/tasks' }
Check 'admin POST without CSRF is rejected' ($noCsrf.Status -eq 419) "status=$($noCsrf.Status)"

Write-Output ''
if ($script:failures.Count -eq 0) {
    Write-Output 'E2E admin: all checks passed'
    exit 0
}
Write-Output "E2E admin: $($script:failures.Count) failure(s)"
$script:failures | ForEach-Object { Write-Output " - $_" }
exit 1
