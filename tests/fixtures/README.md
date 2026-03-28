# Fixtures Directory

Place test files here:

- `sample.pdf` - Sample PDF for ExplodePdfWorker tests
- `test_page.png` - Sample image for OCR tests
- `test_page_with_images.png` - Image with visual elements for ImageDetectWorker tests

## Generating Test Fixtures

```bash
# Create a simple test image
php -r "
    \$img = imagecreatetruecolor(800, 1000);
    \$white = imagecolorallocate(\$img, 255, 255, 255);
    \$black = imagecolorallocate(\$img, 0, 0, 0);
    imagefill(\$img, 0, 0, \$white);
    imagestring(\$img, 5, 50, 50, 'Test OCR Content', \$black);
    imagestring(\$img, 5, 50, 100, 'Page 1 of 1', \$black);
    imagepng(\$img, 'test_page.png');
    imagedestroy(\$img);
"
```
