# End-to-end smoke test against the running PHP dev server.
# Run: pwsh -NoProfile -File .tmp-e2e.ps1
$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000'
$script:failures = @()

function New-Client { [Microsoft.PowerShell.Commands.WebRequestSession]::new() }

function Invoke-Request {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [ValidateSet('GET', 'POST')] [string] $Method = 'GET',
        [hashtable] $Body,
        [byte[]] $Bytes,
        [string] $ContentType,
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
    if ($Method -eq 'POST' -and $Bytes) {
        $params['Body'] = $Bytes
        $params['ContentType'] = $ContentType
    }

    try {
        $response = Invoke-WebRequest @params
        return [pscustomobject]@{
            Status   = [int] $response.StatusCode
            Location = $response.Headers['Location']
            Content  = $response.Content
            Headers  = $response.Headers
        }
    } catch {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }
        $status = [int] $response.StatusCode
        $location = $null
        try { $location = $response.Headers['Location'] } catch { }
        $content = ''
        try {
            $stream = $response.GetResponseStream()
            $reader = New-Object System.IO.StreamReader($stream)
            $content = $reader.ReadToEnd()
            $reader.Close()
        } catch { }
        return [pscustomobject]@{
            Status   = $status
            Location = $location
            Content  = $content
            Headers  = $response.Headers
        }
    }
}

function Get-CsrfToken {
    param([string] $Html)
    $match = [regex]::Match($Html, 'name="_csrf" value="([A-Fa-f0-9]+)"')
    if (-not $match.Success) { throw 'CSRF token not found in page' }
    return $match.Groups[1].Value
}

function Check {
    param([string] $Name, [bool] $Condition, [string] $Detail = '')
    if ($Condition) {
        Write-Output "  OK   $Name"
    } else {
        $script:failures += "$Name $Detail"
        Write-Output "  FAIL $Name $Detail"
    }
}

# multipart/form-data upload, built by hand so the script stays Windows
# PowerShell 5.1 compatible (-Form needs PowerShell 7).
function Invoke-FileUpload {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [hashtable] $Fields,
        [Parameter(Mandatory)] [string] $FileField,
        [Parameter(Mandatory)] [string] $FilePath,
        [Microsoft.PowerShell.Commands.WebRequestSession] $Session,
        [switch] $NoRedirect
    )

    $boundary = [Guid]::NewGuid().ToString('N')
    $encoding = [System.Text.Encoding]::UTF8
    $stream = New-Object System.IO.MemoryStream

    foreach ($name in $Fields.Keys) {
        $part = "--$boundary`r`nContent-Disposition: form-data; name=`"$name`"`r`n`r`n$($Fields[$name])`r`n"
        $bytes = $encoding.GetBytes($part)
        $stream.Write($bytes, 0, $bytes.Length)
    }

    $fileName = [System.IO.Path]::GetFileName($FilePath)
    $fileBytes = [System.IO.File]::ReadAllBytes($FilePath)
    $header = "--$boundary`r`nContent-Disposition: form-data; name=`"$FileField`"; filename=`"$fileName`"`r`nContent-Type: application/pdf`r`n`r`n"
    $headerBytes = $encoding.GetBytes($header)
    $stream.Write($headerBytes, 0, $headerBytes.Length)
    $stream.Write($fileBytes, 0, $fileBytes.Length)
    $closing = $encoding.GetBytes("`r`n--$boundary--`r`n")
    $stream.Write($closing, 0, $closing.Length)

    return Invoke-Request -Path $Path -Method POST -Session $Session -NoRedirect:$NoRedirect `
        -Bytes $stream.ToArray() -ContentType "multipart/form-data; boundary=$boundary"
}

$stamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$ownerEmail = "e2e-owner-$stamp@pddgen.local"
$inviteeEmail = "e2e-invitee-$stamp@pddgen.local"
$password = 'correct horse battery staple'

Write-Output '--- public pages ---'
$homePage = Invoke-Request '/'
Check 'home page renders' ($homePage.Status -eq 200 -and $homePage.Content -match 'validator-ready')
Check 'home page is English by default' ($homePage.Content -match '<html lang="en"')

$loginPage = Invoke-Request '/login'
Check 'login page renders' ($loginPage.Status -eq 200 -and $loginPage.Content -match 'Sign in')

Write-Output '--- sign up ---'
$owner = New-Client
$signupPage = Invoke-Request '/signup' -Session $owner
$signupToken = Get-CsrfToken $signupPage.Content
$signup = Invoke-Request '/signup' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf     = $signupToken
    name      = 'E2E Owner'
    email     = $ownerEmail
    password  = $password
    next      = '/dashboard'
}
Check 'signup redirects (303)' ($signup.Status -eq 303) "status=$($signup.Status)"
Check 'signup redirects to dashboard' ($signup.Location -like '*/dashboard*') "location=$($signup.Location)"

$dashboard = Invoke-Request '/dashboard' -Session $owner
Check 'dashboard loads for signed-in owner' ($dashboard.Status -eq 200)
Check 'dashboard shows empty state' ($dashboard.Content -match 'No projects yet')

Write-Output '--- create project ---'
$csrf = Get-CsrfToken $dashboard.Content
$teamId = [regex]::Match($dashboard.Content, 'name="team_id" value="([0-9a-f-]{36})"').Groups[1].Value
Check 'team id present in create form' ($teamId -ne '')

$create = Invoke-Request '/projects' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf       = $csrf
    team_id     = $teamId
    name        = 'E2E Cookstoves'
    description = 'Created by the end-to-end smoke test.'
}
Check 'project creation redirects (303)' ($create.Status -eq 303) "status=$($create.Status)"
$projectPath = $create.Location -replace '^.*?(/projects/)', '/projects/'
$project = Invoke-Request $projectPath -Session $owner
Check 'project page renders' ($project.Status -eq 200 -and $project.Content -match 'E2E Cookstoves')

Write-Output '--- team page and invitation ---'
$teamPage = Invoke-Request '/team' -Session $owner
Check 'team page renders' ($teamPage.Status -eq 200 -and $teamPage.Content -match 'E2E Owner')

$teamCsrf = Get-CsrfToken $teamPage.Content
$invite = Invoke-Request '/team/invite' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf = $teamCsrf
    email = $inviteeEmail
    role  = 'editor'
}
Check 'invitation creation redirects (303)' ($invite.Status -eq 303) "status=$($invite.Status)"

$teamPage2 = Invoke-Request '/team' -Session $owner
$inviteLink = [regex]::Match($teamPage2.Content, '(http[^"<]*?/invite/[A-Za-z0-9_-]+)').Groups[1].Value
Check 'invitation link shown once' ($inviteLink -ne '')
$invitePath = $inviteLink -replace '^https?://[^/]+', ''

Write-Output '--- invitee signs up and accepts ---'
$invitee = New-Client
$invitePage = Invoke-Request $invitePath -Session $invitee
Check 'invite page renders for guest' ($invitePage.Status -eq 200 -and $invitePage.Content -match 'Join')

$signupPage2 = Invoke-Request "/signup?next=$([uri]::EscapeDataString($invitePath))&email=$([uri]::EscapeDataString($inviteeEmail))" -Session $invitee
$signupToken2 = Get-CsrfToken $signupPage2.Content
$signup2 = Invoke-Request '/signup' -Method POST -Session $invitee -NoRedirect -Body @{
    _csrf    = $signupToken2
    name     = 'E2E Invitee'
    email    = $inviteeEmail
    password = $password
    next     = $invitePath
}
Check 'invitee signup redirects' ($signup2.Status -eq 303) "status=$($signup2.Status)"

$invitePageLoggedIn = Invoke-Request $invitePath -Session $invitee
Check 'invite page shows accept button' ($invitePageLoggedIn.Content -match 'Accept invitation')
$acceptToken = Get-CsrfToken $invitePageLoggedIn.Content
$accept = Invoke-Request "$invitePath/accept" -Method POST -Session $invitee -NoRedirect -Body @{ _csrf = $acceptToken }
Check 'accept redirects to dashboard' ($accept.Status -eq 303 -and $accept.Location -like '*/dashboard*') "status=$($accept.Status) location=$($accept.Location)"

$inviteeDashboard = Invoke-Request '/dashboard' -Session $invitee
Check 'invitee sees the shared workspace' ($inviteeDashboard.Status -eq 200 -and $inviteeDashboard.Content -match 'E2E Cookstoves')

Write-Output '--- organization onboarding ---'
$onboardingPage = Invoke-Request '/onboarding' -Session $owner
Check 'onboarding wizard renders' ($onboardingPage.Status -eq 200) "status=$($onboardingPage.Status)"
Check 'wizard starts at step 1' ($onboardingPage.Content -match 'Organization info' -and $onboardingPage.Content -match 'Step 1 of 4')
Check 'dashboard prompts for organization setup' ($dashboard.Content -match 'Verify your organization')

$declarationTemplate = Invoke-Request '/assets/templates/organization-declaration-template.docx'
Check 'declaration template is downloadable' ($declarationTemplate.Status -eq 200) "status=$($declarationTemplate.Status)"

$orgToken = Get-CsrfToken $onboardingPage.Content
$orgSave = Invoke-Request '/onboarding/organization' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf                 = $orgToken
    legal_name            = 'E2E Carbon Ventures Ltd'
    trading_name          = 'E2E Carbon'
    registration_number   = 'PVT-2026-0001'
    registration_country  = 'Kenya'
    organization_type     = 'developer'
    year_founded          = '2020'
    website               = 'https://e2e.example'
    registered_address    = '1 Test Street'
    city                  = 'Nairobi'
}
Check 'organization details save (303)' ($orgSave.Status -eq 303) "status=$($orgSave.Status)"
Check 'wizard advances to contact details' ($orgSave.Location -like '*step=2*') "location=$($orgSave.Location)"

$contactPage = Invoke-Request '/onboarding?step=2' -Session $owner
$contactToken = Get-CsrfToken $contactPage.Content
$contactSave = Invoke-Request '/onboarding/contact' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf           = $contactToken
    contact_name    = 'E2E Owner'
    contact_role    = 'Managing Director'
    contact_email   = $ownerEmail
    contact_phone   = '+254 700 000 111'
}
Check 'contact details save (303)' ($contactSave.Status -eq 303) "status=$($contactSave.Status)"
Check 'wizard advances to documents' ($contactSave.Location -like '*step=3*') "location=$($contactSave.Location)"

$docsPage = Invoke-Request '/onboarding?step=3' -Session $owner
Check 'documents step lists the required requirements' ($docsPage.Content -match 'CR-12' -and $docsPage.Content -match 'Upload document')

# An editor (not admin) can read the wizard but cannot change it.
$editorOnboarding = Invoke-Request '/onboarding' -Session $invitee
Check 'editor can view the wizard' ($editorOnboarding.Status -eq 200)
$editorToken = Get-CsrfToken $editorOnboarding.Content
$editorSave = Invoke-Request '/onboarding/organization' -Method POST -Session $invitee -NoRedirect -Body @{
    _csrf        = $editorToken
    legal_name   = 'Hijacked Ltd'
    city         = 'Nairobi'
}
Check 'editor cannot save organization details' ($editorSave.Status -eq 303 -and $editorSave.Location -notlike '*step=2*') "status=$($editorSave.Status) location=$($editorSave.Location)"
$editorAfter = Invoke-Request '/onboarding' -Session $invitee
Check 'editor sees a permission message' ($editorAfter.Content -match 'permission')

# Upload real files through the multipart form.
$pdfPath = Join-Path $env:TEMP ("e2e-onboarding-$stamp.pdf")
[System.IO.File]::WriteAllText($pdfPath, "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`ntrailer<</Root 1 0 R>>`n%%EOF`n")

$docsToken = Get-CsrfToken $docsPage.Content
foreach ($kind in @('registration_certificate', 'ownership_structure', 'declaration')) {
    $upload = Invoke-FileUpload -Path '/onboarding/documents' -Fields @{ _csrf = $docsToken; kind = $kind } `
        -FileField 'document' -FilePath $pdfPath -Session $owner -NoRedirect
    Check "document uploads: $kind" ($upload.Status -eq 303) "status=$($upload.Status)"
}

$docsAfter = Invoke-Request '/onboarding?step=3' -Session $owner
Check 'uploaded documents are listed' ($docsAfter.Content -match "e2e-onboarding-$stamp")
Check 'required documents are complete' ($docsAfter.Content -match '3 / 3')

$reviewPage = Invoke-Request '/onboarding?step=4' -Session $owner
Check 'review step renders' ($reviewPage.Status -eq 200 -and $reviewPage.Content -match 'Submit for review')
$reviewToken = Get-CsrfToken $reviewPage.Content
$submitNoAttestation = Invoke-Request '/onboarding/submit' -Method POST -Session $owner -NoRedirect -Body @{ _csrf = $reviewToken }
Check 'submit requires the attestation checkbox' ($submitNoAttestation.Status -eq 303 -and $submitNoAttestation.Location -like '*step=4*') "status=$($submitNoAttestation.Status)"

$submit = Invoke-Request '/onboarding/submit' -Method POST -Session $owner -NoRedirect -Body @{
    _csrf       = $reviewToken
    attestation = '1'
}
Check 'submitting redirects to the review step' ($submit.Status -eq 303 -and $submit.Location -like '*step=4*') "status=$($submit.Status) location=$($submit.Location)"

$submittedPage = Invoke-Request '/onboarding' -Session $owner
Check 'submitted profile shows the review banner' ($submittedPage.Content -match 'Submitted for review')
Check 'submitted profile is locked (no upload forms)' ($submittedPage.Content -notmatch 'name="document"')

$downloadLink = [regex]::Match($submittedPage.Content, '(?<url>/onboarding/documents/[0-9a-f-]{36}/download)').Groups['url'].Value
Check 'document download link is rendered' ($downloadLink -ne '')
if ($downloadLink -ne '') {
    $download = Invoke-Request $downloadLink -Session $owner
    $contentType = "$($download.Headers['Content-Type'])"
    Check 'document downloads as a PDF' ($download.Status -eq 200 -and $contentType -like '*application/pdf*') "status=$($download.Status) type=$contentType"
}

if (Test-Path -LiteralPath $pdfPath) { Remove-Item -LiteralPath $pdfPath -Force }

Write-Output '--- tenant isolation over HTTP ---'
$intruder = New-Client
$intruderPage = Invoke-Request '/signup' -Session $intruder
$intruderToken = Get-CsrfToken $intruderPage.Content
$intruderSignup = Invoke-Request '/signup' -Method POST -Session $intruder -NoRedirect -Body @{
    _csrf    = $intruderToken
    name     = 'E2E Intruder'
    email    = "e2e-intruder-$stamp@pddgen.local"
    password = $password
    next     = '/dashboard'
}
Check 'intruder signs up' ($intruderSignup.Status -eq 303)

$crossTenant = Invoke-Request $projectPath -Session $intruder
Check 'cross-tenant project is 404' ($crossTenant.Status -eq 404) "status=$($crossTenant.Status)"

Write-Output '--- CSRF protection ---'
$noCsrf = Invoke-Request '/projects' -Method POST -Session $owner -NoRedirect -Body @{
    team_id = $teamId
    name    = 'No token project'
}
Check 'POST without CSRF token is rejected' ($noCsrf.Status -eq 419) "status=$($noCsrf.Status)"

Write-Output '--- language switch ---'
$localeGuest = New-Client
$switched = Invoke-Request '/lang/fr?next=%2Flogin' -Session $localeGuest -NoRedirect
Check 'language switch redirects' ($switched.Status -eq 303) "status=$($switched.Status)"
$frenchLogin = Invoke-Request '/login' -Session $localeGuest
Check 'French login page renders' ($frenchLogin.Content -match 'Se connecter')

Write-Output '--- sign out ---'
$dashForLogout = Invoke-Request '/dashboard' -Session $owner
$logoutToken = Get-CsrfToken $dashForLogout.Content
$logout = Invoke-Request '/logout' -Method POST -Session $owner -NoRedirect -Body @{ _csrf = $logoutToken }
Check 'logout redirects to login' ($logout.Status -eq 303 -and $logout.Location -like '*/login*') "status=$($logout.Status)"
$afterLogout = Invoke-Request '/dashboard' -Session $owner -NoRedirect
Check 'dashboard requires sign-in after logout' ($afterLogout.Status -eq 303) "status=$($afterLogout.Status)"

Write-Output '--- demo account from seed ---'
$demo = New-Client
$demoLoginPage = Invoke-Request '/login' -Session $demo
$demoToken = Get-CsrfToken $demoLoginPage.Content
$demoLogin = Invoke-Request '/login' -Method POST -Session $demo -NoRedirect -Body @{
    _csrf    = $demoToken
    email    = 'demo@pddgen.local'
    password = 'DemoPassw0rd!123'
    next     = '/dashboard'
}
Check 'seeded demo account can sign in' ($demoLogin.Status -eq 303) "status=$($demoLogin.Status)"
$demoDashboard = Invoke-Request '/dashboard' -Session $demo
Check 'demo workspace lists seeded projects' ($demoDashboard.Content -match 'Kisumu Clean Cookstoves')

Write-Output ''
if ($script:failures.Count -eq 0) {
    Write-Output 'E2E: all checks passed'
    exit 0
}

Write-Output "E2E: $($script:failures.Count) failure(s)"
$script:failures | ForEach-Object { Write-Output " - $_" }
exit 1

