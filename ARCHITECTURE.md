# Data Pipeline Architecture

## Pipeline Stages

1. **Pdf2Pages** - extract pages from PDF and stores them as images (for example p0001.tiff, p0002.tiff, p0003.tiff etc)
2. **OcrAgent** - extract text from each page and stores them as a text (for example p0001.txt, p0002.txt, p0003.txt etc)
3. **ImagesAgent** - detect and extract book images from each page (for example p0001_img01.tiff, p0001_img01.tiff, p003_img01.tiff etc)
4. **VerifierAgent** - validates OCR and images for each page, and if needs, rerun OcrAgent and ImageAgent for specific page
5. **MarkdownAgent** - combines result (formats text as Markdown) in one Markdown document or in a set of Markdown documents.  

## Module Responsibilities

### core/

### agents/

## Entity Contracts

### Document

Contains set of chapters.

### Chapter

Text and images. 

### Page

### Image

### AgentResult

## Agent Interfaces

### Base Agent Interface

### OCR Agent

Extracts text from page.

### ImageAgent

Extracts images from page.

### Verifier Agent

Gets OCR text and images from page and validates result.

### Markdown Agent

Implode pages, format text as Markdown.