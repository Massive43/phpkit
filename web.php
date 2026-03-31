<?php

use App\Http\Controllers\HerbalmartController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicInformationController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

// ========== GOOGLE VERIFICATION ROUTES ==========
// Letakkan di paling atas agar tidak tertimpa route lain
Route::get('/google{id}.html', function ($id) {
    $filename = "google{$id}.html";
    $path = public_path($filename);
    
    if (file_exists($path)) {
        return response()->file($path);
    }
    
    abort(404);
})->where('id', '.*');

// Alternatif untuk format google-site-verification
Route::get('/google-site-verification/{any}', function ($any) {
    $path = public_path($any);
    
    if (file_exists($path)) {
        return response()->file($path);
    }
    
    abort(404);
})->where('any', '.*');

// Alternatif untuk file verifikasi langsung
Route::get('/{filename}', function ($filename) {
    // Hanya untuk file verifikasi Google
    if (preg_match('/^google[0-9a-zA-Z]+\.html$/', $filename)) {
        $path = public_path($filename);
        if (file_exists($path)) {
            return response()->file($path);
        }
    }
    abort(404);
})->where('filename', '.*\.html');
// ================================================

Route::get('/', function () {
    return view('pages.home');
})->name('home');

Route::get('login', function () {
    return redirect(route('filament.customer.auth.login'));
});

Route::group(['prefix' => 'profile', 'as' => 'profile.'], function () {
    Route::get('history', [ProfileController::class, 'history'])
        ->name('history');
    Route::get('organizational-structure', [ProfileController::class, 'organizationalStructure'])
        ->name('organizational-structure');
    Route::get('task', [ProfileController::class, 'task'])
        ->name('task');
    Route::get('roadmap', [ProfileController::class, 'roadmap'])
        ->name('roadmap');
});

Route::get('public-information', [PublicInformationController::class, 'index'])
    ->name('public-information');

Route::group(['prefix' => 'public-information', 'as' => 'public-information.'], function () {
    Route::get('leaflets', [PublicInformationController::class, 'leafletList'])
        ->name('leaflets');
    Route::get('leaflets/{slug}', [PublicInformationController::class, 'leafletDetail'])
        ->name('leaflets.detail');
    Route::get('leaflets/{slug}/download', [PublicInformationController::class, 'leafletDownload'])
        ->name('leaflets.download');

    Route::get('posters', [PublicInformationController::class, 'posterList'])
        ->name('posters');
    Route::get('posters/{slug}', [PublicInformationController::class, 'posterDetail'])
        ->name('posters.detail');
    Route::get('posters/{slug}/download', [PublicInformationController::class, 'posterDownload'])
        ->name('posters.download');

    Route::get('videos', [PublicInformationController::class, 'videos'])
        ->name('videos');
    Route::get('video/{slug}', [PublicInformationController::class, 'video'])
        ->name('videos.detail');

    Route::get('news', [PublicInformationController::class, 'newsList'])
        ->name('news');
    Route::get('news/{slug}', [PublicInformationController::class, 'newsDetail'])
        ->name('news.detail');

    Route::get('articles', [PublicInformationController::class, 'articleList'])
        ->name('articles');
    Route::get('articles/{slug}', [PublicInformationController::class, 'articleDetail'])
        ->name('articles.detail');

    Route::get('ebooks', [PublicInformationController::class, 'ebooks'])
        ->name('ebooks');
    Route::get('ebooks/{slug}', [PublicInformationController::class, 'ebooksDetail'])
        ->name('ebooks.detail');
    Route::get('ebooks/{slug}/download', [PublicInformationController::class, 'ebooksDownload'])
        ->name('ebooks.download');

    Route::get('regulations', [PublicInformationController::class, 'regulations'])
        ->name('regulations');
    Route::get('regulations/{slug}', [PublicInformationController::class, 'regulationsDetail'])
        ->name('regulations.detail');
    Route::get('regulations/{slug}/download', [PublicInformationController::class, 'regulationsDownload'])
        ->name('regulations.download');

    Route::get('galleries', [PublicInformationController::class, 'galleries'])
        ->name('galleries');
    Route::get('galleries/{slug}', [PublicInformationController::class, 'galleriesDetail'])
        ->name('galleries.detail');

    Route::get('skm', [PublicInformationController::class, 'communitySatisfactionIndexList'])
        ->name('skm');
    Route::get('skm/{slug}', [PublicInformationController::class, 'communitySatisfactionIndexDetail'])
        ->name('skm.detail');
    Route::get('skm/{slug}/download', [PublicInformationController::class, 'communitySatisfactionIndexDownload'])
        ->name('skm.download');

    Route::get('spak', [PublicInformationController::class, 'antiCorruptionAssessmentSurveysList'])
        ->name('spak');
    Route::get('spak/{slug}', [PublicInformationController::class, 'antiCorruptionAssessmentSurveysDetail'])
        ->name('spak.detail');
    Route::get('spak/{slug}/download', [PublicInformationController::class, 'antiCorruptionAssessmentSurveysDownload'])
        ->name('spak.download');

    Route::get('integrity-zones', [PublicInformationController::class, 'integrityZoneList'])
        ->name('integrity-zones');
    Route::get('integrity-zones/{slug}', [PublicInformationController::class, 'integrityZoneDetail'])
        ->name('integrity-zones.detail');
    Route::get('integrity-zones/{slug}/download', [PublicInformationController::class, 'integrityZoneDownload'])
        ->name('integrity-zones.download');
});

Route::resource('services', ServiceController::class)->only(['index', 'show']);

Route::resource('herbalmart', HerbalmartController::class)->only(['index', 'show']);

Route::get('herbalmart/katalog/herbalmart', function () {
    return redirect(route('herbalmart.index'));
})->name('old-herbalmart');