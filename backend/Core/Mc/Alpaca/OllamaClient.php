<?php

namespace Core\Mc\Alpaca;

use \Core\Mc\Alpaca\LLMClient;
use \Core\Mc\Http;

/**
 * Ollama LLM Client Implementation
 * 
 * This class provides a concrete implementation of the LLMClient interface
 * for interacting with Ollama language models. It supports model management,
 * text generation, and various configuration options.
 * 
 * Ollama is a tool for running large language models locally, and this client
 * provides a PHP interface to communicate with the Ollama API server.
 * 
 * @package Core\Mc\Alpaca
 * @author Mihail Croitor <mcroitor@gmail.com>
 * @version 1.0.0
 * @since 1.0.0
 * 
 * @example
 * ```php
 * $client = new OllamaClient('http://localhost:11434', 'llama3.2:latest');
 * $response = $client->generate('What is artificial intelligence?');
 * echo $response;
 * ```
 */
class OllamaClient implements LLMClient
{

    /**
     * API key for authentication (currently not used by Ollama)
     * 
     * @var string
     */
    private string $apiKey;

    /**
     * Base URL for the Ollama API server
     * 
     * @var string
     */
    private string $apiUrl;

    /**
     * Name of the language model to use for generation
     * 
     * @var string
     */
    private string $modelName = "llama3.2:latest";

    /**
     * Custom options for requests (e.g., streaming, system role, model tuning)
     * @var array
     */
    private array $options = [];
    private static int $requestTimeout = 60; // default timeout for API requests in seconds

    /**
     * Initialize the Ollama client
     * 
     * @param string $apiUrl The base URL for the Ollama API (e.g., 'http://localhost:11434')
     * @param string $modelName The name of the model to use (default: 'llama3.2:latest')
     * @param string $apiKey Optional API key (not currently used by Ollama, default: empty)
     * 
     * @throws \InvalidArgumentException When apiUrl is invalid
     */
    public function __construct(string $apiUrl, string $modelName = "llama3.2:latest", string $apiKey = "")
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->modelName = $modelName;
        $this->options = [
            "stream" => false,
            "apiKey" => $apiKey,
        ];
    }

    /**
     * Get the API key
     * 
     * @return string The configured API key
     */
    public function GetApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Get the API URL
     * 
     * @return string The configured API URL
     */
    public function GetApiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Get the current model name
     * 
     * @return string The name of the currently selected model
     */
    public function GetModelName(): string
    {
        return $this->modelName;
    }

    /**
     * Set the model name to use for generation
     * 
     * @param string $modelName The name of the model to use
     * 
     * @return void
     * 
     * @throws \InvalidArgumentException When modelName is empty
     */
    public function SetModelName(string $modelName): void
    {
        $this->modelName = $modelName;
    }

    /**
     * Create and configure an HTTP client for API requests
     * 
     * This is a factory method that creates a properly configured HTTP client
     * with JSON encoding and a custom write function for handling responses.
     * 
     * @param string $uri The URI for the HTTP request
     * @param string &$buffer Reference to buffer for storing response data
     * 
     * @return \Core\Mc\Http Configured HTTP client instance
     */
    private static function GetHttpClient(string $uri, string &$buffer, array $options = []): Http
    {
        $http = new Http($uri);
        $http->SetOption(CURLOPT_TIMEOUT, $options['timeout'] ?? self::$requestTimeout);
        $http->SetEncoder("json_encode");
        $http->SetWriteFunction(function ($curl, $data) use (&$buffer, $options): int {
            if ($options['stream'] ?? false) {
                $object = json_decode($data);
                if ($object->response) {
                    $buffer .= $object->response;
                    echo $object->response;
                    flush();
                }
            } else {
                $buffer .= $data;
            }
            return \strlen($data);
        });
        self::TryAuthenticate($http, $options['apiKey'] ?? "");
        return $http;
    }

    private static function TryAuthenticate(Http $http, string $apiKey = ""): void {
        // if isset OLLAMA_API_KEY then set header
        $apiKey = getenv('OLLAMA_API_KEY') ?: $apiKey;
        if ($apiKey) {
            $http->SetOption(CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
        }
    }

    /**
     * Get list of available models from Ollama server
     * 
     * Queries the Ollama API to retrieve a list of all models
     * that are currently available on the server.
     * 
     * @return array Array of model names
     * 
     * @throws \RuntimeException When the API request fails
     */
    public function GetModelsList(): array
    {
        $models = [];

        $url = "{$this->apiUrl}/api/tags";
        $buffer = "";
        $http = self::GetHttpClient($url, $buffer, $this->options);

        $response = $http->get();
        $data = json_decode($buffer, true);

        foreach ($data['models'] as $model) {
            $models[] = $model['name'];
        }
        return $models;
    }

    /**
     * Get detailed information about a specific model
     * 
     * Retrieves comprehensive information about a model including
     * its parameters, template, and other metadata.
     * 
     * @param string $modelName The name of the model to query
     * 
     * @return array Associative array containing model information
     * 
     * @throws \RuntimeException When the API request fails
     * @throws \InvalidArgumentException When modelName is empty
     */
    public function GetModelInfo(string $modelName): array
    {
        $url = "{$this->apiUrl}/api/show";
        $buffer = "";
        $http = self::GetHttpClient($url, $buffer, $this->options);
        $response = $http->post(
            ["model" => $modelName],
            [CURLOPT_HTTPHEADER => ['Content-Type:application/json']]
        );
        if ($response) {
            $data = json_decode($buffer, true);
            return $data;
        }
        return [];
    }

    /**
     * Send a generic prompt request to a specific Ollama endpoint
     * 
     * This is a low-level method for sending custom requests to
     * different Ollama API endpoints with arbitrary data.
     * 
     * @param string $endpoint The API endpoint to call (without base URL)
     * @param array $data The data to send in the request body
     * 
     * @return string Raw response from the API
     * 
     * @throws \RuntimeException When the API request fails
     */
    public function Prompt(string $endpoint, array $data)
    {
        $url = "{$this->apiUrl}/{$endpoint}";
        $buffer = "";
        $http = self::GetHttpClient($url, $buffer, $this->options);
        $response = $http->post(
            $data
        );

        return $buffer;
    }

    /**
     * Generate text response from the language model
     * 
     * Sends a prompt to the configured model and returns the generated response.
     * This is the main method for text generation.
     * 
     * @param string $prompt The input prompt to send to the model
     * @param array $options Optional parameters to customize generation
     *                      (e.g., system prompt, data format, temperature, etc.)
     * 
     * @return string Raw JSON response from the Ollama API
     * 
     * @throws \RuntimeException When the API request fails
     * @throws \InvalidArgumentException When prompt is empty
     * 
     * @example
     * ```php
     * $response = $client->generate('Explain quantum computing', [
     *     'system' => "You are a helpful assistant.",
     *     'options' => ["temperature" => 0.7]
     * ]);
     * ```
     */
    public function Generate(string $prompt, array $options = []): string
    {
        $data = [
            "model" => $this->modelName,
            "prompt" => $prompt,
        ];
        foreach ($options as $key => $value) {
            $data[$key] = $value;
        }
        return $this->Prompt("api/generate", $data);
    }

        /**
     * Used for manipulate with request, for example enable / disable streaming,
     * set up format etc. Check https://docs.ollama.com/api/generate
     * @param string $option
     * @param string $value
     * @return void
     */
    public function SetRequestOption(string $option, string $value): void {
        $this->options[$option] = $value;
    }

    /**
     * Used for setting model-tuning options:
     * 
     *  - temperature
     *  - seed
     *  - top_k
     *  - top_p
     *  - min_p
     *  - stop
     *  - num_ctx
     *  - num_predict
     * 
     * @param array $options
     * @return void
     */
    public function SetModelOptions(array $options): void {
        $this->options["options"] = $options;
    }
    public static function SetRequestTimeout(int $timeout): void {
        self::$requestTimeout = $timeout;
    }
}
