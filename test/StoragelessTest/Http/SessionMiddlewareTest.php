<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare(strict_types=1);

namespace PSR7SessionsTest\Storageless\Http;

use DateTimeImmutable;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\SetCookie;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PSR7Sessions\Storageless\Http\SessionMiddleware;
use PSR7Sessions\Storageless\Session\DefaultSessionData;
use PSR7Sessions\Storageless\Session\SessionInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;

final class SessionMiddlewareTest extends TestCase
{
    public function testFromSymmetricKeyDefaultsUsesASecureCookie() : void
    {
        $response = SessionMiddleware::fromSymmetricKeyDefaults('not relevant', 100)
            ->process(new ServerRequest(), $this->writingMiddleware());

        $cookie = $this->getCookie($response);

        self::assertTrue($cookie->getSecure());
        self::assertTrue($cookie->getHttpOnly());
    }

    public function testFromAsymmetricKeyDefaultsUsesASecureCookie() : void
    {
        $response = SessionMiddleware
            ::fromAsymmetricKeyDefaults(
                file_get_contents(__DIR__ . '/../../keys/private_key.pem'),
                file_get_contents(__DIR__ . '/../../keys/public_key.pem'),
                200
            )
            ->process(new ServerRequest(), $this->writingMiddleware());

        $cookie = $this->getCookie($response);

        self::assertTrue($cookie->getSecure());
        self::assertTrue($cookie->getHttpOnly());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testSkipsInjectingSessionCookieOnEmptyContainer(SessionMiddleware $middleware) : void
    {
        $response = $this->ensureSameResponse($middleware, new ServerRequest(), $this->emptyValidationMiddleware());

        self::assertNull($this->getCookie($response)->getValue());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testExtractsSessionContainerFromEmptyRequest(SessionMiddleware $middleware) : void
    {
        $this->ensureSameResponse($middleware, new ServerRequest(), $this->emptyValidationMiddleware());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testInjectsSessionInResponseCookies(SessionMiddleware $middleware) : void
    {
        $initialResponse = new Response();
        $response = $middleware->process(new ServerRequest(), $this->writingMiddleware());

        self::assertNotSame($initialResponse, $response);
        self::assertEmpty($this->getCookie($response, 'non-existing')->getValue());
        self::assertInstanceOf(Token::class, (new Parser())->parse($this->getCookie($response)->getValue()));
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testSessionContainerCanBeReusedOverMultipleRequests(SessionMiddleware $middleware) : void
    {
        $sessionValue = uniqid('', true);

        $checkingMiddleware = $this->fakeDelegate(
            function (ServerRequestInterface $request) use ($sessionValue) {
                /* @var $session SessionInterface */
                $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

                self::assertSame($sessionValue, $session->get('foo'));
                self::assertFalse($session->hasChanged());

                $session->set('foo', $sessionValue . 'changed');

                self::assertTrue(
                    $session->hasChanged(),
                    'ensuring that the cookie is sent again: '
                    . 'non-modified session containers are not to be re-serialized into a token'
                );

                return new Response();
            }
        );

        $firstResponse = $middleware->process(new ServerRequest(), $this->writingMiddleware($sessionValue));

        $response = $middleware->process(
            $this->requestWithResponseCookies($firstResponse),
            $checkingMiddleware
        );

        self::assertNotSame($response, $firstResponse);
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillIgnoreRequestsWithExpiredTokens(SessionMiddleware $middleware) : void
    {
        $expiredToken = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => $this->createToken(
                    $middleware,
                    new \DateTime('-1 day'),
                    new \DateTime('-2 day')
                )
            ]);

        $this->ensureSameResponse($middleware, $expiredToken, $this->emptyValidationMiddleware());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillIgnoreRequestsWithTokensFromFuture(SessionMiddleware $middleware) : void
    {
        $tokenInFuture = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => $this->createToken(
                    $middleware,
                    new \DateTime('+1 day'),
                    new \DateTime('-2 day')
                )
            ]);

        $this->ensureSameResponse($middleware, $tokenInFuture, $this->emptyValidationMiddleware());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillIgnoreUnSignedTokens(SessionMiddleware $middleware) : void
    {
        $unsignedToken = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->setIssuedAt((new \DateTime('-1 day'))->getTimestamp())
                    ->setExpiration((new \DateTime('+1 day'))->getTimestamp())
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->getToken()
            ]);

        $this->ensureSameResponse($middleware, $unsignedToken, $this->emptyValidationMiddleware());
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillNotRefreshSignedTokensWithoutIssuedAt(SessionMiddleware $middleware) : void
    {
        $unsignedToken = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->setExpiration((new \DateTime('+1 day'))->getTimestamp())
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->sign($this->getSigner($middleware), $this->getSignatureKey($middleware))
                    ->getToken()
            ]);

        $this->ensureSameResponse($middleware, $unsignedToken);
    }

    public function testWillRefreshTokenWithIssuedAtExactlyAtTokenRefreshTimeThreshold() : void
    {
        // forcing ourselves to think of time as a mutable value:
        $time = time() + random_int(-100, +100);

        $clock = new FrozenClock(new \DateTimeImmutable('@' . $time));

        $middleware = new SessionMiddleware(
            new Sha256(),
            'foo',
            'foo',
            SetCookie::create(SessionMiddleware::DEFAULT_COOKIE),
            new Parser(),
            1000,
            $clock,
            100
        );

        $requestWithTokenIssuedInThePast = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->setExpiration($time + 10000)
                    ->setIssuedAt($time - 100)
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->sign($this->getSigner($middleware), $this->getSignatureKey($middleware))
                    ->getToken()
            ]);

        $cookie = $this->getCookie($middleware->process($requestWithTokenIssuedInThePast, $this->fakeDelegate(function () {
            return new Response();
        })));

        $token = (new Parser())->parse($cookie->getValue());

        self::assertEquals($time, $token->getClaim(SessionMiddleware::ISSUED_AT_CLAIM), 'Token was refreshed');
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillSkipInjectingSessionCookiesWhenSessionIsNotChanged(SessionMiddleware $middleware) : void
    {
        $this->ensureSameResponse(
            $middleware,
            $this->requestWithResponseCookies(
                $middleware->process(new ServerRequest(), $this->writingMiddleware())
            ),
            $this->fakeDelegate(
                function (ServerRequestInterface $request) {
                    /* @var $session SessionInterface */
                    $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

                    // note: we set the same data just to make sure that we are indeed interacting with the session
                    $session->set('foo', 'bar');

                    self::assertFalse($session->hasChanged());

                    return new Response();
                }
            )
        );
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillSendExpirationCookieWhenSessionContentsAreCleared(SessionMiddleware $middleware) : void
    {
        $this->ensureClearsSessionCookie(
            $middleware,
            $this->requestWithResponseCookies(
                $middleware->process(new ServerRequest(), $this->writingMiddleware())
            ),
            $this->fakeDelegate(
                function (ServerRequestInterface $request) {
                    /* @var $session SessionInterface */
                    $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

                    $session->clear();

                    return new Response();
                }
            )
        );
    }

    /**
     * @dataProvider validMiddlewaresProvider
     */
    public function testWillIgnoreMalformedTokens(SessionMiddleware $middleware) : void
    {
        $this->ensureSameResponse(
            $middleware,
            (new ServerRequest())->withCookieParams([SessionMiddleware::DEFAULT_COOKIE => 'malformed content']),
            $this->emptyValidationMiddleware()
        );
    }

    public function testRejectsTokensWithInvalidSignature() : void
    {
        $middleware = new SessionMiddleware(
            new Sha256(),
            'foo',
            'bar', // wrong symmetric key (on purpose)
            SetCookie::create(SessionMiddleware::DEFAULT_COOKIE),
            new Parser(),
            100,
            new SystemClock()
        );

        $this->ensureSameResponse(
            $middleware,
            $this->requestWithResponseCookies(
                $middleware->process(new ServerRequest(), $this->writingMiddleware())
            ),
            $this->emptyValidationMiddleware()
        );
    }

    public function testAllowsModifyingCookieDetails() : void
    {
        $defaultCookie = SetCookie::create('a-different-cookie-name')
            ->withDomain('foo.bar')
            ->withPath('/yadda')
            ->withHttpOnly(false)
            ->withMaxAge('123123')
            ->withValue('a-random-value');

        $dateTime   = new DateTimeImmutable();
        $middleware = new SessionMiddleware(
            new Sha256(),
            'foo',
            'foo',
            $defaultCookie,
            new Parser(),
            123456,
            new FrozenClock($dateTime),
            123
        );

        $response = $middleware->process(new ServerRequest(), $this->writingMiddleware());

        self::assertNull($this->getCookie($response)->getValue());

        $tokenCookie = $this->getCookie($response, 'a-different-cookie-name');

        self::assertNotEmpty($tokenCookie->getValue());
        self::assertNotSame($defaultCookie->getValue(), $tokenCookie->getValue());
        self::assertSame($defaultCookie->getDomain(), $tokenCookie->getDomain());
        self::assertSame($defaultCookie->getPath(), $tokenCookie->getPath());
        self::assertSame($defaultCookie->getHttpOnly(), $tokenCookie->getHttpOnly());
        self::assertSame($defaultCookie->getMaxAge(), $tokenCookie->getMaxAge());
        self::assertEquals($dateTime->getTimestamp() + 123456, $tokenCookie->getExpires());
    }

    public function testSessionTokenParsingIsDelayedWhenSessionIsNotBeingUsed() : void
    {
        /* @var $signer Signer|\PHPUnit_Framework_MockObject_MockObject */
        $signer = $this->createMock(Signer::class);

        $signer->expects($this->never())->method('verify');
        $signer->method('getAlgorithmId')->willReturn('HS256');

        $currentTimeProvider = new SystemClock();
        $setCookie  = SetCookie::create(SessionMiddleware::DEFAULT_COOKIE);
        $middleware = new SessionMiddleware($signer, 'foo', 'foo', $setCookie, new Parser(), 100, $currentTimeProvider);
        $request    = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->setIssuedAt(time())
                    ->sign(new Sha256(), 'foo')
                    ->getToken()
            ]);

        $middleware->process(
            $request,
            $this->fakeDelegate(function (ServerRequestInterface $request) {
                self::assertInstanceOf(
                    SessionInterface::class,
                    $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE)
                );

                return new Response();
            })
        );
    }

    public function testShouldRegenerateTokenWhenRequestHasATokenThatIsAboutToExpire() : void
    {
        $dateTime   = new DateTimeImmutable();
        $middleware = new SessionMiddleware(
            new Sha256(),
            'foo',
            'foo',
            SetCookie::create(SessionMiddleware::DEFAULT_COOKIE),
            new Parser(),
            1000,
            new FrozenClock($dateTime),
            300
        );

        $expiringToken = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->setIssuedAt((new \DateTime('-800 second'))->getTimestamp())
                    ->setExpiration((new \DateTime('+200 second'))->getTimestamp())
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->sign($this->getSigner($middleware), $this->getSignatureKey($middleware))
                    ->getToken()
            ]);

        $initialResponse = new Response();

        $response = $middleware->process($expiringToken, $this->fakeDelegate(function () use ($initialResponse) {
            return $initialResponse;
        }));

        self::assertNotSame($initialResponse, $response);

        $tokenCookie = $this->getCookie($response);

        self::assertNotEmpty($tokenCookie->getValue());
        self::assertEquals($dateTime->getTimestamp() + 1000, $tokenCookie->getExpires());
    }

    public function testShouldNotRegenerateTokenWhenRequestHasATokenThatIsFarFromExpiration() : void
    {
        $middleware = new SessionMiddleware(
            new Sha256(),
            'foo',
            'foo',
            SetCookie::create(SessionMiddleware::DEFAULT_COOKIE),
            new Parser(),
            1000,
            new SystemClock(),
            300
        );

        $validToken = (new ServerRequest())
            ->withCookieParams([
                SessionMiddleware::DEFAULT_COOKIE => (string) (new Builder())
                    ->setIssuedAt((new \DateTime('-100 second'))->getTimestamp())
                    ->setExpiration((new \DateTime('+900 second'))->getTimestamp())
                    ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
                    ->sign($this->getSigner($middleware), $this->getSignatureKey($middleware))
                    ->getToken()
            ]);

        $this->ensureSameResponse($middleware, $validToken);
    }

    /**
     * @return SessionMiddleware[][]
     */
    public function validMiddlewaresProvider() : array
    {
        return [
            [new SessionMiddleware(
                new Sha256(),
                'foo',
                'foo',
                SetCookie::create(SessionMiddleware::DEFAULT_COOKIE),
                new Parser(),
                100,
                new SystemClock()
            )],
            [SessionMiddleware::fromSymmetricKeyDefaults('not relevant', 100)],
            [SessionMiddleware::fromAsymmetricKeyDefaults(
                file_get_contents(__DIR__ . '/../../keys/private_key.pem'),
                file_get_contents(__DIR__ . '/../../keys/public_key.pem'),
                200
            )],
        ];
    }

    /**
     * @group #46
     */
    public function testFromSymmetricKeyDefaultsWillHaveADefaultSessionPath() : void
    {
        self::assertSame(
            '/',
            $this
                ->getCookie(
                    SessionMiddleware::fromSymmetricKeyDefaults('not relevant', 100)
                        ->process(new ServerRequest(), $this->writingMiddleware())
                )
                ->getPath()
        );
    }

    /**
     * @group #46
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     */
    public function testFromAsymmetricKeyDefaultsWillHaveADefaultSessionPath() : void
    {
        self::assertSame(
            '/',
            $this
                ->getCookie(
                    SessionMiddleware
                        ::fromAsymmetricKeyDefaults(
                            file_get_contents(__DIR__ . '/../../keys/private_key.pem'),
                            file_get_contents(__DIR__ . '/../../keys/public_key.pem'),
                            200
                        )
                        ->process(new ServerRequest(), $this->writingMiddleware())
                )
                ->getPath()
        );
    }

    private function ensureSameResponse(
        SessionMiddleware $middleware,
        ServerRequestInterface $request,
        RequestHandlerInterface $next = null
    ) : ResponseInterface {
        $initialResponse = new Response();

        $handleRequest = $this->createMock(RequestHandlerInterface::class);

        if ($next) {
            // capturing `$initialResponse` from the `$next` handler
            $handleRequest
                ->expects(self::once())
                ->method('handle')
                ->willReturnCallback(function (ServerRequestInterface $serverRequest) use ($next, & $initialResponse) {
                    $initialResponse = $next->handle($serverRequest);

                    return $initialResponse;
                });
        } else {
            $handleRequest
                ->expects(self::once())
                ->method('handle')
                ->willReturn($initialResponse);
        }

        $response = $middleware->process($request, $handleRequest);

        self::assertSame($initialResponse, $response);

        return $response;
    }

    private function ensureClearsSessionCookie(
        SessionMiddleware $middleware,
        ServerRequestInterface $request,
        RequestHandlerInterface $next
    ) : ResponseInterface {
        $response = $middleware->process($request, $next);

        $cookie = $this->getCookie($response);

        self::assertLessThan((new \DateTime('-29 day'))->getTimestamp(), $cookie->getExpires());
        self::assertEmpty($cookie->getValue());

        return $response;
    }

    /**
     * @param SessionMiddleware $middleware
     * @param \DateTime $issuedAt
     * @param \DateTime $expiration
     *
     * @return string
     */
    private function createToken(SessionMiddleware $middleware, \DateTime $issuedAt, \DateTime $expiration) : string
    {
        return (string) (new Builder())
            ->setIssuedAt($issuedAt->getTimestamp())
            ->setExpiration($expiration->getTimestamp())
            ->set(SessionMiddleware::SESSION_CLAIM, DefaultSessionData::fromTokenData(['foo' => 'bar']))
            ->sign($this->getSigner($middleware), $this->getSignatureKey($middleware))
            ->getToken();
    }

    private function emptyValidationMiddleware() : RequestHandlerInterface
    {
        return $this->fakeDelegate(
            function (ServerRequestInterface $request) {
                /* @var $session SessionInterface */
                $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

                self::assertInstanceOf(SessionInterface::class, $session);
                self::assertTrue($session->isEmpty());

                return new Response();
            }
        );
    }

    private function writingMiddleware(string $value = 'bar') : RequestHandlerInterface
    {
        return $this->fakeDelegate(
            function (ServerRequestInterface $request) use ($value) {
                /* @var $session SessionInterface */
                $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
                $session->set('foo', $value);

                return new Response();
            }
        );
    }

    private function fakeDelegate(callable $callback) : RequestHandlerInterface
    {
        $middleware = $this->createMock(RequestHandlerInterface::class);

        $middleware
            ->expects(self::once())
           ->method('handle')
           ->willReturnCallback($callback)
           ->with(self::isInstanceOf(RequestInterface::class));

        return $middleware;
    }

    /**
     * @param ResponseInterface $response
     *
     * @return \Zend\Diactoros\ServerRequest
     */
    private function requestWithResponseCookies(ResponseInterface $response) : ServerRequestInterface
    {
        return (new ServerRequest())->withCookieParams([
            SessionMiddleware::DEFAULT_COOKIE => $this->getCookie($response)->getValue()
        ]);
    }

    /**
     * @param ResponseInterface $response
     *
     * @return SetCookie
     */
    private function getCookie(ResponseInterface $response, string $name = SessionMiddleware::DEFAULT_COOKIE) : SetCookie
    {
        return FigResponseCookies::get($response, $name);
    }

    /**
     * @param SessionMiddleware $middleware
     *
     * @return Signer
     */
    private function getSigner(SessionMiddleware $middleware) : Signer
    {
        return $this->getPropertyValue($middleware, 'signer');
    }

    /**
     * @param SessionMiddleware $middleware
     *
     * @return string
     */
    private function getSignatureKey(SessionMiddleware $middleware) : string
    {
        return $this->getPropertyValue($middleware, 'signatureKey');
    }

    /**
     * @param object $object
     * @param string $name
     *
     * @return mixed
     */
    private function getPropertyValue($object, string $name)
    {
        $propertyReflection = new \ReflectionProperty($object, $name);
        $propertyReflection->setAccessible(true);

        return $propertyReflection->getValue($object);
    }
}
