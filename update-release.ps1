# GitHub Release Güncelleme Script'i
# Kullanım: .\update-release.ps1

param(
    [string]$Tag = "v1.0.121",
    [string]$ReleaseTitle = "Version 1.0.121",
    [string]$ReleaseNotes = "Sayfa içeriği güncelleme endpoint'i eklendi",
    [string]$Repo = "mahmut-seker-dev/gsp-connector"
)

# GitHub Personal Access Token'ınızı buraya girin
$Token = Read-Host "GitHub Personal Access Token'ınızı girin (gizli olarak)"

if ([string]::IsNullOrEmpty($Token)) {
    Write-Host "Hata: Token girilmedi!" -ForegroundColor Red
    exit 1
}

$Headers = @{
    "Authorization" = "token $Token"
    "Accept" = "application/vnd.github.v3+json"
}

# Önce mevcut release'i bul
$GetReleaseUri = "https://api.github.com/repos/$Repo/releases/tags/$Tag"

Write-Host "Mevcut release aranıyor: $Tag" -ForegroundColor Yellow

try {
    $ExistingRelease = Invoke-RestMethod -Uri $GetReleaseUri -Method Get -Headers $Headers
    $ReleaseId = $ExistingRelease.id
    
    Write-Host "Release bulundu (ID: $ReleaseId). Güncelleniyor..." -ForegroundColor Yellow
    
    # Release'i güncelle
    $Body = @{
        name = $ReleaseTitle
        body = $ReleaseNotes
        draft = $false
        prerelease = $false
    } | ConvertTo-Json
    
    $UpdateReleaseUri = "https://api.github.com/repos/$Repo/releases/$ReleaseId"
    
    $Response = Invoke-RestMethod -Uri $UpdateReleaseUri -Method Patch -Headers $Headers -Body $Body -ContentType "application/json"
    
    Write-Host "`n✅ Release başarıyla güncellendi!" -ForegroundColor Green
    Write-Host "Release URL: $($Response.html_url)" -ForegroundColor Cyan
    Write-Host "Tag: $($Response.tag_name)" -ForegroundColor Cyan
    Write-Host "Title: $($Response.name)" -ForegroundColor Cyan
}
catch {
    Write-Host "`n❌ Hata oluştu!" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    
    if ($_.Exception.Response.StatusCode -eq 404) {
        Write-Host "`nNot: Bu tag için release bulunamadı. Yeni release oluşturmak için create-release.ps1 script'ini kullanın." -ForegroundColor Yellow
    }
}

