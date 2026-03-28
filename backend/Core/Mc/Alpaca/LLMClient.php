<?php

namespace Core\Mc\Alpaca;

/**
 * Interface for Large Language Model clients
 * 
 * This interface defines the contract for all LLM client implementations,
 * providing a standardized way to interact with different LLM providers
 * such as Ollama, OpenAI, Anthropic, etc.
 * 
 * @package Core\Mc\Alpaca
 * @author Mihail Croitor <mcroitor@gmail.com>
 * @version 1.0.0
 * @since 1.0.0
 */
interface LLMClient {
    
    /**
     * Initialize the LLM client with API configuration
     * 
     * @param string $apiUrl The base URL for the LLM API endpoint
     * @param string $modelName The name of the model to use (default: "llama3.2:latest")
     * @param string $apiKey Optional API key for authentication (default: empty)
     * 
     * @throws \InvalidArgumentException When apiUrl is invalid
     */
    public function __construct(string $apiUrl, string $modelName = "llama3.2:latest", string $apiKey = "");
    
    /**
     * Get the API key used for authentication
     * 
     * @return string The API key, or empty string if not set
     */
    public function GetApiKey(): string;
    
    /**
     * Get the base API URL
     * 
     * @return string The configured API URL
     */
    public function GetApiUrl(): string;
    
    /**
     * Get the model name
     * 
     * @return string The model name
     */
    public function GetModelName(): string;
    
    /**
     * Set the model name
     * 
     * @param string $modelName The model name to use
     */
    public function SetModelName(string $modelName): void;
    
    /**
     * Set a request option
     * 
     * @param string $option The option name
     * @param string $value The option value
     */
    public function SetRequestOption(string $option, string $value): void;
    
    /**
     * Set model options
     * 
     * @param array $options Model tuning options (temperature, num_ctx, etc.)
     */
    public function SetModelOptions(array $options): void;
    
    /**
     * Get list of available models
     * 
     * @return array Array of model names
     */
    public function GetModelsList(): array;
    
    /**
     * Get detailed model information
     * 
     * @param string $modelName The model name to query
     * @return array Model information
     */
    public function GetModelInfo(string $modelName): array;
    
    /**
     * Send a generic prompt request to a specific endpoint
     * 
     * @param string $endpoint The API endpoint
     * @param array $data The request data
     * @return string Raw response
     */
    public function Prompt(string $endpoint, array $data);
    
    /**
     * Generate text response from the language model
     * 
     * This is the core method for interacting with the LLM.
     * It sends a prompt to the model and returns the generated response.
     * 
     * @param string $prompt The input prompt to send to the model
     * @param array $options Optional generation parameters (system prompt,
     *                      data format, temperature, etc.)
     * 
     * @return string The generated response from the model
     * 
     * @throws \RuntimeException When the API request fails
     * @throws \InvalidArgumentException When prompt is empty
     */
    public function Generate(string $prompt, array $options = []): string;
}