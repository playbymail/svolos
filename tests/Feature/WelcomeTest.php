<?php

use Inertia\Testing\AssertableInertia;

test('the root route renders the welcome inertia page', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});
