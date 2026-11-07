<?php

test('guests can view the department selector but the admin workspace requires authentication', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vault Intelligence Agency')
        ->assertSee(route('cfm.login'));

    $this->get('/admin')->assertRedirect(route('login'));
});
