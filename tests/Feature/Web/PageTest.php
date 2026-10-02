<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function pages(): array
    {
        return [['roster'], ['aircraft'], ['maintenance'], ['crew'], ['flights'], ['rules']];
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
        $this->get('/aircraft')->assertForbidden();
        $this->get('/maintenance')->assertForbidden();
    }
}
