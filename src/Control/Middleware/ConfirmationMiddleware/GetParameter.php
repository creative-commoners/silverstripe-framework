<?php

namespace SilverStripe\Control\Middleware\ConfirmationMiddleware;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Confirmation;

/**
 * A rule to match a GET parameter within HTTPRequest
 */
class GetParameter implements Rule, Bypass
{
    /**
     * Parameter name
     *
     * @var string
     */
    private $name;

    /**
     * Initialize the rule with a parameter name
     *
     * @param string $name
     */
    public function __construct(string $name): void
    {
        $this->setName($name);
    }

    /**
     * Return the parameter name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the parameter name
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name): SilverStripe\Control\Middleware\ConfirmationMiddleware\GetParameter
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Generates the confirmation item
     *
     * @param string $token
     * @param string $value
     *
     * @return Confirmation\Item
     */
    protected function buildConfirmationItem(string $token, string $value): SilverStripe\Security\Confirmation\Item
    {
        return new Confirmation\Item(
            $token,
            _t(__CLASS__ . '.CONFIRMATION_NAME', '"{key}" GET parameter', ['key' => $this->name]),
            sprintf('%s = "%s"', $this->name, $value)
        );
    }

    /**
     * Generates the unique token depending on the path and the parameter
     *
     * @param string $path URL path
     * @param string $value The parameter value
     *
     * @return string
     */
    protected function generateToken(string $path, string $value): string
    {
        return sprintf('%s::%s?%s=%s', static::class, $path, $this->name, $value);
    }

    /**
     * Check request contains the GET parameter
     *
     * @param HTTPRequest $request
     *
     * @return bool
     */
    protected function checkRequestHasParameter(HTTPRequest $request): bool
    {
        return array_key_exists($this->name, $request->getVars() ?? []);
    }

    public function checkRequestForBypass(HTTPRequest $request): bool
    {
        return $this->checkRequestHasParameter($request);
    }

    public function getRequestConfirmationItem(HTTPRequest $request): null|SilverStripe\Security\Confirmation\Item
    {
        if (!$this->checkRequestHasParameter($request)) {
            return null;
        }

        $path = $request->getURL();
        $value = $request->getVar($this->name);

        $token = $this->generateToken($path, $value);

        return $this->buildConfirmationItem($token, $value);
    }
}
