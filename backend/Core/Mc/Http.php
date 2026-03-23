<?php

namespace Core\Mc;

class Http
{
    private const ENCODERS = [
        "http_build_query",
        "json_encode"
    ];

    private string $url;
    private array $options = [];
    private string $encoder = "http_build_query";

    public function __construct(string $url, array $options = [])
    {
        $this->url = $url;
        $this->SetOptions($options);
    }

    private static function Request(array $options): string|false
    {

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        if (!$result = curl_exec($ch)) {
            trigger_error(curl_error($ch));
        }
        return $result;
    }

    public function SetOption($key, $value): void
    {
        $this->options[$key] = $value;
    }

    public function SetEncoder(callable $encoder): void {
        if(array_search($encoder, self::ENCODERS) !== false){
            $this->encoder = $encoder;
        }
    }

    public function SetWriteFunction(callable $write_function): void {
        $this->SetOption(CURLOPT_WRITEFUNCTION, $write_function);
    }

    public function SetOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            $this->SetOption($key, $value);
        }
    }

    public function Get(array $data = [], array $options = []): string|false
    {
        $q = strpos($this->url, '?') === false ? '?' : '';
        $this->SetOptions($options);
        $this->SetOptions([
            CURLOPT_HTTPGET => true,
            CURLOPT_URL => $this->url . $q . http_build_query($data)
        ]);
        return self::Request($this->options);
    }

    public function Post(array $data = [], array $options = []): string|false
    {
        $this->SetOptions($options);
        $this->SetOptions([
            CURLOPT_POST => true,
            CURLOPT_URL => $this->url,
            CURLOPT_POSTFIELDS => ($this->encoder)($data)
        ]);
        return self::Request($this->options);
    }

    public function Put(array $data = [], array $options = []): string|false
    {
        $this->SetOptions($options);
        $this->SetOptions([
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_URL => $this->url,
            CURLOPT_POSTFIELDS => ($this->encoder)($data)
        ]);
        return self::Request($this->options);
    }

    public function Delete(array $data = [], array $options = []): string|false
    {
        $q = strpos($this->url, '?') === false ? '?' : '';
        $this->SetOptions($options);
        $this->SetOptions([
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_URL => $this->url . $q . http_build_query($data),
            CURLOPT_POSTFIELDS => ''
        ]);
        return self::Request($this->options);
    }
}
