<?php
/**
 * RSA + AES 混合解密
 *
 * 前端使用RSA公钥加密随机生成的AES密钥与IV，用AES(256-CBC/PKCS7)加密业务数据，
 * 请求体形如 { key, iv, data, timestamp }，本类负责在服务端还原明文。
 *
 * 用法：
 * $data = HybridCrypto::getInstance()->decrypt($request);
 *
 * 依赖 RsaCrypto 单例持有私钥，须由使用方App端提前注入：
 * RsaCrypto::getInstance(config('RSA.private'), config('RSA.public'));
 */

namespace WonderGame\EsUtility\Common\Crypto;

use EasySwoole\Component\Singleton;
use EasySwoole\Http\Request;
use WonderGame\EsUtility\Common\Exception\HttpParamException;
use WonderGame\EsUtility\Common\Http\Code;

class HybridCrypto
{
    use Singleton;

    /**
     * 加密负载字段名
     */
    const PAYLOAD_FIELDS = ['key', 'iv', 'data', 'timestamp'];

    /**
     * 解密请求
     *
     * 从请求中取出加密负载(GET取query，其余取body)，解密后将明文合并回请求参数，
     * 并移除加密负载字段，返回解密得到的明文数组。
     *
     * 若负载字段(key/iv/data)不齐全，视为未加密请求，原样放行返回空数组，不报错。
     *
     * @param Request $request
     * @param array $options window:时间戳有效窗口(毫秒,默认300000); strict:是否校验时间戳(默认true)
     * @return array 解密后的明文数组
     * @throws HttpParamException 密文有误(时间戳过期、解密失败、非合法JSON)
     */
    public function decrypt(Request $request, array $options = [])
    {
        $isGet = strtoupper($request->getMethod()) === 'GET';
        $params = $isGet ? $request->getQueryParams() : $request->getParsedBody();
        $params = is_array($params) ? $params : [];

        // 负载不齐全，视为未加密请求，原样放行
        if ( ! $this->hasPayload($params)) {
            return [];
        }

        $window = isset($options['window']) ? intval($options['window']) : 300000;
        $strict = ! isset($options['strict']) || $options['strict'];

        if ($strict) {
            $this->validateTimestamp($params['timestamp'] ?? null, $window);
        }

        $plain = $this->decryptPayload($params);

        // 移除加密负载字段，合并明文回请求参数
        foreach (self::PAYLOAD_FIELDS as $field) {
            unset($params[$field]);
        }
        $params = $plain + $params;

        $isGet ? $request->withQueryParams($params) : $request->withParsedBody($params);

        return $plain;
    }

    /**
     * 核心解密：RSA解出AES密钥与IV，再AES解出业务数据
     *
     * @param array $payload 含 key/iv/data 的加密负载
     * @return array 明文数组
     * @throws HttpParamException
     */
    public function decryptPayload(array $payload)
    {
        $rsa = RsaCrypto::getInstance();

        $aesKey = $rsa->privateDecrypt($payload['key'] ?? '');
        $aesIV = $rsa->privateDecrypt($payload['iv'] ?? '');
        if ($aesKey === '' || $aesIV === '') {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        // data为base64编码的密文，OPENSSL_RAW_DATA要求传入原始二进制，需先解码
        $ciphertext = base64_decode($payload['data'] ?? '', true);
        if ($ciphertext === false) {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $aesIV);
        if ($plaintext === false) {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        $data = json_decode($plaintext, true);
        if ( ! is_array($data)) {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        return $data;
    }

    /**
     * 校验时间戳。前端为毫秒(Date.now())，兼容秒级时间戳。
     *
     * @param mixed $timestamp
     * @param int $window 有效窗口(毫秒)
     * @throws HttpParamException 过期或不在有效窗口内
     */
    protected function validateTimestamp($timestamp, $window)
    {
        if ( ! is_numeric($timestamp)) {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        $timestamp = (float)$timestamp;
        // 秒级时间戳(小于10^11)容错，统一换算为毫秒
        if ($timestamp > 0 && $timestamp < 100000000000) {
            $timestamp *= 1000;
        }

        $now = microtime(true) * 1000;
        if (abs($now - $timestamp) > $window) {
            throw new HttpParamException('请求已过期', Code::CODE_UNAUTHORIZED);
        }
    }

    /**
     * 判断是否为加密请求：key/iv/data 三个核心字段齐全
     */
    protected function hasPayload(array $params)
    {
        return ! empty($params['key']) && ! empty($params['iv']) && ! empty($params['data']);
    }
}
