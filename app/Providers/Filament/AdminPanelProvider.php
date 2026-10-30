<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Support\LocalAvatarProvider;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            ->brandName('Mikausis · Aptaujas')
            ->colors(['primary' => Color::hex('#3c3876'), 'warning' => Color::hex('#ff7402')])
            ->font('Montserrat', provider: LocalFontProvider::class)
            ->darkMode(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->pages([Dashboard::class])
            ->navigationItems([
                NavigationItem::make('Aptauju sākumlapa')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => route('surveys.index'), shouldOpenInNewTab: true)
                    ->sort(100),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/admin.css')).'?v='.filemtime(public_path('css/admin.css')).'">'.
                '<script src="'.e(asset('js/survey-share.js')).'?v='.filemtime(public_path('js/survey-share.js')).'" defer></script>'.
                '<link rel="stylesheet" href="'.e(asset('css/survey-editor.css')).'?v='.filemtime(public_path('css/survey-editor.css')).'">'
            ))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
