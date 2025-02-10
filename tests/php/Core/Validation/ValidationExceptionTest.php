<?php

namespace SilverStripe\Core\Tests\Validation;

use ReflectionClass;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\DevelopmentAdmin;
use SilverStripe\Core\Tests\Validation\ValidationExceptionTest\TestObject;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Forms\EmailField;

class ValidationExceptionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        TestObject::class
    ];

    private function arrayContainsArray($expectedSubArray, $array)
    {
        foreach ($array as $subArray) {
            if ($subArray == $expectedSubArray) {
                return true;
            }
        }
        return false;
    }

    private ?bool $initialCliOverride = null;

    private array $initialControllerStack = [];

    protected function setUp(): void
    {
        parent::setUp();
        $reflectionCli = new ReflectionClass(Environment::class);
        $reflectionStack = new ReflectionClass(Controller::class);
        $this->initialCliOverride = $reflectionCli->getStaticPropertyValue('isCliOverride');
        $this->initialControllerStack = $reflectionStack->getStaticPropertyValue('controller_stack');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $reflectionCli = new ReflectionClass(Environment::class);
        $reflectionStack = new ReflectionClass(Controller::class);
        $reflectionCli->setStaticPropertyValue('isCliOverride', $this->initialCliOverride);
        $reflectionStack->setStaticPropertyValue('controller_stack', $this->initialControllerStack);
    }

    /**
     * Test that ValidationResult object can correctly populate a ValidationException
     */
    public function testCreateFromValidationResult()
    {
        $result = new ValidationResult();
        $result->addError('Not a valid result');

        $exception = new ValidationException($result);

        $this->assertEquals(0, $exception->getCode());
        $this->assertEquals('Not a valid result', $exception->getMessage());
        $this->assertFalse($exception->getResult()->isValid());
        $b = $this->arrayContainsArray([
            'message' => 'Not a valid result',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => '',
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');
    }

    /**
     * Test that ValidationResult object with multiple errors can correctly
     * populate a ValidationException
     */
    public function testCreateFromComplexValidationResult()
    {
        $result = new ValidationResult();
        $result
            ->addError('Invalid type')
            ->addError('Out of kiwis');
        $exception = new ValidationException($result);

        $this->assertEquals(0, $exception->getCode());
        $this->assertEquals('Invalid type', $exception->getMessage());
        $this->assertEquals(false, $exception->getResult()->isValid());

        $b = $this->arrayContainsArray([
            'message' => 'Invalid type',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => '',
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');

        $b = $this->arrayContainsArray([
            'message' => 'Out of kiwis',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => '',
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');
    }

    /**
     * Test that a ValidationException created with no contained ValidationResult
     * will correctly populate itself with an inferred version
     */
    public function testCreateFromMessage()
    {
        $exception = new ValidationException('Error inferred from message', E_USER_ERROR);

        $this->assertEquals(E_USER_ERROR, $exception->getCode());
        $this->assertEquals('Error inferred from message', $exception->getMessage());
        $this->assertFalse($exception->getResult()->isValid());

        $b = $this->arrayContainsArray([
            'message' => 'Error inferred from message',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => null,
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');
    }

    /**
     * Test that ValidationException can be created with both a ValidationResult
     * and a custom message
     */
    public function testCreateWithComplexValidationResultAndMessage()
    {
        $result = new ValidationResult();
        $result->addError('A spork is not a knife')
            ->addError('A knife is not a back scratcher');
        $exception = new ValidationException($result, E_USER_WARNING);

        $this->assertEquals(E_USER_WARNING, $exception->getCode());
        $this->assertEquals('A spork is not a knife', $exception->getMessage());
        $this->assertEquals(false, $exception->getResult()->isValid());

        $b = $this->arrayContainsArray([
            'message' => 'A spork is not a knife',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => '',
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');

        $b = $this->arrayContainsArray([
            'message' => 'A knife is not a back scratcher',
            'messageCast' => ValidationResult::CAST_TEXT,
            'messageType' => ValidationResult::TYPE_ERROR,
            'fieldName' => '',
            'modelClass' => '',
            'recordID' => null,
        ], $exception->getResult()->getMessages());
        $this->assertTrue($b, 'Messages array should contain expected messaged');
    }

    /**
     * Test combining validation results together
     */
    public function testCombineResults()
    {
        $result = new ValidationResult();
        $anotherresult = new ValidationResult();
        $yetanotherresult = new ValidationResult();
        $anotherresult->addError("Eat with your mouth closed", 'bad', "EATING101");
        $yetanotherresult->addError("You didn't wash your hands", 'bad', "BECLEAN", ValidationResult::CAST_HTML);

        $this->assertTrue($result->isValid());
        $this->assertFalse($anotherresult->isValid());
        $this->assertFalse($yetanotherresult->isValid());

        $result->combineAnd($anotherresult)
            ->combineAnd($yetanotherresult);
        $this->assertFalse($result->isValid());
        $this->assertEquals(
            [
                'EATING101' => [
                    'message' => 'Eat with your mouth closed',
                    'messageType' => 'bad',
                    'messageCast' => ValidationResult::CAST_TEXT,
                    'fieldName' => '',
                    'modelClass' => '',
                    'recordID' => null,
                ],
                'BECLEAN' => [
                    'message' => 'You didn\'t wash your hands',
                    'messageType' => 'bad',
                    'messageCast' => ValidationResult::CAST_HTML,
                    'fieldName' => '',
                    'modelClass' => '',
                    'recordID' => null,
                ],
            ],
            $result->getMessages()
        );
    }

    /**
     * Test that a ValidationException created with no contained ValidationResult
     * will correctly populate itself with an inferred version
     */
    public function testValidationResultAddMethods()
    {
        $result = new ValidationResult();
        $result->addMessage('A spork is not a knife', 'bad');
        $result->addError('A knife is not a back scratcher');
        $result->addFieldMessage('Title', 'Title is good', 'good');
        $result->addFieldError('Content', 'Content is bad', 'bad');


        $this->assertEquals(
            [
                [
                    'fieldName' => '',
                    'message' => 'A spork is not a knife',
                    'messageType' => 'bad',
                    'messageCast' => ValidationResult::CAST_TEXT,
                    'modelClass' => '',
                    'recordID' => null,
                ],
                [
                    'fieldName' => '',
                    'message' => 'A knife is not a back scratcher',
                    'messageType' => 'error',
                    'messageCast' => ValidationResult::CAST_TEXT,
                    'modelClass' => '',
                    'recordID' => null,
                ],
                [
                    'fieldName' => 'Title',
                    'message' => 'Title is good',
                    'messageType' => 'good',
                    'messageCast' => ValidationResult::CAST_TEXT,
                    'modelClass' => '',
                    'recordID' => null,
                ],
                [
                    'fieldName' => 'Content',
                    'message' => 'Content is bad',
                    'messageType' => 'bad',
                    'messageCast' => ValidationResult::CAST_TEXT,
                    'modelClass' => '',
                    'recordID' => null,
                ]
            ],
            $result->getMessages()
        );
    }

    public static function provideDoShowAdditionalInfo(): array
    {
        return [
            'is_cli' => [
                'isCli' => true,
                'isDevAdmin' => false,
                'expected' => true,
            ],
            'is_dev_admin' => [
                'isCli' => false,
                'isDevAdmin' => true,
                'expected' => true,
            ],
            'is_both' => [
                'isCli' => true,
                'isDevAdmin' => true,
                'expected' => true,
            ],
            'is_neither' => [
                'isCli' => false,
                'isDevAdmin' => false,
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideDoShowAdditionalInfo')]
    public function testDoShowAdditionalInfo(
        bool $isCli,
        bool $isDevAdmin,
        bool $expected
    ) {
        // Set cli override
        $reflectionCli = new ReflectionClass(Environment::class);
        $reflectionCli->setStaticPropertyValue('isCliOverride', $isCli);
        if ($isDevAdmin) {
            // Ensure that Controller::curr() to return a DevelopmentAdmin
            $reflectionStack = new ReflectionClass(Controller::class);
            $value = $reflectionStack->getStaticPropertyValue('controller_stack');
            array_unshift($value, new DevelopmentAdmin());
            $reflectionStack->setStaticPropertyValue('controller_stack', $value);
        }
        $result = new ValidationResult();
        $result->addFieldMessage('Title', 'Invalid');
        $exception = new ValidationException($result);
        $showsAdditionalInfo = $exception->getMessage() === 'Invalid - fieldName: Title';
        $this->assertSame($expected, $showsAdditionalInfo);
    }

    public static function provideAdditonalInfoDataObject(): array
    {
        return [
            'dataObject' => [
                'type' => 'DataObject',
                'expect' => 'all-info',
            ],
            'formField' => [
                'type' => 'FormField',
                'expect' => 'partial-info',
            ],
            'error' => [
                'type' => 'NoFieldName',
                'expect' => 'no-info',
            ],
        ];
    }

    #[DataProvider('provideAdditonalInfoDataObject')]
    public function testAdditonalInfoDataObject(string $type, string $expect): void
    {
        // Set cli override to ensure additonal info is shown
        $reflectionCli = new ReflectionClass(Environment::class);
        $reflectionCli->setStaticPropertyValue('isCliOverride', true);
        // Run test
        $obj = new TestObject(['Email' => 'valid@example.com']);
        $recordID = $obj->write();
        $dataClass = get_class($obj);
        $actual = null;
        if ($type === 'DataObject') {
            try {
                $obj->update(['Email' => 'invalid'])->write();
            } catch (ValidationException $e) {
                $actual = $e->getMessage();
            }
        } elseif ($type === 'FormField') {
            $result = (new EmailField('Email', 'Email', 'invalid'))->validate();
            $exception = new ValidationException($result);
            $actual = $exception->getMessage();
        } else {
            $result = new ValidationResult();
            $result->addError('Invalid email address');
            $exception = new ValidationException($result);
            $actual = $exception->getMessage();
        }
        $expected = match ($expect) {
            'all-info' => "Invalid email address - fieldName: Email, recordID: $recordID, dataClass: $dataClass",
            'partial-info' => 'Invalid email address - fieldName: Email',
            'no-info' => 'Invalid email address',
        };
        $this->assertSame($expected, $actual);
    }
}
