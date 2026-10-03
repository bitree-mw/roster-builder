<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blade page shells render for staff with escaped user data, and management pages are forbidden to crew.
 */
class PageTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Every Blade page that staff can open.
     *
     * @return array<int, array{0: string}>
     */
    public static function pages(): array
    {
        return [['dashboard'], ['roster'], ['hours'], ['reports'], ['data'], ['accounts'], ['aircraft'], ['maintenance'], ['crew'], ['flights'], ['rules']];
    }

    #[DataProvider('pages')]
    public function test_staff_pages_render_safe_shells_with_page_assets(string $page): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'scheduler', 'name' => '<script>unsafe</script>']));
        $this->get('/'.$page)->assertOk()->assertViewIs('pages.'.$page)->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertDontSee('<script>unsafe</script>', false);
    }

    public function test_crew_cannot_open_management_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'crew']));
        $this->get('/dashboard')->assertForbidden();
        $this->get('/aircraft')->assertForbidden();
        $this->get('/maintenance')->assertForbidden();
        $this->get('/accounts')->assertForbidden();
        $this->get('/hours')->assertForbidden();
        $this->get('/reports')->assertForbidden();
        $this->get('/data')->assertForbidden();
    }

    public function test_only_admins_and_schedulers_open_the_accounts_page(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'crew_control']))->get('/accounts')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/accounts')->assertOk()->assertSee('Administrator');
    }

    public function test_admin_settings_separate_user_accounts_from_airports_which_only_admins_open(): void
    {
        $this->withoutVite();
        // Administrators: the "Admin settings" tab with both sub-pages, airports on its own page.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/accounts')->assertOk()->assertSee('Admin settings')->assertSee(route('airports'), false)->assertDontSee('id="airport-form"', false);
        $this->get('/airports')->assertOk()->assertViewIs('pages.airports')->assertSee('id="airport-form"', false)->assertSee(route('accounts'), false);

        // Schedulers manage pilot and cabin crew accounts only: no airports sub-page or link.
        $this->actingAs(User::factory()->create(['role' => 'scheduler']));
        $this->get('/accounts')->assertOk()->assertSee('Admin settings')->assertDontSee(route('airports'), false);
        $this->get('/airports')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'crew_control']))->get('/airports')->assertForbidden();
    }
}
