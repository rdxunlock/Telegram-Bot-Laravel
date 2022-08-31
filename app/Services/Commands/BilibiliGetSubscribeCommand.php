<?php

namespace App\Services\Commands;

use App\Jobs\SendMessageJob;
use App\Models\TBilibiliSubscribes;
use App\Models\TChatAdmins;
use App\Services\Base\BaseCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;

class BilibiliGetSubscribeCommand extends BaseCommand
{
    public string $name = 'bilibiligetsubscribe';
    public string $description = 'get all subscribed bilibili videos of an UP in this chat';
    public string $usage = '/bilibiligetsubscribe';

    /**
     * @param Message $message
     * @param Telegram $telegram
     * @param int $updateId
     * @return void
     */
    public function execute(Message $message, Telegram $telegram, int $updateId): void
    {
        $messageId = $message->getMessageId();
        $chatId = $message->getChat()->getId();
        $data = [
            'chat_id' => $chatId,
            'reply_to_message_id' => $messageId,
            'text' => '',
        ];
        //#region Detect Chat Type
        $chatType = $message->getChat()->getType();
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            $data['text'] .= "*Error:* This command is available only for groups.\n";
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#endregion
        //#region Detect Admin Rights
        $admins = TChatAdmins::getChatAdmins($chatId);
        $userId = $message->getFrom()->getId();
        if (!in_array($userId, $admins, true)) {
            $data['text'] .= "*Error:* You should be an admin of this chat to use this command.\n\n";
            $data['text'] .= "*Warning:* This command can be used by people who was an admin before update admin list.\n\n";
            $data['text'] .= "*Notice:* Send /updatechatadministrators to update chat admin list.\n\n";
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#endregion
        $subscribes = TBilibiliSubscribes::getAllSubscribeByChat($chatId);
        $subscribes = $subscribes->toArray();
        if (count($subscribes) > 0) {
            $data['text'] .= "*Subscribed UPs:*\n";
            foreach ($subscribes as $subscribe) {
                $data['text'] .= "[{$subscribe['mid']}](https://space.bilibili.com/{$subscribe['mid']})\n";
            }
        } else {
            $data['text'] .= "*Error:* This chat did not subscribe anything.\n";
        }
        $this->dispatch(new SendMessageJob($data));
    }
}
