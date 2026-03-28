# PDF to Markdown Converter

A Web tool for PDF book conversion to Markdown documents. AI-agents based, Ollama API usage.

## Requirements

- PHP 8.4+
- [Ollama](https://ollama.com/) with models

## Features

- Upload PDF file
- Explode PDF to page images
- OCR page images
- Extract pictures from page images
- Validate text and pictures by page image
- Format extracted text to Markdown by image

## Usage

## App architecture

```yaml
services:
    frontend:
        image: nginx:alpine
        ports:
          - "8080:80"
        # ...
    backend:
        image: php:8.4-fpm
        # ...
    ollama:
        image: ollama
        # ...
```

### Frontend

Styling is based on Skeleton CSS. Re-active part is based on JS standard built-in objects.

### Backend

## Output structure

```text
output_dir/
├── README.md          # Book description with content references
├── chapter_1.md       # Chapter 1
├── chapter_2.md       # Chapter 2
├── ...
├── chapter_N.md       # Chapter N
├── introduction.md    # Introduction (if exists)
├── conclusion.md      # Conclusion (if exists)
├── references.md      # References (if exists)
├── appendix_1.md      # Appendix 1 (if exists)
├── ...
├── appendix_M.md      # Appendix M (if exists)
└── images/            #  book images
```

## Recommended models

For OCR can be used `deepseek-ocr` or `qwen3-vl`. For common usage `qwen3.5` is a good solution.

## Testing

```bash
# Install dependencies
composer install

# Run tests with mocks (default)
./vendor/bin/phpunit tests

# Run tests with real Ollama server
OLLAMA_MODE=real ./vendor/bin/phpunit tests
```

## License

GNU GPL 3.0
