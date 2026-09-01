<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ScrapeRunController;
use App\Http\Controllers\SearchProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
Route::get('/review/{listing}', [ReviewController::class, 'show'])->name('review.show');
Route::post('/review/{listing}/decide', [ReviewController::class, 'decide'])->name('review.decide');
Route::post('/review/{listing}/notes', [ReviewController::class, 'notes'])->name('review.notes');
Route::post('/review/{listing}/undo', [ReviewController::class, 'undo'])->name('review.undo');

Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

Route::get('/criteria', [SearchProfileController::class, 'edit'])->name('criteria.edit');
Route::put('/criteria', [SearchProfileController::class, 'update'])->name('criteria.update');

Route::post('/scrape', [ScrapeRunController::class, 'store'])->name('scrape.store');
Route::get('/scrape/status', [ScrapeRunController::class, 'status'])->name('scrape.status');
