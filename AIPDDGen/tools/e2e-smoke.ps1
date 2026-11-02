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

# --- Email one-time codes -------------------------------------------------
# The dev server writes every message to storage/logs/mail.log (in development
# this happens even when Resend is the configured transport), so the smoke test
# can read the code the way a developer would.
$script:mailLogPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'storage/logs/mail.log'

function Get-OtpCode {
    param([Parameter(Mandatory)] [string] $Email)

    if (-not (Test-Path -LiteralPath $script:mailLogPath)) {
        throw "mail.log not found at $($script:mailLogPath)"
    }
    $content = Get-Content -LiteralPath $script:mailLogPath -Raw
    if (-not $content) { throw 'mail.log is empty' }

    $needle = [regex]::Escape("to=$Email kind=otp")
    $found = $null
    foreach ($block in ($content -split '-{20,}')) {
        if ($block -match $needle) { $found = $block }
    }
    if ($null -eq $found) { throw "no OTP email for $Email in mail.log" }

    $match = [regex]::Match($found, '(?m)^\s*(\d{6})\s*$')
    if (-not $match.Success) { throw "no six-digit code in the OTP email for $Email" }
    return $match.Groups[1].Value
}

# Submit the emailed code from a fresh /verify-otp page, one digit per box.
function Complete-Otp {
    param([Parameter(Mandatory)] [string] $Email, $Session)

    $codePage = Invoke-Request '/verify-otp' -Session $Session
    $token = Get-CsrfToken $codePage.Content
    $code = Get-OtpCode -Email $Email

    $fields = @{ _csrf = $token }
    for ($index = 0; $index -lt 6; $index++) {
        $fields["code[$index]"] = $code.Substring($index, 1)
    }
    return Invoke-Request '/verify-otp' -Method POST -Session $Session -NoRedirect -Body $fields
}

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
Check 'signup starts the one-time-code screen' ($signup.Location -like '*/verify-otp*') "location=$($signup.Location)"

$verifyPage = Invoke-Request '/verify-otp' -Session $owner
Check 'code screen renders' ($verifyPage.Status -eq 200 -and $verifyPage.Content -match '6-digit verification code')
Check 'code screen marks the email address' ($verifyPage.Content -match 'e\*+@pddgen\.local')

$boxCount = ([regex]::Matches($verifyPage.Content, 'name="code\[\]"')).Count
Check 'code screen shows six digit boxes' ($boxCount -eq 6) "boxes=$boxCount"

$beforeCode = Invoke-Request '/dashboard' -Session $owner -NoRedirect
Check 'no session is created before the code' ($beforeCode.Status -eq 303 -and $beforeCode.Location -like '*/verify-otp*') "status=$($beforeCode.Status) location=$($beforeCode.Location)"

$verifyToken = Get-CsrfToken $verifyPage.Content
$wrongCode = if ((Get-OtpCode -Email $ownerEmail) -eq '000000') { '111111' } else { '000000' }
$wrongBody = @{ _csrf = $verifyToken }
for ($index = 0; $index -lt 6; $index++) { $wrongBody["code[$index]"] = $wrongCode.Substring($index, 1) }
$wrongAttempt = Invoke-Request '/verify-otp' -Method POST -Session $owner -NoRedirect -Body $wrongBody
Check 'a wrong code is refused' ($wrongAttempt.Status -eq 303 -and $wrongAttempt.Location -like '*/verify-otp*') "status=$($wrongAttempt.Status)"
$afterWrong = Invoke-Request '/verify-otp' -Session $owner
Check 'a wrong code shows an error' ($afterWrong.Content -match 'not correct')

# One empty box is not a six-digit code and must not reach the verifier.
$partialBody = @{ _csrf = $verifyToken }
for ($index = 0; $index -lt 5; $index++) { $partialBody["code[$index]"] = '1' }
$partial = Invoke-Request '/verify-otp' -Method POST -Session $owner -NoRedirect -Body $partialBody
Check 'an incomplete code is refused' ($partial.Status -eq 303 -and $partial.Location -like '*/verify-otp*') "status=$($partial.Status)"
$afterPartial = Invoke-Request '/verify-otp' -Session $owner
Check 'an incomplete code shows a field error' ($afterPartial.Content -match 'Enter the 6-digit')

$ownerVerified = Complete-Otp -Email $ownerEmail -Session $owner
Check 'the emailed code signs the owner in' ($ownerVerified.Status -eq 303 -and $ownerVerified.Location -like '*/dashboard*') "status=$($ownerVerified.Status) location=$($ownerVerified.Location)"

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
Check 'invitee signup starts the code screen' ($signup2.Location -like '*/verify-otp*') "location=$($signup2.Location)"

$inviteeVerified = Complete-Otp -Email $inviteeEmail -Session $invitee
Check 'invitee code verifies and returns to the invitation' ($inviteeVerified.Status -eq 303 -and $inviteeVerified.Location -like '*invite*') "status=$($inviteeVerified.Status) location=$($inviteeVerified.Location)"

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
$intruderVerified = Complete-Otp -Email "e2e-intruder-$stamp@pddgen.local" -Session $intruder
Check 'intruder verifies the code' ($intruderVerified.Status -eq 303 -and $intruderVerified.Location -like '*/dashboard*') "status=$($intruderVerified.Status)"

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

Write-Output '--- sign-in: password only by default ---'
$reauth = New-Client
$reauthLoginPage = Invoke-Request '/login' -Session $reauth
$reauthToken = Get-CsrfToken $reauthLoginPage.Content
$passwordOnly = Invoke-Request '/login' -Method POST -Session $reauth -NoRedirect -Body @{
    _csrf    = $reauthToken
    email    = $ownerEmail
    password = $password
    next     = '/dashboard'
}
Check 'password-only sign-in opens the session' ($passwordOnly.Status -eq 303 -and $passwordOnly.Location -like '*/dashboard*') "status=$($passwordOnly.Status) location=$($passwordOnly.Location)"
$reauthDashboard = Invoke-Request '/dashboard' -Session $reauth
Check 'password-only sign-in reaches the dashboard' ($reauthDashboard.Status -eq 200)
$reauthLogoutToken = Get-CsrfToken $reauthDashboard.Content
Invoke-Request '/logout' -Method POST -Session $reauth -NoRedirect -Body @{ _csrf = $reauthLogoutToken } | Out-Null

Write-Output '--- optional two-factor from the staff console ---'
$staff = New-Client
$staffLoginPage = Invoke-Request '/login' -Session $staff
$staffToken = Get-CsrfToken $staffLoginPage.Content
$staffLogin = Invoke-Request '/login' -Method POST -Session $staff -NoRedirect -Body @{
    _csrf    = $staffToken
    email    = 'admin@pddgen.local'
    password = 'AdminPassw0rd!123'
    next     = '/dashboard'
}
if ($staffLogin.Status -eq 303 -and $staffLogin.Location -like '*/verify-otp*') {
    # A previous run left the preference on: finish its challenge first.
    $staffLogin = Complete-Otp -Email 'admin@pddgen.local' -Session $staff
}
Check 'staff account signs in with the password' ($staffLogin.Status -eq 303 -and $staffLogin.Location -like '*/dashboard*') "location=$($staffLogin.Location)"

$staffAdmin = Invoke-Request '/admin' -Session $staff
Check 'staff console shows the security panel' ($staffAdmin.Status -eq 200 -and $staffAdmin.Content -match 'Sign-in security')
$staffAdminToken = Get-CsrfToken $staffAdmin.Content
if ($staffAdmin.Content -match 'Turn off two-factor') {
    # Normalise a leftover setting before testing the switch.
    Invoke-Request '/admin/security/otp' -Method POST -Session $staff -NoRedirect -Body @{ _csrf = $staffAdminToken } | Out-Null
    $staffAdmin = Invoke-Request '/admin' -Session $staff
    $staffAdminToken = Get-CsrfToken $staffAdmin.Content
}
$toggleOn = Invoke-Request '/admin/security/otp' -Method POST -Session $staff -NoRedirect -Body @{ _csrf = $staffAdminToken }
Check 'staff can turn two-factor on' ($toggleOn.Status -eq 303) "status=$($toggleOn.Status)"
$staffAfterToggle = Invoke-Request '/admin' -Session $staff
Check 'security panel shows two-factor on' ($staffAfterToggle.Content -match 'Turn off two-factor')

$staffDashboard = Invoke-Request '/dashboard' -Session $staff
$staffLogoutToken = Get-CsrfToken $staffDashboard.Content
Invoke-Request '/logout' -Method POST -Session $staff -NoRedirect -Body @{ _csrf = $staffLogoutToken } | Out-Null

$staff2 = New-Client
$staffLoginPage2 = Invoke-Request '/login' -Session $staff2
$staffToken2 = Get-CsrfToken $staffLoginPage2.Content
$staffLogin2 = Invoke-Request '/login' -Method POST -Session $staff2 -NoRedirect -Body @{
    _csrf    = $staffToken2
    email    = 'admin@pddgen.local'
    password = 'AdminPassw0rd!123'
    next     = '/dashboard'
}
Check 'two-factor account is challenged after the password' ($staffLogin2.Status -eq 303 -and $staffLogin2.Location -like '*/verify-otp*') "location=$($staffLogin2.Location)"
$staffCodePage = Invoke-Request '/verify-otp' -Session $staff2
$staffBoxes = ([regex]::Matches($staffCodePage.Content, 'name="code\[\]"')).Count
Check 'sign-in challenge shows six digit boxes' ($staffBoxes -eq 6) "boxes=$staffBoxes"
$staffVerified = Complete-Otp -Email 'admin@pddgen.local' -Session $staff2
Check 'the sign-in code completes the two-factor login' ($staffVerified.Status -eq 303 -and $staffVerified.Location -like '*/dashboard*') "location=$($staffVerified.Location)"

$staffAdmin2 = Invoke-Request '/admin' -Session $staff2
$staffAdminToken2 = Get-CsrfToken $staffAdmin2.Content
$toggleOff = Invoke-Request '/admin/security/otp' -Method POST -Session $staff2 -NoRedirect -Body @{ _csrf = $staffAdminToken2 }
Check 'staff can turn two-factor off again' ($toggleOff.Status -eq 303) "status=$($toggleOff.Status)"
$staffDashboard2 = Invoke-Request '/dashboard' -Session $staff2
$staffLogoutToken2 = Get-CsrfToken $staffDashboard2.Content
Invoke-Request '/logout' -Method POST -Session $staff2 -NoRedirect -Body @{ _csrf = $staffLogoutToken2 } | Out-Null

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
Check 'seeded demo account signs in' ($demoLogin.Status -eq 303 -and $demoLogin.Location -like '*/dashboard*') "status=$($demoLogin.Status) location=$($demoLogin.Location)"
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

