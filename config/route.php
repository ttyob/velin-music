<?php

declare(strict_types=1);

use app\controller\Api\V1\HealthController;
use app\controller\Api\V1\MetricsController;
use app\controller\Api\V1\HomeController;
use app\controller\Api\V1\LyricsController;
use app\controller\Api\V1\ArtworkController;
use app\controller\Api\V1\AuthController;
use app\controller\Api\V1\AppAuthController;
use app\controller\Api\V1\AppCapabilityController;
use app\controller\Api\V1\AccountController;
use app\controller\Api\V1\BookmarkController;
use app\controller\Api\V1\MeController;
use app\controller\Api\V1\PersonalAccessTokenController;
use app\controller\Api\V1\RealtimeController;
use app\controller\Api\V1\SetupController;
use app\controller\Api\V1\MediaController;
use app\controller\Api\V1\PlayQueueController;
use app\controller\Api\V1\PreferenceController;
use app\controller\Api\V1\PlaybackEventController;
use app\controller\Api\V1\ListeningTimeController;
use app\controller\Api\V1\PlaybackLeaseController;
use app\controller\Api\V1\PlayerProfileController;
use app\controller\Api\V1\DlnaController;
use app\controller\Api\V1\AirplayController;
use app\controller\Api\V1\ExternalPlaybackTicketController;
use app\controller\Api\V1\DownloadController;
use app\controller\Api\V1\PlaylistController;
use app\controller\Api\V1\RecommendationController;
use app\controller\Api\V1\Admin\RecommendationController as AdminRecommendationController;
use app\controller\Api\V1\Admin\AdminPlaylistController;
use app\controller\Api\V1\SmartPlaylistController;
use app\controller\Api\V1\RadioController;
use app\controller\Api\V1\SearchController;
use app\controller\Api\V1\StreamController;
use app\controller\Api\V1\AvatarController;
use app\controller\Api\V1\ThemeController;
use app\controller\Api\V1\Admin\UserController;
use app\controller\Api\V1\Admin\NotificationController;
use app\controller\Api\V1\Admin\SystemErrorController;
use app\controller\Api\V1\Admin\LibraryController;
use app\controller\Api\V1\Admin\OneDriveAuthorizationController;
use app\controller\Api\V1\Admin\GoogleDriveAuthorizationController;
use app\controller\Api\V1\Admin\LyricsAdminController;
use app\controller\Api\V1\Admin\MetadataEntityController;
use app\controller\Api\V1\Admin\DuplicateMediaController;
use app\controller\Api\V1\Admin\MediaMetadataController;
use app\controller\Api\V1\Admin\MediaTrashController;
use app\controller\Api\V1\Admin\MetadataSyncScrapeController;
use app\controller\Api\V1\Admin\LyricsWritebackController;
use app\controller\Api\V1\Admin\LyricsAudioTagWritebackController;
use app\controller\Api\V1\Admin\AudioTagWritebackController;
use app\controller\Api\V1\Admin\ArtworkAdminController;
use app\controller\Api\V1\Admin\JobController;
use app\controller\Api\V1\Admin\ScanController;
use app\controller\Api\V1\Admin\NetworkProxyController;
use app\controller\Api\V1\Admin\SystemSettingsController;
use app\controller\Api\V1\Admin\SystemHealthController;
use app\controller\Api\V1\Admin\ResourcePluginController;
use app\controller\Api\V1\Admin\OverviewController;
use app\controller\Api\V1\Admin\PrivacyController;
use app\controller\Api\V1\Admin\UploadController as AdminUploadController;
use app\controller\SubsonicController;
use app\controller\SubsonicArtworkController;
use app\controller\DlnaStreamController;
use app\controller\ExternalPlaybackStreamController;
use app\middleware\VerifyCsrfToken;
use Webman\Route;

/*
 * Explicit API routes are used instead of Webman's default controller routing. This
 * prevents newly added public controller methods from becoming reachable accidentally
 * and gives every API operation a stable, reviewable URL contract (API-001).
 */
Route::get('/api/v1/health', [HealthController::class, 'show']);
Route::get('/metrics', [MetricsController::class, 'show']);
Route::get('/api/v1/themes', [ThemeController::class, 'index']);
Route::get('/api/v1/setup', [SetupController::class, 'show']);
Route::post('/api/v1/setup', [SetupController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/setup/library', [SetupController::class, 'libraryShow']);
Route::post('/api/v1/setup/library', [SetupController::class, 'configureLibrary'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/auth/csrf', [AuthController::class, 'csrf']);
Route::get('/api/v1/auth/captcha', [AuthController::class, 'captcha']);
Route::get('/api/v1/app/capabilities', [AppCapabilityController::class, 'show']);
Route::post('/api/v1/app/negotiate', [AppCapabilityController::class, 'negotiate'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/auth/app/authorize', [AppAuthController::class, 'authorize']);
Route::post('/api/v1/auth/app/token', [AppAuthController::class, 'token']);
Route::post('/api/v1/auth/app/logout', [AppAuthController::class, 'logout'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/auth/proxy', [AuthController::class, 'proxyStatus']);
Route::post('/api/v1/auth/proxy', [AuthController::class, 'proxyLogin'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me', [MeController::class, 'show']);
Route::patch('/api/v1/me/profile', [AccountController::class, 'updateProfile'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/me/password', [AccountController::class, 'changePassword'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me/sessions', [AccountController::class, 'sessions']);
Route::get('/api/v1/me/app-sessions', [AppAuthController::class, 'sessions']);
Route::delete('/api/v1/me/app-sessions/{familyId}', [AppAuthController::class, 'revokeSession'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/me/sessions/{sessionId}', [AccountController::class, 'revokeSession'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/tokens', [PersonalAccessTokenController::class, 'index']);
Route::post('/api/v1/tokens', [PersonalAccessTokenController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/tokens/{tokenId}', [PersonalAccessTokenController::class, 'revoke'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/events', [RealtimeController::class, 'stream']);
Route::patch('/api/v1/me/preferences', [MeController::class, 'updatePreferences'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/me/preferences/theme', [MeController::class, 'updateTheme'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/me/preferences/motion', [MeController::class, 'updateMotionPreference'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me/avatar', [AvatarController::class, 'show']);
Route::put('/api/v1/me/avatar', [AvatarController::class, 'upload'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/me/avatar', [AvatarController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me/playback-preferences', [MeController::class, 'playbackPreferences']);
Route::patch('/api/v1/me/playback-preferences', [MeController::class, 'updatePlaybackPreferences'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/home', [HomeController::class, 'show']);
Route::get('/api/v1/songs', [MediaController::class, 'songs']);
// 固定发现路径必须先于 `{songId}` 注册，避免把资源名误解释为歌曲 ID。
Route::get('/api/v1/songs/frequently-played', [MediaController::class, 'frequentlyPlayedSongs']);
Route::get('/api/v1/songs/random-recommendations', [MediaController::class, 'randomRecommendationSongs']);
Route::get('/api/v1/recommendations/daily', [RecommendationController::class, 'daily']);
Route::get('/api/v1/recommendations/playlists', [RecommendationController::class, 'playlists']);
Route::post('/api/v1/recommendations/missing-song-detail', [RecommendationController::class, 'missingSongDetail']);
Route::get('/api/v1/songs/{songId}/similar', [RecommendationController::class, 'similarSongs']);
Route::get('/api/v1/songs/{songId}', [MediaController::class, 'song']);
Route::get('/api/v1/albums', [MediaController::class, 'albums']);
Route::get('/api/v1/albums/{albumId}', [MediaController::class, 'album']);
Route::get('/api/v1/artists', [MediaController::class, 'artists']);
Route::get('/api/v1/artists/{artistId}/similar', [RecommendationController::class, 'similarArtists']);
Route::get('/api/v1/artists/{artistId}', [MediaController::class, 'artist']);
Route::get('/api/v1/genres', [MediaController::class, 'genres']);
Route::get('/api/v1/years', [MediaController::class, 'years']);
Route::get('/api/v1/search', [SearchController::class, 'index']);
Route::get('/api/v1/songs/{songId}/lyrics', [LyricsController::class, 'show']);
Route::get('/api/v1/albums/{albumId}/cover', [ArtworkController::class, 'album']);
Route::get('/api/v1/songs/{songId}/cover', [ArtworkController::class, 'song']);
Route::get('/api/v1/artists/{artistId}/image', [ArtworkController::class, 'artist']);
Route::get('/api/v1/streams/{songId}', [StreamController::class, 'show']);
Route::get('/api/v1/download-manifests/{type}/{id}', [DownloadController::class, 'manifest']);
Route::add(['GET', 'HEAD'], '/api/v1/downloads/{songId}', [DownloadController::class, 'file']);
Route::post('/api/v1/streams/{songId}/external-prepare', [ExternalPlaybackTicketController::class, 'prepare'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/streams/{songId}/external-tickets', [ExternalPlaybackTicketController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/play-queues/current', [PlayQueueController::class, 'show']);
Route::put('/api/v1/play-queues/current', [PlayQueueController::class, 'replace'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/favorites', [PreferenceController::class, 'index']);
Route::put('/api/v1/favorites/{type}/{mediaId}', [PreferenceController::class, 'favorite'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/favorites/{type}/{mediaId}', [PreferenceController::class, 'unfavorite'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playback-events', [PlaybackEventController::class, 'record'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me/listening-time', [ListeningTimeController::class, 'show']);
Route::post('/api/v1/playback-leases', [PlaybackLeaseController::class, 'acquire'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/playback-leases/{leaseId}', [PlaybackLeaseController::class, 'heartbeat'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/playback-leases/{leaseId}', [PlaybackLeaseController::class, 'release'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/me/player-profiles', [PlayerProfileController::class, 'index']);
Route::patch('/api/v1/me/player-profiles/{profileId}', [PlayerProfileController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/me/player-profiles/{profileId}', [PlayerProfileController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/dlna/session', [DlnaController::class, 'session']);
Route::get('/api/v1/dlna/devices', [DlnaController::class, 'devices']);
Route::post('/api/v1/dlna/prepare', [DlnaController::class, 'prepare'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/dlna/play', [DlnaController::class, 'play'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/dlna/control', [DlnaController::class, 'control'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/airplay/devices', [AirplayController::class, 'devices']);
Route::post('/api/v1/airplay/play', [AirplayController::class, 'play'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/airplay/control', [AirplayController::class, 'control'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/history', [PlaybackEventController::class, 'history']);
Route::delete('/api/v1/history/{playbackId}', [PlaybackEventController::class, 'clearOne'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/history', [PlaybackEventController::class, 'clear'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/bookmarks', [BookmarkController::class, 'index']);
Route::get('/api/v1/bookmarks/{songId}', [BookmarkController::class, 'show']);
Route::put('/api/v1/bookmarks/{songId}', [BookmarkController::class, 'save'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/bookmarks/{songId}', [BookmarkController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/radios', [RadioController::class, 'index']);
Route::get('/api/v1/radios/{stationId}', [RadioController::class, 'show']);
Route::post('/api/v1/radios', [RadioController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/radios/{stationId}', [RadioController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/radios/{stationId}', [RadioController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/radios/{stationId}/favorite', [RadioController::class, 'favorite'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/radios/{stationId}/favorite', [RadioController::class, 'unfavorite'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/dlna/v1/streams/{token}', [DlnaStreamController::class, 'show']);
Route::add(['GET', 'HEAD'], '/external/v1/streams/{ticket}', [ExternalPlaybackStreamController::class, 'show']);
Route::add(['GET', 'HEAD'], '/subsonic/v1/artist-artworks/{ticket}', [SubsonicArtworkController::class, 'show']);
Route::get('/api/v1/playlists', [PlaylistController::class, 'index']);
Route::get('/api/v1/system-playlists', [PlaylistController::class, 'systemIndex']);
Route::post('/api/v1/smart-playlists', [SmartPlaylistController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/smart-playlists/preview', [SmartPlaylistController::class, 'previewDraft'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists', [PlaylistController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists/import', [PlaylistController::class, 'import'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/playlists/{playlistId}/cover', [PlaylistController::class, 'cover']);
Route::put('/api/v1/playlists/{playlistId}/cover', [PlaylistController::class, 'uploadCover'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/playlists/{playlistId}/cover', [PlaylistController::class, 'deleteCover'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/playlists/{playlistId}/export.m3u8', [PlaylistController::class, 'export']);
Route::get('/api/v1/playlists/{playlistId}/m3u-sync', [PlaylistController::class, 'm3uSyncShow']);
Route::put('/api/v1/playlists/{playlistId}/m3u-sync', [PlaylistController::class, 'm3uSyncBind'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists/{playlistId}/m3u-sync/run', [PlaylistController::class, 'm3uSyncRun'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists/{playlistId}/m3u-sync/resolve', [PlaylistController::class, 'm3uSyncResolve'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/playlists/{playlistId}/m3u-sync', [PlaylistController::class, 'm3uSyncUnbind'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/playlists/{playlistId}/smart-rules', [SmartPlaylistController::class, 'showRules']);
Route::put('/api/v1/playlists/{playlistId}/smart-rules', [SmartPlaylistController::class, 'updateRules'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists/{playlistId}/smart-rules/preview', [SmartPlaylistController::class, 'previewRules'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/playlists/{playlistId}', [PlaylistController::class, 'show']);
Route::patch('/api/v1/playlists/{playlistId}', [PlaylistController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/playlists/{playlistId}', [PlaylistController::class, 'replaceAll'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/playlists/{playlistId}/items', [PlaylistController::class, 'replaceItems'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/playlists/{playlistId}/entries', [PlaylistController::class, 'addManualEntry'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/playlists/{playlistId}', [PlaylistController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/users', [UserController::class, 'index']);
Route::get('/api/v1/admin/notifications', [NotificationController::class, 'index']);
Route::get('/api/v1/admin/notifications/counts', [NotificationController::class, 'counts']);
Route::post('/api/v1/admin/notifications/read-all', [NotificationController::class, 'readAll'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/notifications/clear', [NotificationController::class, 'clear'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/notifications/{notificationId}/read', [NotificationController::class, 'read'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/notification-preferences/{type}', [NotificationController::class, 'preference'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-errors', [SystemErrorController::class, 'index']);
Route::get('/api/v1/admin/system-errors/{errorId}', [SystemErrorController::class, 'show']);
Route::patch('/api/v1/admin/system-errors/{errorId}', [SystemErrorController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-health', [SystemHealthController::class, 'show']);
Route::get('/api/v1/admin/overview', [OverviewController::class, 'show']);
Route::get('/api/v1/admin/privacy/now-playing', [PrivacyController::class, 'nowPlaying']);
Route::get('/api/v1/admin/privacy/users/{userId}/history', [PrivacyController::class, 'history']);
Route::post('/api/v1/admin/users', [UserController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/users/bulk-preview', [UserController::class, 'bulkPreview'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/users/bulk-apply', [UserController::class, 'bulkApply'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/users/{userId}', [UserController::class, 'show']);
Route::put('/api/v1/admin/users/{userId}', [UserController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/users/{userId}', [UserController::class, 'changeStatus'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/users/{userId}/role', [UserController::class, 'changeRole'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/libraries', [LibraryController::class, 'index']);
Route::get('/api/v1/admin/jobs', [JobController::class, 'index']);
Route::get('/api/v1/admin/jobs/{jobId}', [JobController::class, 'show']);
Route::get('/api/v1/admin/jobs/{jobId}/report', [JobController::class, 'report']);
Route::post('/api/v1/admin/jobs/{jobId}/retry', [JobController::class, 'retry'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/jobs/{jobId}/cancel', [JobController::class, 'cancel'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/jobs', [JobController::class, 'clearAll'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/jobs/{jobId}', [JobController::class, 'clear'])
    ->middleware(VerifyCsrfToken::class);
// 插件由管理员上传完整受信 PHP 包；核心只负责生命周期、目录投影和自有页面资源鉴权。
Route::get('/api/v1/admin/resource-plugins', [ResourcePluginController::class, 'index']);
Route::get('/api/v1/admin/resource-plugin-store/config', [ResourcePluginController::class, 'storeConfiguration']);
Route::put('/api/v1/admin/resource-plugin-store/config', [ResourcePluginController::class, 'updateStoreConfiguration'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugin-store/catalog', [ResourcePluginController::class, 'storeCatalog']);
Route::post('/api/v1/admin/resource-plugin-store/plugins/{pluginKey}', [ResourcePluginController::class, 'installStorePlugin'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/backups', [ResourcePluginController::class, 'backups']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/backups/purge', [ResourcePluginController::class, 'purgeBackups'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/php-resource-plugins', [ResourcePluginController::class, 'installPhp'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/php-resource-plugins/{pluginKey}/state', [ResourcePluginController::class, 'updatePhpState'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/php-resource-plugins/{pluginKey}', [ResourcePluginController::class, 'uninstallPhp'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/admin-actions', [ResourcePluginController::class, 'adminActions']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/admin-actions/{actionKey}', [ResourcePluginController::class, 'runAdminAction'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/page/{assetPath:.+}', [ResourcePluginController::class, 'pageAsset']);
// 插件业务通过核心固定路由动态分发，首次安装无需重启 Webman 来注册插件私有路由。
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/search/status', [ResourcePluginController::class, 'searchStatus']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/search/config', [ResourcePluginController::class, 'searchConfiguration']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/search/providers/health', [ResourcePluginController::class, 'providerHealth']);
Route::put('/api/v1/admin/resource-plugins/{pluginKey}/search/config', [ResourcePluginController::class, 'updateSearchConfiguration'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/search', [ResourcePluginController::class, 'search'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/search/providers/{providerKey}/login', [ResourcePluginController::class, 'startProviderLogin'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/search/providers/{providerKey}/test', [ResourcePluginController::class, 'testProvider'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/search/providers/{providerKey}/login/check', [ResourcePluginController::class, 'checkProviderLogin'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/download/status', [ResourcePluginController::class, 'downloadStatus']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/download/config', [ResourcePluginController::class, 'downloadConfiguration']);
Route::put('/api/v1/admin/resource-plugins/{pluginKey}/download/config', [ResourcePluginController::class, 'updateDownloadConfiguration'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/download/options', [ResourcePluginController::class, 'downloadOptions']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions', [ResourcePluginController::class, 'subscriptions']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions', [ResourcePluginController::class, 'createSubscription'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions/{subscriptionId}', [ResourcePluginController::class, 'updateSubscription'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions/{subscriptionId}', [ResourcePluginController::class, 'deleteSubscription'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions/{subscriptionId}/run', [ResourcePluginController::class, 'runSubscription'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions/{subscriptionId}/runs', [ResourcePluginController::class, 'subscriptionRuns']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/subscriptions/{subscriptionId}/trial', [ResourcePluginController::class, 'trialSubscription'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/downloads', [ResourcePluginController::class, 'createDownload'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/completion', [ResourcePluginController::class, 'completeMusic'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/completion/{taskId}', [ResourcePluginController::class, 'musicCompletionStatus']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/sources', [ResourcePluginController::class, 'metadataSources']);
Route::put('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/sources/{sourceKey}', [ResourcePluginController::class, 'updateMetadataSource'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/tasks', [ResourcePluginController::class, 'metadataTasks']);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/tasks', [ResourcePluginController::class, 'clearMetadataTasks'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/tasks/{taskId}', [ResourcePluginController::class, 'metadataTask']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/tasks/{taskId}/log', [ResourcePluginController::class, 'metadataTaskLog']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/tasks/{taskId}/retry', [ResourcePluginController::class, 'retryMetadataTask'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/library-files', [ResourcePluginController::class, 'libraryFiles']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/library-files/{songId}', [ResourcePluginController::class, 'libraryFile']);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/artist-database', [ResourcePluginController::class, 'artistDatabaseStatus']);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/artist-database/uploads', [ResourcePluginController::class, 'artistDatabaseUpload'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/artist-database/uploads/{uploadId}/chunks/{offset}', [ResourcePluginController::class, 'artistDatabaseChunk'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/artist-database/uploads/{uploadId}/complete', [ResourcePluginController::class, 'artistDatabaseComplete'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/metadata-scrape/artist-database/uploads/{uploadId}', [ResourcePluginController::class, 'artistDatabaseCancel'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/downloads', [ResourcePluginController::class, 'downloadJobs']);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/downloads', [ResourcePluginController::class, 'clearDownloadJobs'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/resource-plugins/{pluginKey}/downloads/logs', [ResourcePluginController::class, 'downloadDiagnosticLogs']);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/downloads/logs', [ResourcePluginController::class, 'clearDownloadDiagnosticLogs'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/downloads/logs/{logId}', [ResourcePluginController::class, 'clearDownloadDiagnosticLog'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/resource-plugins/{pluginKey}/downloads/{jobId}/retry', [ResourcePluginController::class, 'retryDownload'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/resource-plugins/{pluginKey}/downloads/{jobId}', [ResourcePluginController::class, 'clearDownloadJob'])
    ->middleware(VerifyCsrfToken::class);
// 上传只属于后台存储管理；目标仍由服务端按管理员实时 manage 授权解析，路由不接受物理路径。
Route::get('/api/v1/admin/uploads/options', [AdminUploadController::class, 'options']);
Route::get('/api/v1/admin/uploads', [AdminUploadController::class, 'index']);
Route::post('/api/v1/admin/uploads', [AdminUploadController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/uploads/{sessionId}', [AdminUploadController::class, 'show']);
Route::put('/api/v1/admin/uploads/{sessionId}/files/{fileId}/chunks/{byteOffset}', [AdminUploadController::class, 'chunk'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/uploads/{sessionId}/publish', [AdminUploadController::class, 'publish'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/uploads/{sessionId}/cancel', [AdminUploadController::class, 'cancel'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/libraries', [LibraryController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/libraries/{libraryId}/directories/browse', [LibraryController::class, 'browseDirectory'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/onedrive/device-authorizations', [OneDriveAuthorizationController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/onedrive/device-authorizations/{authorizationId}/poll', [OneDriveAuthorizationController::class, 'poll'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/google-drive/authorizations', [GoogleDriveAuthorizationController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/google-drive/authorizations/{authorizationId}/status', [GoogleDriveAuthorizationController::class, 'status'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/google-drive/oauth/callback', [GoogleDriveAuthorizationController::class, 'callback']);
Route::patch('/api/v1/admin/libraries/{libraryId}', [LibraryController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/libraries/{libraryId}', [LibraryController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/libraries/{libraryId}/grants', [LibraryController::class, 'grants']);
Route::patch('/api/v1/admin/libraries/{libraryId}/grants', [LibraryController::class, 'replaceGrants'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/songs/{songId}/lyrics/writeback-plans', [LyricsWritebackController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/songs/{songId}/lyrics/audio-tag-writeback-plans', [LyricsAudioTagWritebackController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/lyrics/audio-tag-writeback-batches', [LyricsAudioTagWritebackController::class, 'createBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/lyrics/writeback-batches', [LyricsWritebackController::class, 'createBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/songs/{songId}/tag-writeback-plans', [AudioTagWritebackController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/audio-tag-writeback-batches', [AudioTagWritebackController::class, 'createBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media', [MediaMetadataController::class, 'index']);
Route::get('/api/v1/admin/media/lyrics', [LyricsAdminController::class, 'index']);
Route::get('/api/v1/admin/media/entities', [MetadataEntityController::class, 'entities']);
Route::get('/api/v1/admin/media/duplicates', [DuplicateMediaController::class, 'index']);
Route::post('/api/v1/admin/media/duplicates/{groupId}/keep-all', [DuplicateMediaController::class, 'keepAll'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/duplicates/{groupId}/merge', [DuplicateMediaController::class, 'merge'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/duplicate-merges/{operationId}/rollback', [DuplicateMediaController::class, 'rollback'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/sync-scrape-jobs', [MetadataSyncScrapeController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/sync-scrape-jobs/awaiting-confirmations/ignore-all', [MetadataSyncScrapeController::class, 'ignoreAllAwaiting'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media/scrape-history', [MetadataSyncScrapeController::class, 'history']);
Route::post('/api/v1/admin/media/scrape-history/requeue', [MetadataSyncScrapeController::class, 'requeueHistory'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/scrape-history/clear-queue', [MetadataSyncScrapeController::class, 'clearHistoryQueue'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/scrape-history/clear-records', [MetadataSyncScrapeController::class, 'clearHistoryRecords'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media/sync-scrape-jobs/{jobId}', [MetadataSyncScrapeController::class, 'show']);
Route::post('/api/v1/admin/media/sync-scrape-jobs/{jobId}/confirm', [MetadataSyncScrapeController::class, 'confirm'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/sync-scrape-targets/{targetId}/apply-stored-candidate', [MetadataSyncScrapeController::class, 'applyStoredCandidate'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/artworks/{type}/{entityId}', [ArtworkAdminController::class, 'show']);
Route::post('/api/v1/admin/artworks/{type}/{entityId}/candidates', [ArtworkAdminController::class, 'upload'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/artworks/{type}/{entityId}/selection', [ArtworkAdminController::class, 'select'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/artworks/{type}/{entityId}/selection', [ArtworkAdminController::class, 'restore'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/artworks/{type}/{entityId}/provider-searches', [ArtworkAdminController::class, 'createProviderSearch'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/artworks/{type}/{entityId}/provider-searches/latest', [ArtworkAdminController::class, 'latestProviderSearch']);
Route::get('/api/v1/admin/artworks/{type}/{entityId}/provider-searches/{searchJobId}', [ArtworkAdminController::class, 'providerSearch']);
Route::get('/api/v1/admin/artworks/{type}/{entityId}/provider-searches/{searchJobId}/candidates/{candidateId}/preview', [ArtworkAdminController::class, 'providerPreview']);
Route::post('/api/v1/admin/artworks/{type}/{entityId}/provider-searches/{searchJobId}/candidates/{candidateId}/imports', [ArtworkAdminController::class, 'createProviderImport'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/artworks/{type}/{entityId}/provider-imports/{jobId}', [ArtworkAdminController::class, 'providerImport']);
Route::get('/api/v1/admin/artworks/candidates/{candidateId}/image', [ArtworkAdminController::class, 'image']);
Route::post('/api/v1/admin/media/batch-plans', [MediaMetadataController::class, 'createBatchPlan'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media/batch-plans/{planId}', [MediaMetadataController::class, 'showBatchPlan']);
Route::post('/api/v1/admin/media/batch-plans/{planId}/confirm', [MediaMetadataController::class, 'confirmBatchPlan'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/media/batch-plans/{planId}/retry-failures', [MediaMetadataController::class, 'retryBatchFailures'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/media/song/{songId}', [MediaMetadataController::class, 'deleteSong'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media-trash', [MediaTrashController::class, 'index']);
Route::post('/api/v1/admin/media-trash/{deletionId}/restore', [MediaTrashController::class, 'restore'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/media-trash/{deletionId}', [MediaTrashController::class, 'purge'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/media/{type}/{mediaId}', [MediaMetadataController::class, 'show']);
Route::put('/api/v1/admin/media/{type}/{mediaId}/overrides', [MediaMetadataController::class, 'save'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/media/{type}/{mediaId}/overrides', [MediaMetadataController::class, 'clear'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/metadata/entities/{type}/{entityId}/songs', [MetadataEntityController::class, 'songs']);
Route::delete('/api/v1/admin/metadata/entities/{type}/{entityId}', [MetadataEntityController::class, 'deleteEntity'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/metadata/merge-preview', [MetadataEntityController::class, 'mergePreview'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/metadata/merge', [MetadataEntityController::class, 'merge'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/metadata/split-preview', [MetadataEntityController::class, 'splitPreview'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/metadata/split', [MetadataEntityController::class, 'split'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/metadata/operations', [MetadataEntityController::class, 'operations']);
Route::get('/api/v1/admin/metadata/operations/{operationId}', [MetadataEntityController::class, 'operation']);
Route::post('/api/v1/admin/metadata/operations/{operationId}/rollback', [MetadataEntityController::class, 'rollback'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/lyrics/songs/{songId}', [LyricsAdminController::class, 'show']);
Route::put('/api/v1/admin/lyrics/songs/{songId}/manual', [LyricsAdminController::class, 'saveManual'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/lyrics/songs/{songId}/manual/{lyricId}', [LyricsAdminController::class, 'clearManual'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/lyrics/songs/{songId}', [LyricsAdminController::class, 'clearAll'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/lyrics/songs/{songId}/primary', [LyricsAdminController::class, 'setPrimary'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/lyrics/songs/{songId}/primary', [LyricsAdminController::class, 'clearPrimary'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/lyrics/writeback-plans/{planId}', [LyricsWritebackController::class, 'show']);
Route::get('/api/v1/admin/lyrics/audio-tag-writeback-plans/{planId}', [LyricsAudioTagWritebackController::class, 'show']);
Route::post('/api/v1/admin/lyrics/audio-tag-writeback-plans/{planId}/confirm', [LyricsAudioTagWritebackController::class, 'confirm'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/lyrics/audio-tag-writeback-batches/{batchId}', [LyricsAudioTagWritebackController::class, 'showBatch']);
Route::post('/api/v1/admin/lyrics/audio-tag-writeback-batches/{batchId}/confirm', [LyricsAudioTagWritebackController::class, 'confirmBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/lyrics/writeback-plans/{planId}/confirm', [LyricsWritebackController::class, 'confirm'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/lyrics/writeback-batches/{batchId}', [LyricsWritebackController::class, 'showBatch']);
Route::post('/api/v1/admin/lyrics/writeback-batches/{batchId}/confirm', [LyricsWritebackController::class, 'confirmBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/audio-tag-writeback-plans/{planId}', [AudioTagWritebackController::class, 'show']);
Route::post('/api/v1/admin/audio-tag-writeback-plans/{planId}/confirm', [AudioTagWritebackController::class, 'confirm'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/audio-tag-writeback-batches/{batchId}', [AudioTagWritebackController::class, 'showBatch']);
Route::post('/api/v1/admin/audio-tag-writeback-batches/{batchId}/confirm', [AudioTagWritebackController::class, 'confirmBatch'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/audio-tag-writeback-batches/{batchId}/retry-failures', [AudioTagWritebackController::class, 'retryBatchFailures'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/scans/{jobId}/files', [ScanController::class, 'files']);
Route::get('/api/v1/admin/scans/{jobId}', [ScanController::class, 'show']);
Route::post('/api/v1/admin/libraries/{libraryId}/scans', [ScanController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/scans/{jobId}/cancel', [ScanController::class, 'cancel'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/network-proxies', [NetworkProxyController::class, 'index']);
Route::post('/api/v1/admin/network-proxies', [NetworkProxyController::class, 'create'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/network-proxies/{proxyId}', [NetworkProxyController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/network-proxies/{proxyId}', [NetworkProxyController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/basic', [SystemSettingsController::class, 'show']);
Route::put('/api/v1/admin/system-settings/basic', [SystemSettingsController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/limits', [SystemSettingsController::class, 'showLimits']);
Route::put('/api/v1/admin/system-settings/limits', [SystemSettingsController::class, 'updateLimits'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/dlna', [SystemSettingsController::class, 'showDlna']);
Route::put('/api/v1/admin/system-settings/dlna', [SystemSettingsController::class, 'updateDlna'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/airplay', [SystemSettingsController::class, 'showAirplay']);
Route::put('/api/v1/admin/system-settings/airplay', [SystemSettingsController::class, 'updateAirplay'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/proxy', [SystemSettingsController::class, 'showProxy']);
Route::put('/api/v1/admin/system-settings/proxy', [SystemSettingsController::class, 'updateProxy'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-settings/metadata-scrape-policy', [SystemSettingsController::class, 'showMetadataScrapePolicy']);
Route::put('/api/v1/admin/system-settings/metadata-scrape-policy', [SystemSettingsController::class, 'updateMetadataScrapePolicy'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/system-maintenance', [SystemSettingsController::class, 'showMaintenance']);
Route::post('/api/v1/admin/system-maintenance/cleanup', [SystemSettingsController::class, 'cleanupMaintenance'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/playlists', [AdminPlaylistController::class, 'index']);
Route::post('/api/v1/admin/playlists/import', [AdminPlaylistController::class, 'import'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/playlists/lastfm/presets', [AdminPlaylistController::class, 'lastfmPresets']);
Route::post('/api/v1/admin/playlists/lastfm', [AdminPlaylistController::class, 'createLastfm'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/playlists/public/catalog', [AdminPlaylistController::class, 'publicCatalog']);
Route::post('/api/v1/admin/playlists/public', [AdminPlaylistController::class, 'createPublic'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/playlists/{playlistId}', [AdminPlaylistController::class, 'show']);
Route::patch('/api/v1/admin/playlists/{playlistId}', [AdminPlaylistController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/playlists/{playlistId}', [AdminPlaylistController::class, 'delete'])
    ->middleware(VerifyCsrfToken::class);
Route::put('/api/v1/admin/playlists/{playlistId}/cover', [AdminPlaylistController::class, 'uploadCover'])
    ->middleware(VerifyCsrfToken::class);
Route::delete('/api/v1/admin/playlists/{playlistId}/cover', [AdminPlaylistController::class, 'deleteCover'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/playlists/{playlistId}/lastfm-sync', [AdminPlaylistController::class, 'updateLastfmRule'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/playlists/{playlistId}/lastfm-sync/run', [AdminPlaylistController::class, 'refreshLastfm'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/playlists/{playlistId}/detect', [AdminPlaylistController::class, 'detectResources'])
    ->middleware(VerifyCsrfToken::class);
Route::patch('/api/v1/admin/playlists/{playlistId}/auto-completion', [AdminPlaylistController::class, 'updateAutoCompletion'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/playlists/{playlistId}/auto-completion/reset', [AdminPlaylistController::class, 'resetAutoCompletion'])
    ->middleware(VerifyCsrfToken::class);
Route::get('/api/v1/admin/playlists/{playlistId}/auto-completion/logs', [AdminPlaylistController::class, 'autoCompletionLog']);
Route::get('/api/v1/admin/recommendations/lastfm', [AdminRecommendationController::class, 'show']);
Route::put('/api/v1/admin/recommendations/lastfm', [AdminRecommendationController::class, 'update'])
    ->middleware(VerifyCsrfToken::class);
Route::post('/api/v1/admin/recommendations/lastfm/refresh', [AdminRecommendationController::class, 'refresh'])
    ->middleware(VerifyCsrfToken::class);

/*
 * Third-party clients use a method segment plus optional .view/.json suffix. GET and media-probe
 * HEAD enter the same adapter as form POST; protocol authentication is independent from browser CSRF.
 */
Route::add(['GET', 'HEAD'], '/rest/{method}', [SubsonicController::class, 'handle']);
Route::post('/rest/{method}', [SubsonicController::class, 'handle']);

/*
 * frontend-new 在镜像构建时由 Vite 生成单页应用，生产环境仍只由 Webman 监听一个 HTTP 端口。
 * React Router 的页面路径和歌曲、专辑等动态 ID 必须在刷新时返回同一份 index.html，再由浏览器进行
 * 路由匹配。API 未命中必须继续返回稳定 JSON 404；带扩展名的缺失静态资源也不能回退到 HTML，否则
 * 浏览器会把 index.html 当作 JavaScript、CSS 或图片解析。
 */
Route::fallback(static function (support\Request $request): support\Response {
    $path = '/' . trim($request->path(), '/');

    if ($path === '/api' || str_starts_with($path, '/api/')
        || preg_match('#^/[a-z][a-z0-9-]*-api(?:/|$)#', $path) === 1) {
        return new support\Response(404, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => 'NOT_FOUND', 'message' => '请求的资源不存在。'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    if (preg_match('#(?:^|/)[^/]+\.[A-Za-z0-9]{1,10}$#', $path) === 1) {
        return response('', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    return response('', 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-cache',
    ])->file(public_path('index.html'));
});

Route::disableDefaultRoute();
