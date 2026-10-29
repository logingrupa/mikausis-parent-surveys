<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
        $app['db']->purge('sqlite');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_a_guest_can_render_the_signed_reset_url(): void
    {
        $admin = $this->user();
        $token = Password::broker('users')->createToken($admin);

        $this->get(Filament::getResetPasswordUrl($token, $admin))
            ->assertOk()
            ->assertSeeLivewire(ResetPassword::class)
            ->assertSee($admin->email);

        $this->assertGuest();
        $this->assertTrue(Password::broker('users')->tokenExists($admin, $token));
    }

    public function test_a_reset_url_with_a_bad_signature_is_forbidden(): void
    {
        $admin = $this->user();
        $token = Password::broker('users')->createToken($admin);
        $url = Filament::getResetPasswordUrl($token, $admin);
        $tamperedUrl = preg_replace('/([?&]signature=)[^&]+/', '${1}'.str_repeat('0', 64), $url);

        $this->assertNotSame($url, $tamperedUrl);
        $this->get($tamperedUrl)->assertForbidden();
        $this->assertTrue(Password::broker('users')->tokenExists($admin, $token));
    }

    public function test_a_valid_reset_changes_only_the_intended_admin_and_consumes_the_token(): void
    {
        $admin = $this->user();
        $otherAdmin = $this->user();
        $oldHash = $admin->password;
        $oldRememberToken = $admin->getRememberToken();
        $otherHash = $otherAdmin->password;
        $otherRememberToken = $otherAdmin->getRememberToken();
        $token = Password::broker('users')->createToken($admin);
        $otherToken = Password::broker('users')->createToken($otherAdmin);
        $password = Str::password(32);
        Event::fake([PasswordReset::class]);

        $this->assertTrue(Password::broker('users')->tokenExists($admin, $token));

        Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(__(Password::PASSWORD_RESET))->success())
            ->assertRedirect(Filament::getLoginUrl());

        $admin->refresh();
        $otherAdmin->refresh();
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertNotSame($oldHash, $admin->password);
        $this->assertNotSame($oldRememberToken, $admin->getRememberToken());
        $this->assertSame(60, strlen($admin->getRememberToken()));
        $this->assertSame($otherHash, $otherAdmin->password);
        $this->assertSame($otherRememberToken, $otherAdmin->getRememberToken());
        $this->assertFalse(Password::broker('users')->tokenExists($admin, $token));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->assertTrue(Password::broker('users')->tokenExists($otherAdmin, $otherToken));
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($admin));
        Event::assertDispatchedTimes(PasswordReset::class, 1);
        $this->assertGuest();
    }

    public function test_replaying_a_consumed_token_does_not_change_the_password_again(): void
    {
        $admin = $this->user();
        $token = Password::broker('users')->createToken($admin);
        $password = Str::password(32);

        Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getLoginUrl());

        $admin->refresh();
        $resetHash = $admin->password;
        $resetRememberToken = $admin->getRememberToken();
        $replayPassword = Str::password(32);
        Event::fake([PasswordReset::class]);

        Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
            ->fillForm(['password' => $replayPassword, 'passwordConfirmation' => $replayPassword])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(__(Password::INVALID_TOKEN))->danger())
            ->assertNoRedirect();

        $admin->refresh();
        $this->assertSame($resetHash, $admin->password);
        $this->assertSame($resetRememberToken, $admin->getRememberToken());
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertFalse(Hash::check($replayPassword, $admin->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_the_users_broker_rejects_a_token_after_sixty_minutes(): void
    {
        $this->assertSame('users', Filament::getAuthPasswordBroker() ?? Password::getDefaultDriver());
        $this->assertSame(60, config('auth.passwords.users.expire'));
        $admin = $this->user();
        $oldHash = $admin->password;
        $oldRememberToken = $admin->getRememberToken();
        $token = Password::broker('users')->createToken($admin);
        $password = Str::password(32);
        Event::fake([PasswordReset::class]);

        $this->assertTrue(Password::broker('users')->tokenExists($admin, $token));
        $this->travel(59)->minutes();
        $this->assertTrue(Password::broker('users')->tokenExists($admin, $token));
        $this->travel(2)->minutes();
        $this->assertFalse(Password::broker('users')->tokenExists($admin, $token));

        Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(__(Password::INVALID_TOKEN))->danger())
            ->assertNoRedirect();

        $admin->refresh();
        $this->assertSame($oldHash, $admin->password);
        $this->assertSame($oldRememberToken, $admin->getRememberToken());
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_a_token_for_another_email_cannot_reset_an_admin_password(): void
    {
        $admin = $this->user();
        $otherAdmin = $this->user();
        $oldHash = $admin->password;
        $otherHash = $otherAdmin->password;
        $token = Password::broker('users')->createToken($otherAdmin);
        $password = Str::password(32);
        Event::fake([PasswordReset::class]);

        Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(__(Password::INVALID_TOKEN))->danger())
            ->assertNoRedirect();

        $this->assertSame($oldHash, $admin->fresh()->password);
        $this->assertSame($otherHash, $otherAdmin->fresh()->password);
        $this->assertTrue(Password::broker('users')->tokenExists($otherAdmin, $token));
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_a_non_admin_with_a_valid_token_cannot_change_their_password(): void
    {
        $member = $this->user(isAdmin: false);
        $oldHash = $member->password;
        $oldRememberToken = $member->getRememberToken();
        $token = Password::broker('users')->createToken($member);
        $password = Str::password(32);
        Event::fake([PasswordReset::class]);

        $this->assertFalse($member->canAccessPanel(Filament::getCurrentPanel()));
        $this->assertTrue(Password::broker('users')->tokenExists($member, $token));

        Livewire::test(ResetPassword::class, ['email' => $member->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(__(Password::INVALID_USER))->danger())
            ->assertNoRedirect();

        $member->refresh();
        $this->assertSame($oldHash, $member->password);
        $this->assertSame($oldRememberToken, $member->getRememberToken());
        $this->assertFalse(Hash::check($password, $member->password));
        Event::assertNotDispatched(PasswordReset::class);
        $this->assertGuest();
    }

    private function user(bool $isAdmin = true): User
    {
        $school = School::create(['name' => 'Reset test school', 'slug' => 'reset-'.Str::random(12)]);
        $user = User::factory()->create(['email' => 'reset-'.Str::lower(Str::random(12)).'@example.test']);
        $user->forceFill(['is_admin' => $isAdmin, 'school_id' => $school->id])->save();

        return $user;
    }
}
