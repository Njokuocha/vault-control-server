<?php

test('guests are redirected to authenticate before accessing the application', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
