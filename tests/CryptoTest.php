<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Container\Container;
use Phpvin\Crypto\DecryptionFailed;
use Phpvin\Crypto\Encrypter;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\EncryptCookies;
use Phpvin\Middleware\Pipeline;

final class CryptoTest extends TestCase
{
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        $this->encrypter = Encrypter::fromKey(Encrypter::generateKey());
    }

    // --- keys ------------------------------------------------------------------

    #[Test]
    public function a_generated_key_is_32_bytes_of_randomness(): void
    {
        $key = Encrypter::generateKey();

        $this->assertSame(32, strlen((string) base64_decode($key, true)));
        $this->assertNotSame($key, Encrypter::generateKey(), 'two keys differ');
    }

    #[Test]
    public function a_key_can_carry_a_base64_prefix(): void
    {
        $key = Encrypter::generateKey();

        $plain = Encrypter::fromKey($key);
        $prefixed = Encrypter::fromKey('base64:' . $key);

        // Same key either way, so a value sealed by one opens with the other.
        $this->assertSame('secret', $prefixed->decrypt($plain->encrypt('secret')));
    }

    #[Test]
    public function a_key_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 32 bytes');

        new Encrypter('too short');
    }

    #[Test]
    public function a_key_that_is_not_base64_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid base64');

        Encrypter::fromKey('!!! not base64 !!!');
    }

    // --- round trip -------------------------------------------------------------

    #[Test]
    public function a_value_survives_the_round_trip(): void
    {
        foreach (['', 'plain', '{"json":true}', "binary\0bytes\xff", str_repeat('x', 10_000)] as $value) {
            $this->assertSame($value === '' ? '' : $value, $this->encrypter->decrypt($this->encrypter->encrypt($value)));
        }
    }

    #[Test]
    public function unicode_survives_the_round_trip(): void
    {
        $value = 'Ünicode, 日本語, 🔐';

        $this->assertSame($value, $this->encrypter->decrypt($this->encrypter->encrypt($value)));
    }

    #[Test]
    public function the_same_value_never_seals_the_same_way_twice(): void
    {
        // A deterministic ciphertext leaks which users share a value.
        $first = $this->encrypter->encrypt('user 7');
        $second = $this->encrypter->encrypt('user 7');

        $this->assertNotSame($first, $second);
        $this->assertSame('user 7', $this->encrypter->decrypt($first));
        $this->assertSame('user 7', $this->encrypter->decrypt($second));
    }

    #[Test]
    public function a_sealed_value_is_safe_in_a_url_or_a_cookie(): void
    {
        $sealed = $this->encrypter->encrypt('needs no escaping');

        $this->assertSame($sealed, rawurlencode($sealed), 'no characters needing escapes');
        $this->assertStringNotContainsString('=', $sealed, 'unpadded');
    }

    // --- tampering ---------------------------------------------------------------

    #[Test]
    public function a_modified_ciphertext_is_rejected(): void
    {
        $sealed = $this->encrypter->encrypt('balance: 10');

        // Flip one character in the body.
        $tampered = substr($sealed, 0, -1) . (str_ends_with($sealed, 'A') ? 'B' : 'A');

        $this->expectException(DecryptionFailed::class);

        $this->encrypter->decrypt($tampered);
    }

    #[Test]
    public function a_truncated_payload_is_rejected(): void
    {
        $sealed = $this->encrypter->encrypt('balance: 10');

        $this->expectException(DecryptionFailed::class);

        $this->encrypter->decrypt(substr($sealed, 0, 8));
    }

    #[Test]
    public function a_payload_from_another_key_is_rejected(): void
    {
        $sealed = Encrypter::fromKey(Encrypter::generateKey())->encrypt('not yours');

        $this->expectException(DecryptionFailed::class);

        $this->encrypter->decrypt($sealed);
    }

    #[Test]
    public function something_that_is_not_a_payload_at_all_is_rejected(): void
    {
        foreach (['', 'x1.', 'nonsense', 'zz.abcdef', 'x1'] as $rubbish) {
            try {
                $this->encrypter->decrypt($rubbish);
                $this->fail("Accepted [$rubbish]");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function every_failure_gives_the_same_answer(): void
    {
        // Distinguishable failures are what make a padding oracle work.
        $messages = [];

        foreach (['nonsense', 'x1.aaaa', Encrypter::fromKey(Encrypter::generateKey())->encrypt('x')] as $bad) {
            try {
                $this->encrypter->decrypt($bad);
            } catch (DecryptionFailed $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(3, $messages);
        $this->assertCount(1, array_unique($messages), 'one message for every kind of failure');
    }

    #[Test]
    public function a_sealed_value_is_recognisable_by_shape(): void
    {
        $this->assertTrue(Encrypter::looksSealed($this->encrypter->encrypt('x')));
        $this->assertFalse(Encrypter::looksSealed('plain value'));
    }

    // --- both ciphers ----------------------------------------------------------

    #[Test]
    public function each_cipher_round_trips_on_its_own(): void
    {
        $key = Encrypter::generateKey();

        foreach (['x1', 'a1'] as $cipher) {
            if ($cipher === 'x1' && ! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
                continue;
            }

            $encrypter = Encrypter::fromKey($key, $cipher);
            $sealed = $encrypter->encrypt('portable');

            $this->assertStringStartsWith("$cipher.", $sealed);
            $this->assertSame('portable', $encrypter->decrypt($sealed), $cipher);
        }
    }

    #[Test]
    public function a_value_sealed_with_one_cipher_opens_with_the_default(): void
    {
        // A fleet with mixed extensions must not lose data when a host that
        // pinned AES hands a payload to one that prefers XChaCha20.
        $key = Encrypter::generateKey();

        $sealed = Encrypter::fromKey($key, 'a1')->encrypt('written by the old host');

        $this->assertSame('written by the old host', Encrypter::fromKey($key)->decrypt($sealed));
    }

    #[Test]
    public function pinning_openssl_actually_uses_it(): void
    {
        $encrypter = Encrypter::fromKey(Encrypter::generateKey(), 'a1');

        $this->assertSame('a1', $encrypter->cipher());
        $this->assertStringStartsWith('a1.', $encrypter->encrypt('x'));
    }

    #[Test]
    public function an_unknown_cipher_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cipher [blowfish]');

        Encrypter::fromKey(Encrypter::generateKey(), 'blowfish');
    }

    #[Test]
    public function a_payload_too_short_to_hold_a_nonce_is_rejected(): void
    {
        $key = Encrypter::generateKey();

        // Well-formed base64, valid prefix, but nothing like enough bytes.
        foreach (['x1.' . rtrim(strtr(base64_encode(str_repeat('a', 4)), '+/', '-_'), '='),
                  'a1.' . rtrim(strtr(base64_encode(str_repeat('a', 20)), '+/', '-_'), '=')] as $stunted) {
            try {
                Encrypter::fromKey($key)->decrypt($stunted);
                $this->fail("Accepted a stunted payload: $stunted");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_payload_exactly_the_length_of_its_nonce_is_still_rejected(): void
    {
        // Boundary: a nonce and no ciphertext is not a message.
        $key = Encrypter::generateKey();
        $noncelike = rtrim(strtr(base64_encode(str_repeat("\0", 24)), '+/', '-_'), '=');

        $this->expectException(DecryptionFailed::class);

        Encrypter::fromKey($key)->decrypt('x1.' . $noncelike);
    }

    #[Test]
    public function an_openssl_payload_with_only_a_nonce_and_tag_is_rejected(): void
    {
        $key = Encrypter::generateKey();
        $headerOnly = rtrim(strtr(base64_encode(str_repeat("\0", 28)), '+/', '-_'), '=');

        $this->expectException(DecryptionFailed::class);

        Encrypter::fromKey($key, 'a1')->decrypt('a1.' . $headerOnly);
    }

    #[Test]
    public function a_tampered_openssl_payload_is_rejected(): void
    {
        $encrypter = Encrypter::fromKey(Encrypter::generateKey(), 'a1');
        $sealed = $encrypter->encrypt('balance: 10');
        $tampered = substr($sealed, 0, -1) . (str_ends_with($sealed, 'A') ? 'B' : 'A');

        $this->expectException(DecryptionFailed::class);

        $encrypter->decrypt($tampered);
    }

    #[Test]
    public function an_openssl_payload_from_another_key_is_rejected(): void
    {
        $sealed = Encrypter::fromKey(Encrypter::generateKey(), 'a1')->encrypt('not yours');

        $this->expectException(DecryptionFailed::class);

        Encrypter::fromKey(Encrypter::generateKey(), 'a1')->decrypt($sealed);
    }

    // --- cookie middleware ---------------------------------------------------------

    /**
     * @param array<string, string> $cookies
     */
    private function through(EncryptCookies $middleware, array $cookies, Closure|callable|null $handler = null): Response
    {
        $request = new Request(method: 'GET', path: '/', cookies: $cookies);

        return (new Pipeline(new Container()))
            ->send($request)
            ->through([$middleware])
            ->then($handler ?? fn (): Response => Response::html('ok'));
    }

    #[Test]
    public function an_outgoing_cookie_is_sealed(): void
    {
        $response = $this->through(
            new EncryptCookies($this->encrypter),
            [],
            fn (): Response => Response::html('ok')->cookie('tenant', 'acme'),
        );

        $value = $response->cookies()[0]['value'];

        $this->assertNotSame('acme', $value, 'the raw value never leaves');
        $this->assertTrue(Encrypter::looksSealed($value));
        $this->assertSame('acme', $this->encrypter->decrypt($value));
    }

    #[Test]
    public function an_incoming_cookie_is_opened_before_the_handler_sees_it(): void
    {
        $seen = null;

        $this->through(
            new EncryptCookies($this->encrypter),
            ['tenant' => $this->encrypter->encrypt('acme')],
            function (Request $request) use (&$seen): Response {
                $seen = $request->cookie('tenant');

                return Response::html('ok');
            },
        );

        $this->assertSame('acme', $seen);
    }

    #[Test]
    public function a_forged_cookie_is_dropped_rather_than_passed_through(): void
    {
        $seen = 'untouched';

        $this->through(
            new EncryptCookies($this->encrypter),
            ['tenant' => 'acme'],
            function (Request $request) use (&$seen): Response {
                $seen = $request->cookie('tenant');

                return Response::html('ok');
            },
        );

        // A cookie the client wrote by hand must not arrive looking genuine.
        $this->assertNull($seen);
    }

    #[Test]
    public function the_session_cookie_is_left_alone(): void
    {
        $name = session_name() ?: 'PHPSESSID';
        $seen = null;

        $response = $this->through(
            new EncryptCookies($this->encrypter),
            [$name => 'the-session-id'],
            function (Request $request) use (&$seen, $name): Response {
                $seen = $request->cookie($name);

                return Response::html('ok')->cookie($name, 'new-session-id');
            },
        );

        // PHP's handler manages this one; encrypting it would break
        // session_start().
        $this->assertSame('the-session-id', $seen);
        $this->assertSame('new-session-id', $response->cookies()[0]['value']);
    }

    #[Test]
    public function named_cookies_can_be_exempted(): void
    {
        $response = $this->through(
            new EncryptCookies($this->encrypter, except: ['theme']),
            [],
            fn (): Response => Response::html('ok')->cookie('theme', 'dark')->cookie('tenant', 'acme'),
        );

        $this->assertSame('dark', $response->cookies()[0]['value'], 'exempt');
        $this->assertNotSame('acme', $response->cookies()[1]['value'], 'sealed');
    }

    #[Test]
    public function an_empty_cookie_is_left_empty_so_it_still_clears(): void
    {
        // Sealing '' would produce a non-empty value, and the browser would
        // keep the cookie instead of deleting it.
        $response = $this->through(
            new EncryptCookies($this->encrypter),
            [],
            fn (): Response => Response::html('ok')->cookie('tenant', ''),
        );

        $this->assertSame('', $response->cookies()[0]['value']);
    }

    #[Test]
    public function a_cookie_survives_a_full_round_trip_through_the_middleware(): void
    {
        $middleware = new EncryptCookies($this->encrypter);

        $sealed = $this->through($middleware, [], fn (): Response => Response::html('ok')->cookie('tenant', 'acme'))
            ->cookies()[0]['value'];

        $seen = null;
        $this->through($middleware, ['tenant' => $sealed], function (Request $request) use (&$seen): Response {
            $seen = $request->cookie('tenant');

            return Response::html('ok');
        });

        $this->assertSame('acme', $seen);
    }
}
