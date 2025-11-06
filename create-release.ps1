# GitHub Release Oluşturma Script'i
# Kullanım: .\create-release.ps1

param(
    [string]$Tag = "v1.0.121",
    [string]$ReleaseTitle = "Version 1.0.121",
    [string]$ReleaseNotes = "Sayfa içeriği güncelleme endpoint'i eklendi",
    [string]$Repo = "mahmut-seker-dev/gsp-connector"
)

# GitHub Personal Access Token'ınızı buraya girin
# Token oluşturmak için: https://github.com/settings/tokens
# Gerekli izinler: repo (Full control of private repositories)
$Token = Read-Host "GitHub Personal Access Token'ınızı girin (gizli olarak)"

if ([string]::IsNullOrEmpty($Token)) {
    Write-Host "Hata: Token girilmedi!" -ForegroundColor Red
    exit 1
}

# Release oluştur
$Body = @{
    tag_name = $Tag
    name = $ReleaseTitle
    body = $ReleaseNotes
    draft = $false
    prerelease = $false
} | ConvertTo-Json

$Headers = @{
    "Authorization" = "token $Token"
    "Accept" = "application/vnd.github.v3+json"
}

$Uri = "https://api.github.com/repos/$Repo/releases"

Write-Host "Release oluşturuluyor: $Tag" -ForegroundColor Yellow

try {
    $Response = Invoke-RestMethod -Uri $Uri -Method Post -Headers $Headers -Body $Body -ContentType "application/json"
    
    Write-Host "`n✅ Release başarıyla oluşturuldu!" -ForegroundColor Green
    Write-Host "Release URL: $($Response.html_url)" -ForegroundColor Cyan
    Write-Host "Tag: $($Response.tag_name)" -ForegroundColor Cyan
    Write-Host "Title: $($Response.name)" -ForegroundColor Cyan
}
catch {
    Write-Host "`n❌ Hata oluştu!" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    
    if ($_.Exception.Response.StatusCode -eq 422) {
        Write-Host "`nNot: Bu tag zaten mevcut olabilir. Mevcut release'i güncellemek için update-release.ps1 script'ini kullanın." -ForegroundColor Yellow
    }
}

