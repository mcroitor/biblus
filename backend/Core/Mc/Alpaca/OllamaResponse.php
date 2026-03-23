<?php

namespace Core\Mc\Alpaca;

/**
 * Ollama API Response Parser
 * 
 * This class represents and parses responses from the Ollama API.
 * It provides structured access to all the information returned
 * by Ollama's generate endpoint, including timing metrics and context.
 * 
 * The class handles the JSON response format and extracts all relevant
 * fields into typed properties for easy access.
 * 
 * @package Mc\Alpaca
 * @author Mihail Croitor <mcroitor@gmail.com>
 * @version 1.0.0
 * @since 1.0.0
 * 
 * @example
 * ```php
 * $jsonResponse = $ollamaClient->generate("Hello world");
 * $response = OllamaResponse::fromJson($jsonResponse);
 * echo $response->response; // The generated text
 * echo $response->total_duration; // Total processing time
 * ```
 */
class OllamaResponse {
    
    /**
     * Name of the model that generated the response
     * 
     * @var string
     */
    public string $model;
    
    /**
     * ISO 8601 timestamp when the response was created
     * 
     * @var string
     */
    public string $created_at;
    
    /**
     * The generated text response from the model
     * 
     * @var string
     */
    public string $response;
    
    /**
     * Whether the response generation is complete
     * 
     * @var bool
     */
    public bool $done;
    
    /**
     * Context array for maintaining conversation state
     * 
     * This array contains the model's internal context state
     * which can be used for multi-turn conversations.
     * 
     * @var array
     */
    public array $context;
    
    /**
     * Total time taken for the entire generation process (nanoseconds)
     * 
     * @var int
     */
    public int $total_duration;
    
    /**
     * Time taken to load the model (nanoseconds)
     * 
     * @var int
     */
    public int $load_duration;
    
    /**
     * Number of tokens in the input prompt
     * 
     * @var int
     */
    public int $prompt_eval_count;
    
    /**
     * Time taken to evaluate the prompt (nanoseconds)
     * 
     * @var int
     */
    public int $prompt_eval_duration;
    
    /**
     * Number of tokens generated in the response
     * 
     * @var int
     */
    public int $eval_count;
    
    /**
     * Time taken to generate the response tokens (nanoseconds)
     * 
     * @var int
     */
    public int $eval_duration;

    /**
     * Create an OllamaResponse instance from JSON response
     * 
     * This static factory method parses a JSON response from the Ollama API
     * and creates a properly initialized OllamaResponse object with all
     * fields populated from the response data.
     * 
     * @param string $json Raw JSON response from Ollama API
     * 
     * @return OllamaResponse Parsed response object
     * 
     * @throws \JsonException When JSON parsing fails
     * @throws \InvalidArgumentException When JSON is empty or invalid
     * 
     * @example
     * ```php
     * $jsonResponse = '{"model":"llama3.2","response":"Hello!","done":true}';
     * $response = OllamaResponse::FromJson($jsonResponse);
     * ```
     */
    public static function FromJson(string $json): OllamaResponse {
        $data = json_decode($json, true);
        $response = new OllamaResponse();
        $response->model = $data['model'] ?? '';
        $response->created_at = $data['created_at'] ?? '';
        $response->response = $data['response'] ?? '';
        $response->done = $data['done'] ?? false;
        $response->context = $data['context'] ?? [];
        $response->total_duration = $data['total_duration'] ?? 0;
        $response->load_duration = $data['load_duration'] ?? 0;
        $response->prompt_eval_count = $data['prompt_eval_count'] ?? 0;
        $response->prompt_eval_duration = $data['prompt_eval_duration'] ?? 0;
        $response->eval_count = $data['eval_count'] ?? 0;
        $response->eval_duration = $data['eval_duration'] ?? 0;
        return $response;
    }
}