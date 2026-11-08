<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Pushery\SesInboundMail\Http\SnsNotificationController;

/*
 * The endpoint Amazon SNS delivers the notifications of Amazon SES to. Its path, the host it answers
 * on and its middleware come from `ses-inbound-mail.route`; it checks the signature of every message
 * itself, so it stands outside the `web` group, without a session or a CSRF token.
 */
$middleware = array_values(array_filter(Config::array('ses-inbound-mail.route.middleware', []), is_string(...)));

$notifications = Route::post(Config::string('ses-inbound-mail.route.path', 'ses-inbound-mail/notifications'), SnsNotificationController::class)
    ->middleware($middleware)
    ->name('ses-inbound-mail.notifications');

$domain = Config::get('ses-inbound-mail.route.domain');

if (is_string($domain) && $domain !== '') {
    $notifications->domain($domain);
}
