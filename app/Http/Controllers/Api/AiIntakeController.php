<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiIntakeParser;
use App\Services\Ai\AiSpeechTranscriber;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AiIntakeController extends Controller
{
    public function parse(Request $request, AiIntakeParser $parser)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $workspaceId = (string) $request->attributes->get('workspace_id');

        try {
            $result = $parser->parse($workspaceId, $data['text']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => '没听懂，请再说具体一点'], 502);
        }

        return response()->json([
            'data' => [
                'draft' => $result['draft'],
                'skill' => $result['skill'],
                'missing_fields' => $result['missing_fields'],
                'members' => $result['members'],
                'model' => $result['model'],
            ],
        ]);
    }

    public function transcribe(Request $request, AiSpeechTranscriber $asr)
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:10240'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('audio');

        try {
            $text = $asr->transcribeUploaded($file);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => '语音识别失败，请改用文字输入'], 502);
        }

        return response()->json([
            'data' => [
                'text' => $text,
            ],
        ]);
    }
}
