<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Doctrine\ORM\Tools\SchemaValidator;

class SchemaTest extends AbstractAccountingTestCase
{
    public function testMappingIsValid(): void
    {
        $errors = (new SchemaValidator($this->em()))->validateMapping();

        $this->assertSame([], $errors);
    }
}
