<?php

namespace Tests\Common\Crypto;

use EasySwoole\Http\Request;
use EasySwoole\Http\Message\Stream;
use PHPUnit\Framework\TestCase;
use WonderGame\EsUtility\Common\Crypto\HybridCrypto as HybridCryptoService;
use WonderGame\EsUtility\Common\Crypto\RsaCrypto;
use WonderGame\EsUtility\Common\Exception\HttpParamException;

/**
 * RSA+AES 混合解密测试
 *
 * 运行时临时生成2048位密钥对，模拟前端加密流程，自包含无需外部pem。
 * php easyswoole phpunit tests/Common/Crypto/HybridCrypto.php
 */
class HybridCrypto extends TestCase
{
    protected static $publicKey = '';

    public static function setUpBeforeClass(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        self::$publicKey = openssl_pkey_get_details($res)['key'];

        // 注入RsaCrypto单例(私钥+公钥)
        RsaCrypto::getInstance($privateKey, self::$publicKey);
    }

    /**
     * 模拟前端加密，生成 { key, iv, data, timestamp } 负载
     * 前端对key/iv的hex字符串做RSA加密，而非原始字节
     */
    protected function encryptPayload(array $data, $timestamp = null): array
    {
        $aesKey = random_bytes(32);
        $aesIV = random_bytes(16);

        $ciphertext = openssl_encrypt(json_encode($data), 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $aesIV);

        // RSA加密的是hex字符串
        openssl_public_encrypt(bin2hex($aesKey), $encKey, self::$publicKey, OPENSSL_PKCS1_PADDING);
        openssl_public_encrypt(bin2hex($aesIV), $encIV, self::$publicKey, OPENSSL_PKCS1_PADDING);

        return [
            'key' => base64_encode($encKey),
            'iv' => base64_encode($encIV),
            'data' => base64_encode($ciphertext),
            'timestamp' => $timestamp ?? intval(microtime(true) * 1000),
        ];
    }

    protected function makeRequest(array $params, $method = 'POST'): Request
    {
        $request = new Request();
        $request->withMethod($method);
        strtoupper($method) === 'GET' ? $request->withQueryParams($params) : $request->withParsedBody($params);
        return $request;
    }

    public function testDecryptSuccess()
    {
        $origin = ['username' => 'alice', 'age' => 18, 'list' => [1, 2, 3]];
        $request = $this->makeRequest($this->encryptPayload($origin));

        $plain = HybridCryptoService::getInstance()->decrypt($request);

        $this->assertSame($origin, $plain);
        // 明文已合并回请求，且负载字段被移除
        $this->assertSame('alice', $request->getParsedBody('username'));
        $this->assertNull($request->getParsedBody('key'));
        $this->assertNull($request->getParsedBody('data'));
    }

    public function testDecryptGetQuery()
    {
        $origin = ['id' => '1001', 'act' => 'query'];
        $request = $this->makeRequest($this->encryptPayload($origin), 'GET');

        $plain = HybridCryptoService::getInstance()->decrypt($request);

        $this->assertSame($origin, $plain);
        $this->assertSame('1001', $request->getQueryParams()['id']);
        $this->assertArrayNotHasKey('key', $request->getQueryParams());
    }

    public function testDecryptJsonBody()
    {
        // 模拟 application/json 请求：body为原始JSON，parsedBody为空
        $origin = ['username' => 'bob', 'n' => 7];
        $payload = $this->encryptPayload($origin);

        $request = new Request();
        $request->withMethod('POST');
        $request->withBody(new Stream(json_encode($payload)));

        $plain = HybridCryptoService::getInstance()->decrypt($request);

        $this->assertSame($origin, $plain);
        $this->assertSame('bob', $request->getParsedBody('username'));
    }

    public function testNotEncryptedPassThrough()
    {
        $request = $this->makeRequest(['foo' => 'bar']);
        $plain = HybridCryptoService::getInstance()->decrypt($request);
        $this->assertSame([], $plain);
        // 未加密请求原样保留
        $this->assertSame('bar', $request->getParsedBody('foo'));
    }

    public function testExpiredTimestamp()
    {
        // 10分钟前，超出默认5分钟窗口
        $payload = $this->encryptPayload(['a' => 1], intval(microtime(true) * 1000) - 600000);
        $request = $this->makeRequest($payload);

        $this->expectException(HttpParamException::class);
        HybridCryptoService::getInstance()->decrypt($request);
    }

    public function testExpiredButStrictFalse()
    {
        $payload = $this->encryptPayload(['a' => 1], intval(microtime(true) * 1000) - 600000);
        $request = $this->makeRequest($payload);

        $plain = HybridCryptoService::getInstance()->decrypt($request, ['strict' => false]);
        $this->assertSame(['a' => 1], $plain);
    }

    public function testInvalidCiphertext()
    {
        $payload = $this->encryptPayload(['a' => 1]);
        $payload['data'] = base64_encode('not-a-valid-aes-ciphertext');
        $request = $this->makeRequest($payload);

        $this->expectException(HttpParamException::class);
        HybridCryptoService::getInstance()->decrypt($request);
    }
}
