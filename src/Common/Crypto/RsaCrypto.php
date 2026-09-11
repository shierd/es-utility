<?php
/**
 * RSA加解密单例
 *
 * 由使用方App端首次注入私钥与公钥，例如：
 * RsaCrypto::getInstance(config('RSA.private'), config('RSA.public'));
 */

namespace WonderGame\EsUtility\Common\Crypto;

use EasySwoole\Component\Singleton;
use WonderGame\EsUtility\Common\Classes\Openssl;

/**
 * 全局单例RSA加密类
 */
class RsaCrypto extends Openssl
{
    use Singleton;
}
