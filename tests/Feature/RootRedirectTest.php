<?php

declare(strict_types=1);

it('sends the domain root to the admin, which opens its login when signed out', function (): void {
    $this->get('/')->assertRedirect('/admin')->assertStatus(302);
});
