<?php

namespace WonderGame\EsUtility\Validate;

use EasySwoole\Http\Request;
use EasySwoole\Validate\Validate;
use EasySwoole\Validate\Exception\Runtime;
use WonderGame\EsUtility\Common\Exception\HttpParamException;

trait BaseValidateTrait
{
    /**
     * ES 的验证器实例
     * @see EasySwoole\Validate\Validate
     * @var Validate
     */
    protected $validateIns;

    /**
     * Request 对象
     * @var Request
     */
    protected $request;

    public function __construct()
    {
        $this->validateIns = new Validate();
    }

    /**
     * 根据预定义场景规则创建验证器
     * @param Request $request
     * @param string $scene 场景
     * @return static
     */
    public static function create(?Request $request = null, $scene = '')
    {
        $ins = new static($request);
        $ins->setRequest($request);
        if (empty($scene)) {
            $target = $request->getUri()->getPath();
            $scene = substr($target, strrpos($target, '/') + 1);
        }
        $ins->loadRule($scene);

        return $ins;
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 根据规则验证数据
     * @param array $data 数据
     * @return array
     * @throws HttpParamException
     */
    public function validate($data)
    {
        $bool = $this->validateIns->validate($data);
        if ( ! $bool) {
            throw new HttpParamException($this->validateIns->getError()->__toString());
        }

        return $data;
    }

    public function loadRule($scene = '')
    {
        $ruleMethod = "rule" . ucfirst($scene);
        $ruleSet = $this->$ruleMethod();
        if (is_array($ruleSet) && is_array($ruleSet[0])) {
            $this->make(...$ruleSet);
        }
    }

    /**
     * @see EasySwoole\Validate\Validate::make
     */
    public function make(array $rules = [], array $message = [], array $alias = [])
    {
        $errMsgMap = [];
        // eg: msgMap[field][rule] => msg

        foreach ($message as $field => $msg) {
            // eg: field.required

            $pos = strrpos($field, '.');
            if ($pos === false) {
                // No validation rules will reset all error messages
                $errMsgMap[$field] = $msg;
                continue;
            }

            $fieldName = substr($field, 0, $pos);
            $fieldRule = substr($field, $pos + 1);

            if (!$fieldName) {
                throw new Runtime(sprintf('Error message[%s] does not specify a field', $msg));
            }

            if ($fieldRule) {
                $errMsgMap[$fieldName][$fieldRule] = $msg;
                continue;
            }

            // No validation rules will reset all error messages
            $errMsgMap[$fieldName] = $msg;
        }

        foreach ($rules as $key => $rule) {
            if (!$key) {
                throw new Runtime('The verified field is empty');
            }

            /** @var Rule $validateRule */
            $validateRule = $this->validateIns->addColumn($key, $alias[$key] ?? null);
            // eg: rule 'required|max:25|between:1,100'
            $rule = explode('|', $rule);
            foreach ($rule as $action) {
                $actionArgs = [];

                if (strpos($action, ':')) {
                    // eg max:25
                    list($action, $arg) = explode(':', $action, 2);

                    if (!strpos($arg, ',')) {
                        $actionArgs[] = $arg;
                    } else {
                        // eg between:1,100
                        $arg = explode(',', $arg);
                        $actionArgs = array_merge($actionArgs, $arg);
                    }
                }

                $errMsg = $errMsgMap[$key] ?? null;
                if (is_array($errMsg)) {
                    $errMsg = $errMsg[$action] ?? null;
                }

                $actionArgs[] = $errMsg;
                $validateRule->{$action}(...$actionArgs);
            }
        }
    }
}