<?php


namespace SilverStripe\Core\Tests\Validation;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationResultTest extends SapphireTest
{
    public function testSerialise()
    {
        $result = new ValidationResult();
        $result->addError(
            'Error',
            ValidationResult::TYPE_ERROR,
            'code-a',
            ValidationResult::CAST_HTML,
        );
        $result->addMessage(
            'Message',
            ValidationResult::TYPE_GOOD,
            'code-b',
            ValidationResult::CAST_HTML,
        );
        $serialised = serialize($result);
        /** @var ValidationResult $result2 */
        $result2 = unserialize($serialised ?? '');
        $this->assertEquals(
            [
                'code-a' => [
                    'message' => 'Error',
                    'fieldName' => '',
                    'messageCast' => ValidationResult::CAST_HTML,
                    'messageType' => ValidationResult::TYPE_ERROR,
                    'modelClass' => '',
                    'recordID' => null,
                ],
                'code-b' => [
                    'message' => 'Message',
                    'fieldName' => '',
                    'messageCast' => ValidationResult::CAST_HTML,
                    'messageType' => ValidationResult::TYPE_GOOD,
                    'modelClass' => '',
                    'recordID' => null,
                ],
            ],
            $result2->getMessages()
        );
        $this->assertFalse($result2->isValid());
    }

    public static function provideCombineDataClassAndRecordID(): array
    {
        return [
            'first-has-data' => [
                'firstHasData' => true,
                'secondHasData' => false,
            ],
            'second-has-data' => [
                'firstHasData' => false,
                'secondHasData' => true,
            ],
        ];
    }

    #[DataProvider('provideCombineDataClassAndRecordID')]
    public function testCombineDataClassAndRecordID(
        bool $firstHasData,
        bool $secondHasData,
    ): void {
        $modelClass = 'Some\\DataObject';
        $recordID = 123;
        $first = new ValidationResult();
        if ($firstHasData) {
            $first->setModelClass($modelClass);
            $first->setRecordID($recordID);
        }
        $second = new ValidationResult();
        if ($secondHasData) {
            $second->setModelClass($modelClass);
            $second->setRecordID($recordID);
        }
        $first->combineAnd($second);
        // first should always end up with data
        $this->assertSame($modelClass, $first->getModelClass());
        $this->assertSame($recordID, $first->getRecordID());
        // assert data is not copied from first to the second
        if ($firstHasData && !$secondHasData) {
            $this->assertSame('', $second->getModelClass());
            $this->assertSame(null, $second->getRecordID());
        }
    }
}
