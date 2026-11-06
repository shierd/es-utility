<?php

namespace WonderGame\EsUtility\Common\CloudLib\Email;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;

/**
 * composer require aws/aws-sdk-php:3.337.3 (最后一个支持 php>=7.2.5 的版本)
 * @document https://docs.aws.amazon.com/ses/latest/dg/send-email.html
 */
class Aws extends Base
{
    protected $accessKeyId = '';

    protected $secretAccessKey = '';

    /**
     * 地域，如 us-east-1
     * @document https://docs.aws.amazon.com/general/latest/gr/ses.html
     * @var string
     */
    protected $region = '';

    /**
     * 发件人，须为 SES 已验证身份
     * @var string
     */
    protected $fromEmailAddress = '';

    protected $subject = '';

    /**
     * HTML 正文模板，sprintf 填参
     * @var string
     */
    protected $htmlBody = '';

    public function send($to = [], array $params = [], bool $ingo = false)
    {
        $parentId = $ingo ? ($params['parentId'] ?: '') : null;
        // Simple 模式无按 type 选模板的概念，丢弃 type，避免其成为 sprintf 的位置参数
        unset($params['type'], $params['parentId']);

        if (is_string($to)) {
            $to = [$to];
        }
        $htmlBody = $params ? sprintf($this->htmlBody, ...$params) : $this->htmlBody;

        $log = repeat_array_keys(get_object_vars($this), ['accessKeyId', 'secretAccessKey'], 5);
        $endFn = http_tracker('SDK:EMAIL', [
            'url' => '__AWS_EMAIL__',
            'POST' => $log,
            'method' => 'POST',
        ], $parentId);

        try {
            // 注意：以下代码可在开发模式下请根据需要开启或关闭
            if (is_env('dev')) {
                $endFn('env: dev ok');
                return true;
            }

            $Client = new SesV2Client([
                'version' => '2019-09-27',
                'region' => $this->region,
                'credentials' => [
                    'key' => $this->accessKeyId,
                    'secret' => $this->secretAccessKey,
                ],
            ]);

            $resp = $Client->sendEmail([
                'FromEmailAddress' => $this->fromEmailAddress,
                'Destination' => [
                    'ToAddresses' => $to,
                ],
                'Content' => [
                    'Simple' => [
                        'Subject' => [
                            'Data' => $this->subject,
                            'Charset' => 'UTF-8',
                        ],
                        'Body' => [
                            'Html' => [
                                'Data' => $htmlBody,
                                'Charset' => 'UTF-8',
                            ],
                        ],
                    ],
                ],
            ]);

            $messageId = $resp->get('MessageId');
            $endFn(['MessageId' => $messageId], 200);

            $isSuccess = ! empty($messageId);
            if ( ! $isSuccess) {
                notice('AWS邮件发送失败1: ' . json_encode($resp->toArray()));
            }
            return $isSuccess;
        } catch (AwsException $error) {
            $msg = 'AWS邮件发送失败: ' . $error->__toString();
            is_callable($endFn) && $endFn($msg, $error->getCode());
            trace($msg, 'error');
            notice($msg);
            return false;
        }
    }
}
