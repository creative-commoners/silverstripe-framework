<?php

namespace SilverStripe\ORM\Tests\HierarchyTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Core\Extension;

class TestTreeTitleExtension extends Extension implements TestOnly
{
    protected function updateTreeTitle(string &$title)
    {
        return "<i>$title</i>";
    }
}
