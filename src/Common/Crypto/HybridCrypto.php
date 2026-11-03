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
     * 密文混淆规则：前面加4位、后面加3位无规则字符
     */
    const OBFUSCATE_PREFIX_LEN = 4;
    const OBFUSCATE_SUFFIX_LEN = 3;

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
        $params = $isGet ? $request->getQueryParams() : $this->parsedBody($request);
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

        // key/iv/data 均为 base64url + 首尾混淆，需先统一还原成标准base64
        $aesKey = $rsa->privateDecrypt($this->fromBase64Url($payload['key'] ?? ''));
        $aesIV = $rsa->privateDecrypt($this->fromBase64Url($payload['iv'] ?? ''));
        if ($aesKey === '' || $aesIV === '') {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        // 前端RSA加密的是hex字符串(key=64字符,iv=32字符)，需转回原始字节
        $aesKey = strlen($aesKey) === 64 ? hex2bin($aesKey) : $aesKey;
        $aesIV = strlen($aesIV) === 32 ? hex2bin($aesIV) : $aesIV;

        // data为base64url编码的密文(带前后混淆字符)，需先还原成标准base64再解码
        $ciphertext = base64_decode($this->fromBase64Url($payload['data'] ?? ''), true);
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
     * 前端密文还原为标准base64：
     * 去掉前4位、后3位混淆字符 → `-`/`_` 还原为 `+`/`/` → 补 `=` 填充
     *
     * @param string $data 前端传来的密文
     * @return string 标准base64字符串
     * @throws HttpParamException 长度不足或base64url长度非法
     */
    protected function fromBase64Url($data)
    {
        $data = (string)$data;
        $total = self::OBFUSCATE_PREFIX_LEN + self::OBFUSCATE_SUFFIX_LEN;

        if (strlen($data) < $total) {
            throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }

        $b64 = substr($data, self::OBFUSCATE_PREFIX_LEN, strlen($data) - $total);
        $b64 = strtr($b64, '-_', '+/');

        // base64长度 mod 4 只可能是 0 / 2 / 3（1 为非法长度）
        switch (strlen($b64) % 4) {
            case 0:
                return $b64;
            case 2:
                return $b64 . '==';
            case 3:
                return $b64 . '=';
            default:
                throw new HttpParamException('密文有误', Code::CODE_UNAUTHORIZED);
        }
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

    /**
     * 获取POST等请求的body参数。
     *
     * EasySwoole仅对 x-www-form-urlencoded / multipart 自动解析到parsedBody，
     * application/json 需从原始body中json_decode兜底。
     */
    protected function parsedBody(Request $request)
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed) {
            return $parsed;
        }

        $raw = (string)$request->getBody();
        if ($raw === '') {
            return [];
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
