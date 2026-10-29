<?php

use App\Http\Controllers\Billiard\BilliardAdminController;
use App\Http\Controllers\Billiard\BilliardAuthController;
use App\Http\Controllers\Billiard\BilliardPlayerController;
use App\Http\Controllers\Billiard\BilliardPublicController;
use Illuminate\Support\Facades\Route;

Route::prefix('billiard')->name('billiard.')->group(function () {
    Route::get('config', [BilliardAuthController::class, 'config'])->middleware('throttle:60,1');

    Route::post('auth/send-code', [BilliardAuthController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::post('auth/verify', [BilliardAuthController::class, 'verify'])->middleware('throttle:15,1');

    // Public
    Route::get('home', [BilliardPublicController::class, 'home'])->middleware('throttle:60,1');
    Route::get('disciplines', [BilliardPublicController::class, 'disciplines'])->middleware('throttle:60,1');
    Route::get('tournaments', [BilliardPublicController::class, 'tournaments'])->middleware('throttle:60,1');
    Route::get('tournaments/{slug}', [BilliardPublicController::class, 'tournament'])->middleware('throttle:60,1');
    Route::get('schedule', [BilliardPublicController::class, 'schedule'])->middleware('throttle:60,1');
    Route::get('results', [BilliardPublicController::class, 'results'])->middleware('throttle:60,1');
    Route::get('ranking', [BilliardPublicController::class, 'ranking'])->middleware('throttle:60,1');
    Route::get('players', [BilliardPublicController::class, 'players'])->middleware('throttle:60,1');
    Route::get('players/{id}', [BilliardPublicController::class, 'player'])->whereNumber('id')->middleware('throttle:60,1');
    Route::get('news', [BilliardPublicController::class, 'news'])->middleware('throttle:60,1');
    Route::get('news/{slug}', [BilliardPublicController::class, 'newsItem'])->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', 'billiard.role'])->group(function () {
        Route::get('me', [BilliardAuthController::class, 'me']);
        Route::patch('me', [BilliardAuthController::class, 'updateProfile']);
        Route::post('logout', [BilliardAuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'billiard.role:player,admin,operator'])->group(function () {
        Route::post('tournaments/{slug}/register', [BilliardPlayerController::class, 'register'])
            ->middleware('throttle:10,1');
        Route::post('registrations/{registrationId}/pay', [BilliardPlayerController::class, 'pay'])
            ->whereNumber('registrationId')
            ->middleware('throttle:10,1');
        Route::get('me/registrations', [BilliardPlayerController::class, 'myRegistrations']);
        Route::get('me/matches', [BilliardPlayerController::class, 'myMatches']);
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'billiard.role:admin,operator'])->group(function () {
        Route::get('dashboard', [BilliardAdminController::class, 'dashboard']);

        Route::get('players', [BilliardAdminController::class, 'players']);
        Route::post('players', [BilliardAdminController::class, 'storePlayer']);
        Route::post('players/bulk', [BilliardAdminController::class, 'bulkStorePlayers'])
            ->middleware('throttle:10,1');
        Route::patch('players/{player}', [BilliardAdminController::class, 'updatePlayer']);

        Route::get('tournaments', [BilliardAdminController::class, 'tournaments']);
        Route::post('tournaments', [BilliardAdminController::class, 'storeTournament'])
            ->middleware('billiard.role:admin');
        Route::patch('tournaments/{tournament}', [BilliardAdminController::class, 'updateTournament'])
            ->middleware('billiard.role:admin');

        Route::get('tournaments/{tournament}/tables', [BilliardAdminController::class, 'tables']);
        Route::post('tournaments/{tournament}/tables', [BilliardAdminController::class, 'storeTable']);
        Route::patch('tables/{table}', [BilliardAdminController::class, 'updateTable']);
        Route::delete('tables/{table}', [BilliardAdminController::class, 'destroyTable']);

        Route::get('registrations', [BilliardAdminController::class, 'registrations']);
        Route::post('tournaments/{tournament}/registrations', [BilliardAdminController::class, 'addTournamentPlayer']);
        Route::post('registrations/{registration}/confirm', [BilliardAdminController::class, 'confirmRegistration']);
        Route::post('registrations/{registration}/reject', [BilliardAdminController::class, 'rejectRegistration']);
        Route::patch('registrations/{registration}/seed', [BilliardAdminController::class, 'updateRegistrationSeed']);

        Route::post('tournaments/{tournament}/draw', [BilliardAdminController::class, 'draw']);
        Route::patch('matches/{match}/schedule', [BilliardAdminController::class, 'scheduleMatch']);
        Route::post('matches/{match}/result', [BilliardAdminController::class, 'recordResult']);

        Route::get('disciplines', [BilliardAdminController::class, 'disciplines']);
        Route::post('disciplines', [BilliardAdminController::class, 'storeDiscipline'])
            ->middleware('billiard.role:admin');
        Route::patch('disciplines/{discipline}', [BilliardAdminController::class, 'updateDiscipline'])
            ->middleware('billiard.role:admin');

        Route::get('ranking-settings', [BilliardAdminController::class, 'rankingSettings'])
            ->middleware('billiard.role:admin');
        Route::put('ranking-settings', [BilliardAdminController::class, 'updateRankingSettings'])
            ->middleware('billiard.role:admin');
        Route::post('ranking/recompute', [BilliardAdminController::class, 'recomputeRanking'])
            ->middleware('billiard.role:admin');

        Route::get('news', [BilliardAdminController::class, 'newsAdmin']);
        Route::post('news', [BilliardAdminController::class, 'storeNews'])
            ->middleware('billiard.role:admin');
        Route::patch('news/{news}', [BilliardAdminController::class, 'updateNews'])
            ->middleware('billiard.role:admin');
        Route::delete('news/{news}', [BilliardAdminController::class, 'destroyNews'])
            ->middleware('billiard.role:admin');
    });
});
