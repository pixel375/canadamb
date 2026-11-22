<?php

use App\Http\Controllers\Admin\BusinessReviewController;
use App\Http\Controllers\Admin\CommunityController;
use App\Http\Controllers\Admin\CommunityModerationController;
use App\Http\Controllers\Admin\EditorialController;
use App\Http\Controllers\Admin\JobReviewController;
use App\Http\Controllers\Admin\NetworkController;
use App\Http\Controllers\Admin\PropertyReviewController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TestAccountController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CommunityEventController;
use App\Http\Controllers\CommunityForumController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\ListingEngagementController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\MessagesController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PropertyDraftController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'home'])->name('home');
// Background install requests use the global host and prelaunch checks without consuming session flash.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/app.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
    Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.worker');
    Route::get('/pwa/icon/{size}.png', [PwaController::class, 'icon'])->whereIn('size', ['180', '192', '512'])->name('pwa.icon');
});
Route::view('/photo-credits', 'photo-credits');
Route::view('/about', 'pages.about');
Route::view('/contact', 'pages.contact');
Route::get('/users/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
Route::middleware('auth')->group(function () {
    Route::get('/account/profile', [UserProfileController::class, 'mine']);
    Route::get('/account/settings', [UserProfileController::class, 'settings']);
    Route::post('/account/settings', [UserProfileController::class, 'update'])->middleware('throttle:8,1');
    Route::post('/account/settings/password', [UserProfileController::class, 'changePassword'])->middleware('throttle:5,1')->block(8, 8);
});
Route::get('/communities', [PublicController::class, 'communities']);
Route::get('/sitemap.xml', [PublicController::class, 'sitemap']);
Route::get('/robots.txt', [PublicController::class, 'robots']);
Route::get('/businesses', [BusinessController::class, 'index']);
Route::get('/business-updates', [BusinessController::class, 'updates']);
Route::get('/business-updates/{update}', [BusinessController::class, 'showUpdate'])->whereNumber('update');
Route::get('/businesses/{slug}', [BusinessController::class, 'show']);
Route::get('/news', [NewsController::class, 'index']);
Route::get('/news/{article}', [NewsController::class, 'show'])->whereNumber('article');
Route::get('/community', [CommunityForumController::class, 'index']);
Route::get('/community/{thread}', [CommunityForumController::class, 'thread'])->whereNumber('thread');
Route::get('/community/replies/{reply}', [CommunityForumController::class, 'showReply'])->whereNumber('reply');
Route::get('/events', [CommunityEventController::class, 'index']);
Route::get('/events/{event}', [CommunityEventController::class, 'show'])->whereNumber('event');
Route::get('/jobs', [JobController::class, 'index']);
Route::get('/jobs/{job}', [JobController::class, 'show'])->whereNumber('job');

Route::get('/auth/start', [IdentityController::class, 'start'])->middleware('throttle:20,1')->block(8, 8);
Route::get('/auth/callback', [IdentityController::class, 'callback'])->middleware('throttle:20,1')->block(8, 8);
Route::post('/account/logout', [IdentityController::class, 'logout'])->middleware('auth')->name('network.logout');

Route::get('/marketplace', [MarketplaceController::class, 'index']);
Route::get('/marketplace/{listing}', [MarketplaceController::class, 'show'])->whereNumber('listing');
Route::get('/marketplace/{listing}/photo/{photo}', [ListingEngagementController::class, 'photo'])->whereNumber(['listing', 'photo']);
Route::get('/marketplace/{listing}/thumbnail/{photo}', [ListingEngagementController::class, 'photo'])
    ->whereNumber(['listing', 'photo'])->defaults('thumbnail', true);
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/account/properties', [PropertyDraftController::class, 'index']);
    Route::get('/account/properties/new', [PropertyDraftController::class, 'create']);
    Route::post('/account/properties', [PropertyDraftController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/account/properties/{property}', [PropertyDraftController::class, 'show'])->whereNumber('property');
    Route::get('/account/properties/{property}/edit', [PropertyDraftController::class, 'edit'])->whereNumber('property');
    Route::put('/account/properties/{property}', [PropertyDraftController::class, 'update'])->whereNumber('property')->middleware('throttle:12,1');
    Route::delete('/account/properties/{property}', [PropertyDraftController::class, 'archive'])->whereNumber('property');
    Route::post('/account/properties/{property}/submit', [PropertyDraftController::class, 'submit'])->whereNumber('property')->middleware('throttle:6,1');
    Route::get('/account/properties/{property}/media/{photo}', [PropertyDraftController::class, 'media'])->whereNumber(['property', 'photo']);
    Route::get('/account/businesses/{business}/jobs', [JobController::class, 'business'])->whereNumber('business');
    Route::post('/account/businesses/{business}/jobs', [JobController::class, 'store'])->whereNumber('business')->middleware('throttle:6,1');
    Route::get('/account/businesses/{business}/jobs/{job}/edit', [JobController::class, 'edit'])->whereNumber(['business', 'job']);
    Route::put('/account/businesses/{business}/jobs/{job}', [JobController::class, 'update'])->whereNumber(['business', 'job'])->middleware('throttle:12,1');
    Route::post('/account/businesses/{business}/jobs/{job}/withdraw', [JobController::class, 'withdraw'])->whereNumber(['business', 'job']);
    Route::get('/account/saved-jobs', [JobController::class, 'saved']);
    Route::post('/jobs/{job}/save', [JobController::class, 'save'])->whereNumber('job');
    Route::delete('/jobs/{job}/save', [JobController::class, 'remove'])->whereNumber('job');
    Route::get('/community/new', [CommunityForumController::class, 'create']);
    Route::post('/community', [CommunityForumController::class, 'store'])->middleware('throttle:6,1');
    Route::post('/community/{thread}/replies', [CommunityForumController::class, 'reply'])->whereNumber('thread')->middleware('throttle:12,1');
    Route::post('/community/{thread}/follow', [CommunityForumController::class, 'follow'])->whereNumber('thread')->middleware('throttle:30,1');
    Route::delete('/community/{thread}/follow', [CommunityForumController::class, 'unfollow'])->whereNumber('thread')->middleware('throttle:30,1');
    Route::get('/account/notifications', [NotificationController::class, 'index'])->name('account.notifications');
    Route::post('/account/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:10,1')->name('account.notifications.read-all');
    Route::get('/account/notifications/{storage}/{notification}/open', [NotificationController::class, 'open'])
        ->whereIn('storage', ['unified', 'legacy'])->whereNumber('notification')->name('account.notifications.open');
    Route::post('/account/notifications/{storage}/{notification}/read', [NotificationController::class, 'read'])
        ->whereIn('storage', ['unified', 'legacy'])->whereNumber('notification')->middleware('throttle:40,1')->name('account.notifications.read');
    Route::get('/account/community-notifications', [NotificationController::class, 'index'])->name('account.notifications.legacy');
    Route::post('/account/community-notifications/{notification}/read', [NotificationController::class, 'readLegacy'])
        ->whereNumber('notification')->middleware('throttle:40,1');
    Route::get('/events/new', [CommunityEventController::class, 'create']);
    Route::post('/events', [CommunityEventController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/account/events', [CommunityEventController::class, 'owned']);
    Route::post('/account/events/{event}/cancel', [CommunityEventController::class, 'cancel'])->whereNumber('event');
    Route::get('/marketplace/new', [MarketplaceController::class, 'create']);
    Route::post('/marketplace', [MarketplaceController::class, 'store'])->middleware('throttle:12,1');
    Route::get('/marketplace/{listing}/contact', [MarketplaceController::class, 'contact'])->whereNumber('listing');
    Route::get('/account/listings', [MarketplaceController::class, 'mine']);
    Route::get('/account/listings/{listing}/edit', [MarketplaceController::class, 'edit'])->whereNumber('listing');
    Route::put('/account/listings/{listing}', [MarketplaceController::class, 'update'])->whereNumber('listing')->middleware('throttle:20,1');
    Route::post('/account/listings/{listing}/status', [MarketplaceController::class, 'status'])->whereNumber('listing');
    Route::get('/account/saved', [ListingEngagementController::class, 'saved']);
    Route::post('/marketplace/{listing}/save', [ListingEngagementController::class, 'save'])->whereNumber('listing')->middleware('throttle:24,1');
    Route::delete('/marketplace/{listing}/save', [ListingEngagementController::class, 'remove'])->whereNumber('listing')->middleware('throttle:24,1');
    Route::post('/marketplace/{listing}/report', [ListingEngagementController::class, 'report'])->whereNumber('listing')->middleware('throttle:8,1');
    Route::post('/marketplace/{listing}/message', [MessagesController::class, 'start'])->whereNumber('listing')->middleware('throttle:15,1');
    Route::get('/account/messages', [MessagesController::class, 'index']);
    Route::get('/account/messages/{conversation}', [MessagesController::class, 'show'])->whereNumber('conversation');
    Route::post('/account/messages/{conversation}', [MessagesController::class, 'reply'])->whereNumber('conversation')->middleware('throttle:25,1');
    Route::post('/account/messages/{conversation}/block', [MessagesController::class, 'block'])->whereNumber('conversation');
    Route::delete('/account/messages/{conversation}/block', [MessagesController::class, 'unblock'])->whereNumber('conversation');
    Route::post('/businesses/{slug}/claim', [BusinessController::class, 'claim'])->middleware('throttle:8,1');
    Route::get('/account/businesses', [BusinessController::class, 'mine']);
    Route::get('/account/businesses/new', [BusinessController::class, 'create']);
    Route::post('/account/businesses', [BusinessController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/account/businesses/{business}', [BusinessController::class, 'edit'])->whereNumber('business');
    Route::put('/account/businesses/{business}', [BusinessController::class, 'update'])->whereNumber('business')->middleware('throttle:20,1');
    Route::post('/account/businesses/{business}/updates', [BusinessController::class, 'postUpdate'])->whereNumber('business')->middleware('throttle:8,1');
});

// Stateful routes keep CSRF protection for browser mutations.
Route::prefix('api/v1')->middleware('throttle:api-network')->group(function () {
    Route::get('/locations', [PublicController::class, 'communities']);
    Route::get('/marketplace', [MarketplaceController::class, 'index']);
    Route::post('/marketplace', [MarketplaceController::class, 'store'])->middleware(['auth', 'verified', 'throttle:12,1']);
});

Route::prefix('admin')->middleware(['auth', 'verified', 'can:manage-network'])->group(function () {
    Route::get('/properties', [PropertyReviewController::class, 'index']);
    Route::get('/properties/{property}', [PropertyReviewController::class, 'show'])->whereNumber('property');
    Route::post('/properties/{property}', [PropertyReviewController::class, 'update'])->whereNumber('property');
    Route::get('/properties/{property}/media/{photo}', [PropertyReviewController::class, 'media'])->whereNumber(['property', 'photo']);
    Route::get('/jobs', [JobReviewController::class, 'index']);
    Route::post('/jobs/{job}', [JobReviewController::class, 'update'])->whereNumber('job');
    Route::get('/community', [CommunityModerationController::class, 'index']);
    Route::post('/community/threads/{thread}', [CommunityModerationController::class, 'threads'])->whereNumber('thread');
    Route::post('/community/replies/{reply}', [CommunityModerationController::class, 'replies'])->whereNumber('reply');
    Route::post('/community/events/{event}', [CommunityModerationController::class, 'events'])->whereNumber('event');
    Route::get('/', [NetworkController::class, 'index']);
    Route::get('/audit', [NetworkController::class, 'audit']);
    Route::get('/moderation', [NetworkController::class, 'moderation']);
    Route::get('/business-review', [BusinessReviewController::class, 'index']);
    Route::post('/business-review/{business}', [BusinessReviewController::class, 'profile'])->whereNumber('business');
    Route::post('/business-claims/{claim}', [BusinessReviewController::class, 'claim'])->whereNumber('claim');
    Route::post('/business-updates/{update}', [BusinessReviewController::class, 'update'])->whereNumber('update');
    Route::post('/moderation/{listing}', [NetworkController::class, 'moderate'])->whereNumber('listing');
    Route::post('/moderation/reports/{report}', [NetworkController::class, 'resolveReport'])->whereNumber('report');
    Route::middleware('can:manage-theme')->group(function () {
        Route::get('/test-accounts', [TestAccountController::class, 'index']);
        Route::post('/test-accounts/{account}/verify', [TestAccountController::class, 'verify'])->whereNumber('account');
        Route::get('/settings', [SettingsController::class, 'edit']);
        Route::post('/settings', [SettingsController::class, 'save']);
        Route::post('/settings/publish', [SettingsController::class, 'publish']);
        Route::get('/theme', [ThemeController::class, 'edit']);
        Route::post('/theme', [ThemeController::class, 'draft']);
        Route::post('/theme/publish', [ThemeController::class, 'publish']);
        Route::post('/theme/rollback', [ThemeController::class, 'rollback']);
    });
    Route::view('/photo-credits', 'photo-credits');
    Route::get('/communities', [CommunityController::class, 'index']);
    Route::get('/communities/new', [CommunityController::class, 'create']);
    Route::post('/communities', [CommunityController::class, 'store']);
    Route::get('/communities/{site}', [CommunityController::class, 'edit'])->whereNumber('site');
    Route::put('/communities/{site}', [CommunityController::class, 'update'])->whereNumber('site');
    Route::get('/communities/{site}/preview', [CommunityController::class, 'preview'])->whereNumber('site');
    Route::post('/communities/{site}/status', [CommunityController::class, 'status'])->whereNumber('site');
    Route::post('/communities/{site}/readiness', [CommunityController::class, 'readiness'])->whereNumber('site');
});

Route::prefix('admin/news')->middleware(['auth', 'verified', 'can:manage-news'])->group(function () {
    Route::get('/', [EditorialController::class, 'index']);
    Route::post('/source', [EditorialController::class, 'source'])->middleware('can:manage-network');
    Route::post('/source/{source}', [EditorialController::class, 'sourceStatus'])->whereNumber('source')->middleware('can:manage-network');
    Route::post('/source/{source}/feed', [EditorialController::class, 'sourceFeed'])->whereNumber('source')->middleware('can:manage-network');
    Route::post('/draft', [EditorialController::class, 'draft']);
    Route::post('/{article}/publish', [EditorialController::class, 'publish'])->whereNumber('article');
    Route::post('/{article}/withdraw', [EditorialController::class, 'withdraw'])->whereNumber('article');
});
