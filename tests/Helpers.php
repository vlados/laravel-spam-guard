<?php

function responseBody(mixed $probability = 0.12): array
{
    return ['model' => 'jev-1.13.0', 'answers' => ['spam' => ['type' => 'noul', 'noul' => $probability]], 'usage' => ['input_tokens' => 307, 'output_tokens' => 20]];
}
