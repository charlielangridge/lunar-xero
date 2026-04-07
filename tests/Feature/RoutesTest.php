<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;

it('registers the xero connect route', function (): void {
    $this->withoutMiddleware(Authenticate::class);

    $response = $this->get(route('lunarpanel-xero.connect'));

    expect($response->status())->toBe(302);
});
