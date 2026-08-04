<?php

namespace App\Contracts;

use App\Services\Llm\LlmResult;

interface LlmClient
{
    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @param  array{temperature?:float,response_format?:array,model?:string,max_tokens?:int}  $options
     */
    public function chat(array $messages, array $options = []): LlmResult;
}
