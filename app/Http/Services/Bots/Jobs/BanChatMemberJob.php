<?php

namespace App\Http\Services\Bots\Jobs;

use App\Http\Services\Bots\BotCommon;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class BanChatMemberJob extends TelegramBaseQueue
{
    private array $data;

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct();
        $this->data = $data;
    }

    /**
     * @throws TelegramException
     */
    public function handle()
    {
        $botCommon = new BotCommon;
        $botCommon->newTelegram();
        Request::banChatMember($this->data);
    }
}
