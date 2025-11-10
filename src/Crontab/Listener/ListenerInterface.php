<?php

namespace WonderGame\EsUtility\Crontab\Listener;

interface ListenerInterface
{
    /**
     * 任务投递完成
     * @param array $row 单个crontab行
     * @param int $status 投递状态值
     */
    public function onTaskDeliveryFinish($row, $status): void;
}
