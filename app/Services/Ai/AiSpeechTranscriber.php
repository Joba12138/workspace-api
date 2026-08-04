<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiSpeechTranscriber
{
    public function transcribeUploaded(UploadedFile $file): string
    {
        if (! config('services.asr.enabled')) {
            throw new RuntimeException('语音转写未开启');
        }

        $apiKey = (string) config('services.asr.api_key');
        $base = rtrim((string) config('services.asr.base_url'), '/');
        $model = (string) config('services.asr.model', 'fun-asr-flash');
        $timeout = (int) config('services.asr.timeout', 60);

        if ($apiKey === '' || $base === '') {
            throw new RuntimeException('语音转写未配置：请设置 ASR_API_KEY 或 LLM_API_KEY');
        }

        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('音频文件为空');
        }
        if (strlen($bytes) > 10 * 1024 * 1024) {
            throw new RuntimeException('音频过长，请控制在 5 分钟 / 10MB 内');
        }

        $mime = $file->getMimeType() ?: 'audio/mpeg';
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp3');
        $mime = $this->normalizeMime($mime, $ext);
        $dataUri = 'data:'.$mime.';base64,'.base64_encode($bytes);

        $url = $base.'/services/aigc/multimodal-generation/generation';
        $body = [
            'model' => $model,
            'input' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_audio',
                        'input_audio' => [
                            'data' => $dataUri,
                        ],
                    ]],
                ]],
            ],
            'parameters' => [
                'language_hints' => ['zh'],
            ],
        ];

        $res = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->withHeaders(['X-DashScope-SSE' => 'disable'])
            ->post($url, $body);

        if (! $res->successful()) {
            throw new RuntimeException(
                '语音识别失败: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 240)
            );
        }

        $json = $res->json() ?: [];
        $text = (string) (
            data_get($json, 'output.text')
            ?: data_get($json, 'output.output.sentence.text')
            ?: data_get($json, 'output.choices.0.message.content')
            ?: ''
        );
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('未识别到语音内容，请再说一遍');
        }

        return $text;
    }

    private function normalizeMime(string $mime, string $ext): string
    {
        if (str_starts_with($mime, 'audio/')) {
            return $mime;
        }

        return match ($ext) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/mp4',
            'amr' => 'audio/amr',
            'ogg' => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
