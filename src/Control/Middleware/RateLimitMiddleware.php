<?php

namespace SilverStripe\Control\Middleware;

use InvalidArgumentException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Cache\RateLimiter;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

class RateLimitMiddleware implements HTTPMiddleware
{
    /**
     * @var string Optional extra data to add to request key generation
     */
    private $extraKey;

    /**
     * @var int Maximum number of attempts within the decay period
     */
    private $maxAttempts = 10;

    /**
     * @var int The decay period (in minutes)
     */
    private $decay = 1;

    /**
     * @var RateLimiter|null
     */
    private $rateLimiter;

    /**
     * URL regex patterns to bypass rate limiting for this instance.
     *
     * @var string[]
     */
    private array $excludedUrlPatterns = [];

    /**
     * @param HTTPRequest $request
     * @param callable $delegate
     * @return HTTPResponse
     */
    public function process(HTTPRequest $request, callable $delegate)
    {
        if (!$limiter = $this->getRateLimiter()) {
            $limiter = RateLimiter::create(
                $this->getKeyFromRequest($request),
                $this->getMaxAttempts(),
                $this->getDecay()
            );
        }
        if ($this->shouldBypassRateLimit($request)) {
            $response = $delegate($request);
        } elseif ($limiter->canAccess()) {
            $limiter->hit();
            $response = $delegate($request);
        } else {
            $response = $this->getErrorHTTPResponse();
        }
        $this->addHeadersToResponse($response, $limiter);
        return $response;
    }

    /**
     * @param HTTPRequest $request
     * @return string
     */
    protected function getKeyFromRequest($request)
    {
        $key = $this->getExtraKey() ? $this->getExtraKey() . '-' : '';
        $key .= $request->getHost() . '-';
        if ($currentUser = Security::getCurrentUser()) {
            $key .= $currentUser->ID;
        } else {
            $key .= $request->getIP();
        }
        return md5($key ?? '');
    }

    /**
     * @return HTTPResponse
     */
    protected function getErrorHTTPResponse()
    {
        return HTTPResponse::create('<h1>429 - Too many requests</h1>', 429);
    }

    /**
     * @param HTTPResponse $response
     * @param RateLimiter $limiter
     */
    protected function addHeadersToResponse($response, $limiter)
    {
        $response->addHeader('X-RateLimit-Limit', $limiter->getMaxAttempts());
        $response->addHeader('X-RateLimit-Remaining', $remaining = $limiter->getNumAttemptsRemaining());
        $ttl = $limiter->getTimeToReset();
        $response->addHeader('X-RateLimit-Reset', DBDatetime::now()->getTimestamp() + $ttl);
        if ($remaining <= 0) {
            $response->addHeader('Retry-After', $ttl);
        }
    }

    /**
     * @param string $key
     * @return $this
     */
    public function setExtraKey($key)
    {
        $this->extraKey = $key;
        return $this;
    }

    /**
     * @return string
     */
    public function getExtraKey()
    {
        return $this->extraKey;
    }

    /**
     * @param int $maxAttempts
     * @return $this
     */
    public function setMaxAttempts($maxAttempts)
    {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxAttempts()
    {
        return $this->maxAttempts;
    }

    /**
     * @param int $decay Time in minutes
     * @return $this
     */
    public function setDecay($decay)
    {
        $this->decay = $decay;
        return $this;
    }

    /**
     * @return int
     */
    public function getDecay()
    {
        return $this->decay;
    }

    /**
     * @param RateLimiter $rateLimiter
     * @return $this
     */
    public function setRateLimiter($rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
        return $this;
    }

    /**
     * @return RateLimiter|null
     */
    public function getRateLimiter()
    {
        return $this->rateLimiter;
    }

    /**
     * Set the excluded URL regex patterns which will bypass rate limiting.
     * @param string[] $patterns
     * @return $this
     */
    public function setExcludedURLPatterns(array $patterns): static
    {
        foreach ($patterns as $pattern) {
            // The @ error suppression operator is used to check for invalid regex without PHP warnings
            // We're explicitly testing for regex errors in RateLimitMiddlewareTest.php
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(sprintf(
                    'RateLimitMiddleware.ExcludedURLPatterns contains an invalid regex pattern: %s',
                    $pattern
                ));
            }
        }
        $this->excludedUrlPatterns = $patterns;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getExcludedURLPatterns(): array
    {
        return $this->excludedUrlPatterns;
    }

    private function shouldBypassRateLimit(HTTPRequest $request): bool
    {
        $patterns = $this->getExcludedURLPatterns();
        if (!$patterns) {
            return false;
        }
        $url = $request->getURL();
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }
        return false;
    }
}
