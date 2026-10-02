<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_redirects_to_roster_workspace(): void
    {
        $this->get('/')->assertRedirect('/roster');
    }
}
