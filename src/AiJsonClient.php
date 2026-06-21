<?php

interface AiJsonClient
{
    public function generateJson(string $systemPrompt, array $inputData): array;
}
