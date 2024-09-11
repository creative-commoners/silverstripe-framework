<?php

namespace SilverStripe\Dev;

use SebastianBergmann\Exporter\Exporter;
use SebastianBergmann\RecursionContext\Context;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Model\ModelData;

if (!class_exists(Exporter::class)) {
    return;
}

/**
 * A custom exporter for prettier formatting of SilverStripe specific Objects in PHPUnit's failing test messages.
 *
 * @deprecated 5.4.0 Will be removed without equivalent functionality to replace it
 */
class SSListExporter extends Exporter implements TestOnly
{
    public function __construct()
    {
        Deprecation::withNoReplacement(function () {
            Deprecation::notice(
                '5.4.0',
                'Will be removed without equivalent functionality to replace it',
                Deprecation::SCOPE_CLASS
            );
        });
    }

    /**
     * @param mixed $value
     * @param int $indentation
     * @param null|Context $processed
     * @return string
     * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
     */
    protected function recursiveExport(&$value, $indentation, $processed = null)
    {
        if (!$processed) {
            $processed = new Context;
        }

        $whitespace = str_repeat(' ', 4 * $indentation);

        if ($value instanceof SS_List) {
            $className = get_class($value);
            if (($key = $processed->contains($value)) !== false) {
                return $className . ' &' . $key;
            }

            $list = $value;
            $key = $processed->add($value);
            $values = '';

            if ($list->count() > 0) {
                foreach ($list as $k => $v) {
                    $values .= sprintf(
                        '%s    %s ' . "\n",
                        $whitespace,
                        $this->recursiveExport($v, $indentation)
                    );
                }

                $values = "\n" . $values . $whitespace;
            }

            return sprintf($className . ' &%s (%s)', $key, $values);
        }

        if ($value instanceof ModelData) {
            $className = get_class($value);
            $data = $this->toMap($value);

            return sprintf(
                '%s    %s => %s' . "\n",
                $whitespace,
                $className,
                $this->recursiveExport($data, $indentation + 2, $processed)
            );
        }


        return parent::recursiveExport($value, $indentation, $processed);
    }

    /**
     * @param ModelData $object
     * @return array
     */
    public function toMap(ModelData $object)
    {
        return $object->hasMethod('toMap')
            ? $object->toMap()
            : [];
    }
}
