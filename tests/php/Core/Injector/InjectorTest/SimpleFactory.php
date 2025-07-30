<?php

namespace SilverStripe\Core\Tests\Injector\InjectorTest;

use SilverStripe\Core\Injector\Factory;

class SimpleFactory implements Factory
{
    public function create(string $service, array $params = []): ?object
    {
        return new TestObject();
    }
}
