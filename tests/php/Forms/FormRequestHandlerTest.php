<?php

namespace SilverStripe\Forms\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\FormRequestHandler;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\Tests\FormRequestHandlerTest\TestForm;
use SilverStripe\Forms\Tests\FormRequestHandlerTest\TestFormRequestHandler;
use SilverStripe\Forms\Tests\FormTest\ChildFieldManagerField;
use SilverStripe\Forms\TextField;

class FormRequestHandlerTest extends SapphireTest
{
    public function testCallsActionOnFormHandler()
    {
        $form = new TestForm(
            Controller::curr(),
            'Form',
            new FieldList(),
            new FieldList(new FormAction('mySubmitOnFormHandler'))
        );
        $form->disableSecurityToken();
        $handler = new TestFormRequestHandler($form);
        $request = new HTTPRequest('POST', '/', null, ['action_mySubmitOnFormHandler' => 1]);
        $request->setSession(new Session([]));
        $response = $handler->httpSubmission($request);
        $this->assertFalse($response->isError());
    }

    public function testCallsActionOnForm()
    {
        $form = new TestForm(
            Controller::curr(),
            'Form',
            new FieldList(),
            new FieldList(new FormAction('mySubmitOnForm'))
        );
        $form->disableSecurityToken();
        $handler = new FormRequestHandler($form);
        $request = new HTTPRequest('POST', '/', null, ['action_mySubmitOnForm' => 1]);
        $request->setSession(new Session([]));
        $response = $handler->httpSubmission($request);
        $this->assertFalse($response->isError());
    }

    public static function provideHandleField(): array
    {
        return [
            [
                'fieldToRequest' => 'FieldOne',
            ],
            [
                'fieldToRequest' => 'FieldTwo',
            ],
            [
                'fieldToRequest' => 'FieldThree',
            ],
            [
                'fieldToRequest' => 'NonExistantField',
            ],
        ];
    }

    #[DataProvider('provideHandleField')]
    public function testHandleField(string $fieldToRequest): void
    {
        $expectedFields = [
            'FieldOne' => new TextField('FieldOne'),
            'FieldTwo' => new TextField('FieldTwo'),
            'FieldThree' => new TextField('FieldThree'),
        ];
        $form = new TestForm(
            Controller::curr(),
            'Form',
            new FieldList([
                $expectedFields['FieldOne'],
                new CompositeField([
                    $expectedFields['FieldTwo'],
                    new ChildFieldManagerField([
                        $expectedFields['FieldThree'],
                    ]),
                ]),
            ]),
            new FieldList(new FormAction('mySubmitOnForm'))
        );
        $handler = new FormRequestHandler($form);
        $request = new HTTPRequest('GET', '');
        // Setting the param here mimics what happens during real request handling further up the chain
        $request->setRouteParams(['FieldName' => $fieldToRequest]);
        $this->assertSame($expectedFields[$fieldToRequest] ?? null, $handler->handleField($request));
    }
}
