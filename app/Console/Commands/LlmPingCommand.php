<?php

namespace App\Console\Commands;

use App\Contracts\LlmClient;
use Illuminate\Console\Command;
use Throwable;

class LlmPingCommand extends Command
{
    protected $signature = 'llm:ping {--prompt=用一句话介绍你自己}';

    protected $description = '探测当前 LLM 配置是否可用';

    public function handle(LlmClient $llm): int
    {
        $this->info('driver='.config('services.llm.driver'));
        $this->info('base_url='.config('services.llm.base_url'));
        $this->info('model='.config('services.llm.model'));

        try {
            $result = $llm->chat([
                ['role' => 'user', 'content' => (string) $this->option('prompt')],
            ], ['temperature' => 0.2]);
            $this->info('model_used='.($result->model ?: '-'));
            $this->line($result->content);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
