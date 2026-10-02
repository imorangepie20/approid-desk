# [PROJECT_DIRECTORY]
# 예시: C:\Wspace\approid-desk
# [APPLICATION_URL]
# 예시: http://localhost:8000
# [PHP_CONTAINER_SERVICE]
# 예시: laravel.test
param(
    [Parameter()]
    [string] $ProjectDirectory = (Get-Location).Path,

    [Parameter()]
    [string] $ApplicationUrl = 'http://localhost:8000',

    [Parameter()]
    [string] $PhpContainerService = 'laravel.test'
)

$ErrorActionPreference = 'Stop'
$marker = [guid]::NewGuid().ToString('N')
$invitedEmail = "invite-$marker@example.invalid"
$operatorEmail = "operator-$marker@example.invalid"
$companyName = "__invitation_verification_$marker"

function Invoke-TinkerCode {
    param(
        [Parameter(Mandatory)]
        [string] $Code
    )

    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Code))
    $expression = "eval(base64_decode('$encoded'));"
    $output = & docker compose exec -T $PhpContainerService php artisan tinker "--execute=$expression" 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw "Laravel tinker failed: $($output -join [Environment]::NewLine)"
    }

    return $output -join [Environment]::NewLine
}

$setupTemplate = @'
if (app()->environment("production")) {
    throw new RuntimeException("Invitation verification must not run in production.");
}

$operator = new \App\Models\User;
$operator->name = "Invitation verification operator";
$operator->email = "[OPERATOR_EMAIL]";
$operator->role = \App\Enums\UserRole::Operator;
$operator->is_active = true;
$operator->email_verified_at = now();
$operator->password = \Illuminate\Support\Str::random(48);
$operator->save();

$company = \App\Models\Company::create([
    "name" => "[COMPANY_NAME]",
    "status" => \App\Enums\CompanyStatus::Active,
]);

$created = app(\App\Actions\CreateUserInvitation::class)->handle(
    $operator,
    $company,
    "[INVITED_EMAIL]",
    \App\Enums\UserRole::CustomerUser,
    now()->addHour(),
);

echo "RESULT=".base64_encode(json_encode([
    "token" => $created->token,
    "hash_matches" => hash("sha256", $created->token) === $created->invitation->token_hash,
]));
'@

$cleanupTemplate = @'
if (app()->environment("production")) {
    throw new RuntimeException("Invitation verification cleanup must not run in production.");
}

\App\Models\UserInvitation::where("email", "[INVITED_EMAIL]")->delete();
\App\Models\User::where("email", "[OPERATOR_EMAIL]")->delete();
\App\Models\Company::where("name", "[COMPANY_NAME]")->delete();
'@

$setupCode = $setupTemplate.Replace('[OPERATOR_EMAIL]', $operatorEmail)
$setupCode = $setupCode.Replace('[INVITED_EMAIL]', $invitedEmail)
$setupCode = $setupCode.Replace('[COMPANY_NAME]', $companyName)
$cleanupCode = $cleanupTemplate.Replace('[OPERATOR_EMAIL]', $operatorEmail)
$cleanupCode = $cleanupCode.Replace('[INVITED_EMAIL]', $invitedEmail)
$cleanupCode = $cleanupCode.Replace('[COMPANY_NAME]', $companyName)

Push-Location -LiteralPath $ProjectDirectory

try {
    $setupOutput = Invoke-TinkerCode -Code $setupCode
    $resultMatch = [regex]::Match($setupOutput, 'RESULT=([A-Za-z0-9+/=]+)')

    if (-not $resultMatch.Success) {
        throw "The verification invitation was not created: $setupOutput"
    }

    $resultJson = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($resultMatch.Groups[1].Value))
    $result = $resultJson | ConvertFrom-Json

    if (-not $result.hash_matches) {
        throw 'The invitation token hash does not match the generated token.'
    }

    $url = "$($ApplicationUrl.TrimEnd('/'))/invitations/$($result.token)"
    $response = Invoke-WebRequest -UseBasicParsing -Uri $url

    if ($response.StatusCode -ne 200) {
        throw "Expected HTTP 200 from the invitation page, received $($response.StatusCode)."
    }

    if (-not $response.Content.Contains($invitedEmail)) {
        throw 'The invitation page did not show the invited email.'
    }

    if (-not $response.Content.Contains($companyName)) {
        throw 'The invitation page did not show the company name.'
    }

    Write-Output 'Invitation verification passed.'
    Write-Output 'ROUTE=/invitations/[REDACTED]'
    Write-Output 'HTTP_STATUS=200'
    Write-Output 'TOKEN_HASH_MATCH=True'
    Write-Output 'EMAIL_VISIBLE=True'
    Write-Output 'COMPANY_VISIBLE=True'
}
finally {
    try {
        Invoke-TinkerCode -Code $cleanupCode | Out-Null
    }
    finally {
        Pop-Location
    }
}
