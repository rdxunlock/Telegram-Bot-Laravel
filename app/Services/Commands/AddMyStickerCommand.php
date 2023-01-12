<?php

namespace App\Services\Commands;

use App\Common\Config;
use App\Jobs\DeleteTempStickerFileJob;
use App\Jobs\SendMessageJob;
use App\Services\Base\BaseCommand;
use DESMG\RFC6986\Hash;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

class AddMyStickerCommand extends BaseCommand
{
    public string $name = 'addmysticker';
    public string $description = 'add sticker to pack';
    public string $usage = '/addmysticker';

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
        $userId = $message->getFrom()->getId();
        $data = [
            'chat_id' => $chatId,
            'reply_to_message_id' => $messageId,
            'text' => '',
        ];
        $reply_to_message = $message->getReplyToMessage();
        if (!$reply_to_message) {
            $data['text'] .= "<b>Error</b>: You should reply to a sticker for using this command.\n";
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        $stickerName = 'user_' . $userId . '_by_' . $telegram->getBotUsername();
        $sticker = $reply_to_message->getSticker();
        if (!$sticker) {
            $photos = $reply_to_message->getPhoto();
            if ($photos) {
                usort($photos, function (PhotoSize $left, PhotoSize $right) {
                    return bccomp(
                        bcmul($right->getWidth(), $right->getHeight()),
                        bcmul($left->getWidth(), $left->getHeight())
                    );
                });
                $photos = array_filter($photos, function (PhotoSize $photo) {
                    return $photo->getWidth() <= 512 && $photo->getHeight() <= 512 && $photo->getFileSize() / 1024 <= 512;
                });
                $photos = array_values($photos);
                if (count($photos) <= 0) {
                    $data['text'] .= "<b>Error</b>: The photo is too large.\n";
                    $this->dispatch(new SendMessageJob($data));
                    return;
                }
                $stickerFileId = $photos[0]->getFileId();
                $is_png = true;
                $stickerEmoji = hex2bin('C2A9');
            } else {
                $data['text'] .= "<b>Error</b>: Cannot get the sticker from the message you replied to.\n";
                $this->dispatch(new SendMessageJob($data));
                return;
            }
        } else {
            $stickerEmoji = $sticker->getEmoji();
            $is_tgs = $sticker->getIsAnimated();
            $is_webm = $sticker->getIsVideo();
            $is_png = $is_tgs == false && $is_webm == false;
            $stickerFileId = $sticker->getFileId();
        }
        /**
         * TODO: maybe we should create 3 sticker sets to support all types of stickers
         */
        if (!$is_png) {
            $data['text'] .= "<b>Error</b>: Only PNG sticker is supported.\n";
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#region $stickerFileUrl
        try {
            [$stickerFileUrl, $stickerFile] = $this->getStickerFileURL($stickerFileId);
            if (!$stickerFileUrl) {
                $data['text'] .= "<b>Error</b>: Get sticker file path failed.\n";
                $data['text'] .= "<b>Error Code</b>: <code>{$stickerFile->getErrorCode()}</code>\n";
                $data['text'] .= "<b>Error Msg</b>: <code>{$stickerFile->getDescription()}</code>\n\n";
                $this->dispatch(new SendMessageJob($data));
                return;
            }
        } catch (Throwable $e) {
            $data['text'] .= "An error occurred while getting sticker file path.\n";
            Log::error($e->getMessage(), $e->getTrace());
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#endregion $stickerFileUrl
        //#region $stickerFileDownloaded
        try {
//            $stickerFileDownloaded = $this->downloadStickerFile($stickerFileUrl, $is_png, $is_tgs, $is_webm);
            $stickerFileDownloaded = $this->downloadStickerFile($stickerFileUrl);
            if (!$stickerFileDownloaded) {
                $data['text'] .= "<b>Error</b>: Downloading the sticker file failed.\n";
                $this->dispatch(new SendMessageJob($data));
                return;
            }
        } catch (Throwable $e) {
            $data['text'] .= "An error occurred while downloading the sticker file.\n";
            Log::error($e->getMessage(), $e->getTrace());
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#endregion $stickerFileDownloaded
        //#region addStickerToSet
        try {
            [$addStickerToSetSuccess, $serverResponse] =
                $this->addStickerToSet(
                    $userId, $stickerName, $stickerEmoji,
//                    $is_png, $is_tgs, $is_webm,
                    $stickerFileDownloaded
                );
            if (!$addStickerToSetSuccess) {
                switch ($serverResponse->getDescription()) {
                    case 'Bad Request: STICKERSET_INVALID':
                        $data['text'] .= "It seems that you don't have a sticker pack yet.\n";
                        $data['text'] .= "You can create one by using /createmysticker command.\n";
                        break;
                    case 'Bad Request: STICKER_PNG_NOPNG':
                        $data['text'] .= "The sticker file is not a PNG file.\n";
                        break;
                    case 'Bad Request: STICKER_PNG_DIMENSIONS':
                        $data['text'] .= "<b>Error</b>: The sticker is not 512x512.\n";
                        break;
                    default:
                        $data['text'] .= "<b>Error</b>: Add to your sticker pack failed.\n";
                        $data['text'] .= "<b>Error Code</b>: <code>{$serverResponse->getErrorCode()}</code>\n";
                        $data['text'] .= "<b>Error Msg</b>: <code>{$serverResponse->getDescription()}</code>\n\n";
                        $data['text'] .= "If you do not have a sticker pack created from this bot, send /createmysticker to create one.\n";
                        break;
                }
            } else {
                $data['text'] .= "Sticker added successfully to <a href='https://t.me/addstickers/$stickerName'>this</a> sticker pack.\n";
            }
            $this->dispatch(new SendMessageJob($data));
            return;
        } catch (Throwable $e) {
            $data['text'] .= "An error occurred while add the sticker to your pack.\n";
            Log::error($e->getMessage(), $e->getTrace());
            $this->dispatch(new SendMessageJob($data));
            return;
        }
        //#endregion addStickerToSet
    }

    /**
     * @param string $stickerFileId
     * @return array
     */
    private function getStickerFileURL(string $stickerFileId): array
    {
        $stickerFile = Request::getFile(['file_id' => $stickerFileId]);
        if ($stickerFile->isOk()) {
            $stickerFile = $stickerFile->getResult();
            $stickerFilePath = $stickerFile->getFilePath();
            return [env('TELEGRAM_API_BASE_URI') . '/file/bot' . env('TELEGRAM_BOT_TOKEN') . '/' . $stickerFilePath, $stickerFile];
        } else {
            return ['', $stickerFile];
        }
    }

//    /**
//     * @param string $stickerFileUrl
//     * @param bool $is_png
//     * @param bool $is_tgs
//     * @param bool $is_webm
//     * @return string
//     */
//    private function downloadStickerFile(string $stickerFileUrl, bool $is_png, bool $is_tgs, bool $is_webm): string
//    {
//        $stickerFileData = Http::withHeaders(Config::CURL_HEADERS)
//            ->connectTimeout(3)
//            ->timeout(5)
//            ->retry(1, 1000)
//            ->get($stickerFileUrl);
//        if ($stickerFileData->ok()) {
//            $stickerFile = $stickerFileData->body();
//            $stickerFileExtension = 'png';
//            $is_tgs && $stickerFileExtension = 'tgs';
//            $is_webm && $stickerFileExtension = 'webm';
//            $stickerFileName = Hash::sha256($stickerFile) . '.' . $stickerFileExtension;
//            $path = "stickers/$stickerFileName";
//            Storage::disk('public')->put($path, $stickerFile);
//            $stickerFileDownloaded = Storage::disk('public')->path($path);
//            $this->dispatch(new DeleteTempStickerFileJob($path));
//            return $stickerFileDownloaded;
//        } else {
//            return '';
//        }
//
//    }

    /**
     * @param string $stickerFileUrl
     * @return string
     * @throws Exception
     */
    private function downloadStickerFile(string $stickerFileUrl): string
    {
        $stickerFileData = Http::withHeaders(Config::CURL_HEADERS)
            ->connectTimeout(3)
            ->timeout(5)
            ->retry(1, 1000)
            ->get($stickerFileUrl);
        if ($stickerFileData->ok()) {
            $stickerFile = $stickerFileData->body();
            $stickerFileName = Hash::sha256($stickerFile) . '.png';
            $path = "stickers/$stickerFileName";
            Storage::disk('public')->put($path, $stickerFile);
            $stickerFileDownloaded = Storage::disk('public')->path($path);
            if (!Storage::disk('public')->path($path)) {
                throw new Exception('Sticker file not found', -1);
            }
            $this->dispatch(new DeleteTempStickerFileJob($path));
            return $stickerFileDownloaded;
        } else {
            return '';
        }

    }

//    /**
//     * @throws TelegramException
//     */
//    private function addStickerToSet(int $userId, string $stickerName, string $stickerEmoji, bool $is_png, bool $is_tgs, bool $is_webm, string $stickerFileDownloaded): array
//    {
//        $stickerInputFile = Request::encodeFile($stickerFileDownloaded);
//        $stickerRequestData = [
//            'user_id' => $userId,
//            'name' => $stickerName,
//            'emojis' => $stickerEmoji,
//        ];
//        $is_png && $stickerRequestData['png_sticker'] = $stickerInputFile;
//        $is_tgs && $stickerRequestData['tgs_sticker'] = $stickerInputFile;
//        $is_webm && $stickerRequestData['webm_sticker'] = $stickerInputFile;
//        $serverResponse = Request::addStickerToSet($stickerRequestData);
//        if ($serverResponse->isOk()) {
//            return [true, $serverResponse];
//        } else {
//            return [false, $serverResponse];
//        }
//    }

    /**
     * @param int $userId
     * @param string $stickerName
     * @param string $stickerEmoji
     * @param string $stickerFileDownloaded
     * @return array
     * @throws TelegramException
     */
    private function addStickerToSet(int $userId, string $stickerName, string $stickerEmoji, string $stickerFileDownloaded): array
    {
        $stickerInputFile = Request::encodeFile($stickerFileDownloaded);
        $stickerRequestData = [
            'user_id' => $userId,
            'name' => $stickerName,
            'emojis' => $stickerEmoji,
            'png_sticker' => $stickerInputFile,
        ];
        $serverResponse = Request::addStickerToSet($stickerRequestData);
        if ($serverResponse->isOk()) {
            return [true, $serverResponse];
        } else {
            return [false, $serverResponse];
        }
    }
}
