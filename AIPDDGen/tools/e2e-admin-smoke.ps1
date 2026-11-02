# End-to-end smoke test for the staff Action Center (port 8000).
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
$tenantAdmin = Invoke-Request '/admin' -Session $tenant
Check 'tenant gets 404 on /admin' ($tenantAdmin.Status -eq 404) "status=$($tenantAdmin.Status)"

Write-Output '--- staff sign in ---'
$staff = New-Client
$staffLoginPage = Invoke-Request '/login' -Session $staff
$staffToken = Get-CsrfToken $staffLoginPage.Content
$staffLogin = Invoke-Request '/login' -Method POST -Session $staff -NoRedirect -Body @{
    _csrf = $staffToken; email = 'admin@pddgen.local'; password = 'AdminPassw0rd!123'; next = '/admin'
}
Check 'staff sign in redirects' ($staffLogin.Status -eq 303) "status=$($staffLogin.Status)"

Write-Output '--- Action Center dashboard ---'
$dashboard = Invoke-Request '/admin' -Session $staff
Check 'Action Center renders' ($dashboard.Status -eq 200 -and $dashboard.Content -match 'Action Center')
Check 'assigned-to-me tile present' ($dashboard.Content -match 'Assigned to me')
Check 'SLA breached tile present' ($dashboard.Content -match 'SLA breached')
Check 'work type breakdown present' ($dashboard.Content -match 'By work type')
Check 'pipeline status cards present' ($dashboard.Content -match 'PDD pipeline status')
Check 'KPI tiles present' ($dashboard.Content -match 'Total workspaces')
Check 'SLA alert bar present' ($dashboard.Content -match 'breached SLA')
Check 'staff badge visible' ($dashboard.Content -match 'Staff console')

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
