<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke test: the home page redirects to the roster workspace.
 */
class ExampleTest extends TestCase
{
    public function test_home_redirects_to_roster_workspace(): void
    {
        $this->get('/')->assertRedirect('/roster');
    }
}
