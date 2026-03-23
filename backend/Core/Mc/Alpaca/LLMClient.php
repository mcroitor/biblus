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