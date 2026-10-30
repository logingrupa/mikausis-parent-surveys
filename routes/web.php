<?php

use App\Http\Controllers\QuestionContactController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReportHtmlController;
use App\Http\Controllers\SurveyController;
use App\Http\Middleware\SurveyPrivacyHeaders;
use Illuminate\Support\Facades\Route;

Route::middleware(SurveyPrivacyHeaders::class)->group(function () {
    Route::get('/', [SurveyController::class, 'index'])->name('surveys.index');
    Route::view('/privatums', 'surveys.privacy')->name('surveys.privacy');
    Route::get('/a/{slug}/paldies', [SurveyController::class, 'thanks'])->name('surveys.thanks');
    Route::get('/a/{slug}/kontakti/{question}/paldies', [QuestionContactController::class, 'thanks'])->name('question-contacts.thanks');
    Route::get('/a/{slug}/kontakti/{question}', [QuestionContactController::class, 'show'])->name('question-contacts.show');
    // A survey can send up to 100 independent activity contacts after one main submission.
    Route::post('/a/{slug}/kontakti/{question}', [QuestionContactController::class, 'store'])->middleware('throttle:150,1,question-contacts:')->name('question-contacts.store');
    Route::get('/a/{slug}/session', [SurveyController::class, 'session'])->name('surveys.session');
    Route::get('/a/{slug}/g/{token}/session', [SurveyController::class, 'session'])->name('surveys.family.session');
    Route::post('/a/{slug}/velreiz', [SurveyController::class, 'restart'])->name('surveys.restart');
    Route::post('/a/{slug}/g/{token}/velreiz', [SurveyController::class, 'restart'])->name('surveys.family.restart');
    Route::get('/a/{slug}/g/{token}', [SurveyController::class, 'show'])->name('surveys.family');
    Route::post('/a/{slug}/g/{token}', [SurveyController::class, 'store'])->middleware('throttle:30,1')->name('surveys.family.submit');
    Route::get('/a/{slug}', [SurveyController::class, 'show'])->name('surveys.show');
    Route::post('/a/{slug}', [SurveyController::class, 'store'])->middleware('throttle:30,1')->name('surveys.submit');
    Route::get('/admin/aptaujas/{survey}/eksports', ReportExportController::class)->middleware('auth')->name('reports.export');
});

Route::middleware(['auth', SurveyPrivacyHeaders::class])->group(function () {
    Route::get('/admin/aptaujas/{survey}/html', [ReportHtmlController::class, 'show'])->name('reports.html');
    Route::get('/admin/aptaujas/{survey}/html/lejupielade', [ReportHtmlController::class, 'download'])->name('reports.html.download');
});
