<?php

namespace SilverStripe\ORM\Tests;

use LogicException;
use SilverStripe\Dev\CliDebugView;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\Connect\Database;

class DBQueryCounterDebugView extends CliDebugView implements TestOnly
{
    private const SHOW_QUERIES_RESET = 'SET_TO_THIS_VALUE_WHEN_FINISHED';

    private mixed $showQueries = DBQueryCounterDebugView::SHOW_QUERIES_RESET;

    private int $numQueries = 0;

    public function startCounting(): void
    {
        $this->numQueries = 0;
        if ($this->showQueries !== DBQueryCounterDebugView::SHOW_QUERIES_RESET) {
            throw new LogicException('showQueries wasnt reset, you did something wrong');
        }
        $this->showQueries = $_REQUEST['showqueries'] ?? null;
        $_REQUEST['showqueries'] = 1;
    }

    public function stopCounting(): void
    {
        $_REQUEST['showqueries'] = $this->showQueries;
        $this->showQueries = DBQueryCounterDebugView::SHOW_QUERIES_RESET;
    }

    public function getCount(): int
    {
        return $this->numQueries;
    }

    public function renderMessage($message, $caller, $showHeader = true)
    {
        if (isset($caller['class']) && isset($caller['function'])) {
            if (is_a($caller['class'], Database::class, true) && $caller['function'] === 'displayQuery') {
                $this->numQueries++;
                return;
            }
        }

        parent::renderMessage($message, $caller, $showHeader);
    }
}
