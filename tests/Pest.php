<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

afterEach(function (): void {
    Mockery::close();
});
