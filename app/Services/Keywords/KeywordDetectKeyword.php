<?php

namespace App\Services\Keywords;

use App\Exceptions\Handler;
use App\Jobs\SendMessageJob;
use App\Models\TChatKeywords;
use App\Models\TChatKeywordsOperationEnum;
use App\Models\TChatKeywordsTargetEnum;
use App\Services\Base\BaseKeyword;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Throwable;

class KeywordDetectKeyword extends BaseKeyword
{
    public string $name = 'Keyword Detecter';
    public string $description = 'Match Keywords';
    protected string $pattern = '//';
    private bool $stop = false;

    public function preExecute(Message $message): bool
    {
        return true;
    }

    public function execute(Message $message, Telegram $telegram, int $updateId): void
    {
        /** @var Collection<TChatKeywords> $keywords */
        $keywords = TChatKeywords::getKeywords($message->getChat()->getId());
        foreach ($keywords as $keyword) {
            try {
                $this->handle($keyword->keyword, $keyword->target, $keyword->operation, $keyword->data, $message, $telegram, $updateId);
            } catch (Throwable $e) {
                Handler::logError($e);
            }
            if ($this->stop) {
                break;
            }
        }
    }

    private function handle(
        string                     $keyword,
        TChatKeywordsTargetEnum    $target,
        TChatKeywordsOperationEnum $operation,
        array                      $data,
        Message                    $message, Telegram $telegram, int $updateId
    ): void
    {
        switch ($target) {
            case TChatKeywordsTargetEnum::TARGET_CHATID:
                $chatId = $message->getChat()->getId();
                if ($chatId == $keyword) {
                    $this->runOperation($operation, $data, $message, $telegram, $updateId);
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_USERID:
                $userId = $message->getFrom()->getId();
                if ($userId == $keyword) {
                    $this->runOperation($operation, $data, $message, $telegram, $updateId);
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_NAME:
                $name = strtoupper(($message->getFrom()->getFirstName() ?? '') . ($message->getFrom()->getLastName() ?? ''));
                if (str_contains($name, $keyword)) {
                    $this->runOperation($operation, $data, $message, $telegram, $updateId);
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_FROMNAME:
                if ($message->getForwardFrom()) {
                    $fromName = strtoupper(($message->getForwardFrom()->getFirstName() ?? '') . ($message->getForwardFrom()->getLastName() ?? ''));
                    if (str_contains($fromName, $keyword)) {
                        $this->runOperation($operation, $data, $message, $telegram, $updateId);
                    }
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_TITLE:
                if ($message->getForwardFromChat()) {
                    $title = strtoupper($message->getForwardFromChat()->getTitle());
                    if (str_contains($title, $keyword)) {
                        $this->runOperation($operation, $data, $message, $telegram, $updateId);
                    }
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_TEXT:
                $text = strtoupper($message->getText() ?? $message->getCaption() ?? '');
                if (str_contains($text, $keyword)) {
                    $this->runOperation($operation, $data, $message, $telegram, $updateId);
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_DICE:
                if ($message->getDice()) {
                    $text = $message->getDice()->getEmoji() ?? '';
                    if (strtoupper(bin2hex($text)) == strtoupper($keyword)) {
                        $this->runOperation($operation, $data, $message, $telegram, $updateId);
                    }
                }
                break;
            case TChatKeywordsTargetEnum::TARGET_STICKER:
                if ($message->getSticker()) {
                    $fileUniqueId = $message->getSticker()->getFileUniqueId() ?? '';
                    if ($fileUniqueId == $keyword) {
                        $this->runOperation($operation, $data, $message, $telegram, $updateId);
                    }
                }
        }
    }

    private function runOperation(
        TChatKeywordsOperationEnum $operation,
        array                      $data,
        Message                    $message, Telegram $telegram, int $updateId
    ): void
    {
        switch ($operation) {
            case TChatKeywordsOperationEnum::OPERATION_FORWARD:
                $this->forward($data, $message, $telegram, $updateId);
                break;
            case TChatKeywordsOperationEnum::OPERATION_REPLY:
                $this->reply($data, $message, $telegram, $updateId);
                break;
            default:
                break;
        }
    }

    private function forward(array $data, Message $message, Telegram $telegram, int $updateId): void
    {
        $cacheKey1 = "Keyword::WARN::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey2 = "Keyword::RESTRICT::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey3 = "Keyword::BAN::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey4 = "Keyword::DELETE::{$message->getChat()->getId()}::{$message->getFrom()->getId()}::{$message->getMessageId()}";
        if (Cache::has($cacheKey1) || Cache::has($cacheKey2) || Cache::has($cacheKey3) || Cache::has($cacheKey4)) {
            return;
        }
        $cacheKey = "Keyword::FORWARD::{$message->getChat()->getId()}::{$message->getFrom()->getId()}::{$message->getMessageId()}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, 1, Carbon::now()->addMinute());

        $forwarder = [];

        isset($data['chat_id']) && $forwarder['chat_id'] = $data['chat_id'];

        if (isset($data['text'])) {
            $data['text'] = "Forwarded Message:\n\n" . $data['text'] . "\n\n";
        } else {
            $data['text'] = "Forwarded Message:\n\n";
        }
        $forwarder['text'] = $data['text'];

        $originalText = $message->getText() ?? $message->getCaption();
        if (mb_strlen($originalText, 'UTF-8') > 32) {
            $forwarder['text'] .= mb_substr($originalText, 0, 64, 'UTF-8') . '...' . "\n\n";
        } else {
            $forwarder['text'] .= $originalText . "\n\n";
        }

        $forwarder['text'] .= "Message ID: <code>{$message->getMessageId()}</code>\n";
        $forwarder['text'] .= "From Chat: <code>{$message->getChat()->getId()}</code>\n";
        $forwarder['text'] .= "From User: <a href='tg://user?id={$message->getFrom()->getId()}'>{$message->getFrom()->getId()}</a>\n";
        $cid = str_replace('-100', '', $message->getChat()->getId());
        $forwarder['text'] .= "Message Link: https://t.me/c/$cid/{$message->getMessageId()}";
        count($forwarder) == 2 && $this->dispatch(new SendMessageJob($forwarder, null, 0));
    }

    private function reply(array $data, Message $message, Telegram $telegram, int $updateId): void
    {
        $cacheKey1 = "Keyword::WARN::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey2 = "Keyword::RESTRICT::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey3 = "Keyword::BAN::{$message->getChat()->getId()}::{$message->getFrom()->getId()}";
        $cacheKey4 = "Keyword::DELETE::{$message->getChat()->getId()}::{$message->getFrom()->getId()}::{$message->getMessageId()}";
        if (Cache::has($cacheKey1) || Cache::has($cacheKey2) || Cache::has($cacheKey3) || Cache::has($cacheKey4)) {
            return;
        }
        $cacheKey = "Keyword::REPLY::{$message->getChat()->getId()}::{$message->getFrom()->getId()}::{$message->getMessageId()}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, 1, Carbon::now()->addMinute());
        if (!isset($data['type'])) {
            return;
        }
        $sender = [
            'chat_id' => $message->getChat()->getId(),
            'reply_to_message_id' => $message->getMessageId(),
        ];
        switch ($data['type']) {
            case 'text':
                if (!isset($data['text'])) {
                    return;
                }
                $sender['text'] = $data['text'];
                if (isset($data['button'])) {
                    $sender['reply_markup'] = new InlineKeyboard([]);
//                    $data['button'] = [
//                        [
//                            [
//                                'text' => 'text',
//                                'url' => 'url',
//                            ],
//                            [
//                                'text' => 'text',
//                                'url' => 'url',
//                            ],
//                        ],
//                        [
//                            [
//                                'text' => 'text',
//                                'url' => 'url',
//                            ],
//                            [
//                                'text' => 'text',
//                                'url' => 'url',
//                            ],
//                        ],
//                    ];
                    foreach ($data['button'] as $row) {
                        $buttons = [];
                        foreach ($row as $button) {
                            $buttons[] = new InlineKeyboardButton([
                                'text' => $button['text'],
                                'url' => $button['url'],
                            ]);
                        }
                        $sender['reply_markup']->addRow(...$buttons);
                    }
                }
                $this->dispatch(new SendMessageJob($sender, null, 0));
                break;
            case 'sticker':
                if (!isset($data['sticker'])) {
                    return;
                }
                break;
        }
    }
}
