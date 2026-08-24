<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Commands\Concerns;

use ZipArchive;

/**
 * The bytes behind the demo library.
 *
 * Real files, never a name over the wrong content: the kind filter and the sort
 * by type both match on `mime_type`, which Media Library sniffs from what is
 * actually on disk. A ".mp4" holding noise sniffs as application/octet-stream,
 * so the filter would offer Video and find nothing — the demo would be showing
 * a bug the package does not have. Same reason the sizes are real: a fake size
 * over an empty file leaves the quota bar and the storage widget at zero, which
 * are two of the things a demo exists to show.
 *
 * Every recipe was checked against finfo. WebM is absent for that reason — an
 * EBML header alone does not sniff, and nothing short of a real stream does. So
 * is SVG, which the upload rules refuse anyway.
 */
trait GeneratesDemoFiles
{
    /**
     * Extensions per kind, dropping the ones this host cannot honestly write.
     *
     * The keys are FileKinds' own families, so a demo library exercises every
     * entry the filter menu offers — a kind with nothing behind it would read
     * as a broken filter.
     *
     * @return array<string, list<string>>
     */
    protected function demoExtensions(): array
    {
        $kinds = [
            'image' => extension_loaded('gd') ? ['png', 'jpg', 'gif'] : [],
            'pdf' => ['pdf'],
            'document' => ['txt', ...(extension_loaded('zip') ? ['odt'] : [])],
            'spreadsheet' => ['csv', ...(extension_loaded('zip') ? ['ods'] : [])],
            'presentation' => extension_loaded('zip') ? ['odp'] : [],
            'archive' => ['gz', ...(extension_loaded('zip') ? ['zip'] : [])],
            'audio' => ['mp3', 'wav'],
            'video' => ['mp4', 'mov'],
        ];

        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $kinds['image'][] = 'webp';
        }

        return array_filter($kinds, fn (array $extensions): bool => $extensions !== []);
    }

    /**
     * Writes one file and returns its path.
     *
     * $weight scales the content so a folder holds files of visibly different
     * sizes — sorting by size, the quota bar and the storage widget all need
     * something to tell apart.
     */
    protected function writeDemoFile(string $directory, string $extension, string $title, int $weight = 1): string
    {
        $path = $directory.'/'.bin2hex(random_bytes(8)).'.'.$extension;

        match ($extension) {
            'png', 'jpg', 'gif', 'webp' => $this->writeDemoImage($path, $extension, $title, $weight),
            'pdf' => $this->writeDemoPdf($path, $title, $weight),
            'txt' => file_put_contents($path, $this->demoProse($title, $weight)),
            'csv' => file_put_contents($path, $this->demoCsv($weight)),
            'odt' => $this->writeDemoOpenDocument($path, 'text', $title, $weight),
            'ods' => $this->writeDemoOpenDocument($path, 'spreadsheet', $title, $weight),
            'odp' => $this->writeDemoOpenDocument($path, 'presentation', $title, $weight),
            'zip' => $this->writeDemoZip($path, $title, $weight),
            'gz' => file_put_contents($path, (string) gzencode($this->demoProse($title, $weight * 4))),
            'mp3' => $this->writeDemoMp3($path, $weight),
            'wav' => $this->writeDemoWav($path, $weight),
            'mp4' => $this->writeDemoIsoMedia($path, 'isom', $weight),
            'mov' => $this->writeDemoIsoMedia($path, 'qt  ', $weight),
            default => file_put_contents($path, $this->demoProse($title, $weight)),
        };

        return $path;
    }

    /**
     * A gradient with the file's own name across it, so a wall of thumbnails is
     * a wall of distinguishable thumbnails.
     */
    protected function writeDemoImage(string $path, string $extension, string $title, int $weight): void
    {
        $width = 480 + ($weight * 160);
        $height = (int) round($width * (mt_rand(0, 1) === 1 ? 0.66 : 1.4));

        $image = imagecreatetruecolor($width, $height);
        $hue = mt_rand(0, 359);

        for ($y = 0; $y < $height; $y++) {
            [$r, $g, $b] = $this->demoRgb($hue, 0.55, 0.35 + (0.5 * ($y / max(1, $height - 1))));
            $line = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $y, $width, $y, $line);
        }

        $ink = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 24, 24, $title, $ink);
        imagestring($image, 3, 24, 46, $width.' x '.$height, $ink);

        match ($extension) {
            'jpg' => imagejpeg($image, $path, 82),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, 82),
            default => imagepng($image, $path),
        };

        imagedestroy($image);
    }

    /**
     * A structurally complete one-page PDF — offsets computed, xref written.
     *
     * A header and a %%EOF would sniff as application/pdf just as well, but the
     * demo's files get downloaded and previewed, and a viewer refusing to open
     * one reads as the explorer having served it wrong.
     */
    protected function writeDemoPdf(string $path, string $title, int $weight): void
    {
        $lines = ['BT /F1 22 Tf 62 760 Td ('.$this->escapePdfText($title).') Tj ET'];

        foreach ($this->demoSentences(4 + ($weight * 6)) as $index => $sentence) {
            $lines[] = 'BT /F1 11 Tf 62 '.(724 - ($index * 16)).' Td ('.$this->escapePdfText($sentence).') Tj ET';
        }

        $stream = implode("\n", $lines);

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>',
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
            '<</Length '.strlen($stream).'>>stream'."\n".$stream."\n".'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1).' 0 obj'.$body."endobj\n";
        }

        $startxref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT).' 00000 n '."\n";
        }

        $pdf .= 'trailer<</Size '.(count($objects) + 1).'/Root 1 0 R>>'."\n"
            .'startxref'."\n".$startxref."\n".'%%EOF'."\n";

        file_put_contents($path, $pdf);
    }

    /**
     * OpenDocument rather than OOXML, and not for taste: finfo recognises ODF
     * from its `mimetype` entry, which the format requires to be first and
     * stored uncompressed. A .docx built by hand sniffs as application/zip, and
     * would land the file under Archive in the kind filter.
     */
    protected function writeDemoOpenDocument(string $path, string $kind, string $title, int $weight): void
    {
        $mime = 'application/vnd.oasis.opendocument.'.$kind;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', $mime);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
            .'<manifest:file-entry manifest:full-path="/" manifest:media-type="'.$mime.'"/>'
            .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            .'</manifest:manifest>');
        $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.3">'
            .'<office:body><office:'.$kind.'><text:h>'.htmlspecialchars($title, ENT_XML1).'</text:h>'
            .implode('', array_map(
                fn (string $line): string => '<text:p>'.htmlspecialchars($line, ENT_XML1).'</text:p>',
                $this->demoSentences(6 + ($weight * 10)),
            ))
            .'</office:'.$kind.'></office:body></office:document-content>');
        $zip->close();
    }

    protected function writeDemoZip(string $path, string $title, int $weight): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('README.txt', $title."\n\n".$this->demoProse($title, 1));

        for ($index = 1; $index <= 2 + $weight; $index++) {
            $zip->addFromString('data/part-'.$index.'.txt', $this->demoProse($title.' '.$index, $weight));
        }

        $zip->close();
    }

    /**
     * An ID3v2 tag followed by MPEG-1 Layer III frame headers.
     */
    protected function writeDemoMp3(string $path, int $weight): void
    {
        $tag = 'ID3'.chr(3).chr(0).chr(0).pack('N', 0);
        $frame = "\xFF\xFB\x90\x00".str_repeat("\0", 100);

        file_put_contents($path, $tag.str_repeat($frame, 200 * $weight));
    }

    /**
     * A RIFF/WAVE header over 8kHz 16-bit silence.
     */
    protected function writeDemoWav(string $path, int $weight): void
    {
        $samples = str_repeat("\0\0", 8000 * $weight);

        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples);
    }

    /**
     * An ISO base media container: an `ftyp` box naming the brand, then a free
     * box holding the padding. `isom` sniffs as video/mp4 and `qt  ` as
     * video/quicktime, which is the whole difference between the two.
     */
    protected function writeDemoIsoMedia(string $path, string $brand, int $weight): void
    {
        $ftyp = pack('N', 20).'ftyp'.$brand.pack('N', 512);
        $padding = str_repeat("\0", 24000 * $weight);

        file_put_contents($path, $ftyp.pack('N', 8 + strlen($padding)).'free'.$padding);
    }

    protected function demoProse(string $title, int $weight): string
    {
        return $title."\n".str_repeat('=', strlen($title))."\n\n"
            .implode("\n", $this->demoSentences(8 + ($weight * 14)))."\n";
    }

    protected function demoCsv(int $weight): string
    {
        $rows = ['reference,description,quantity,unit_price,total'];
        $words = $this->demoWords();

        for ($index = 1; $index <= 12 + ($weight * 40); $index++) {
            $quantity = mt_rand(1, 40);
            $price = mt_rand(150, 48000) / 100;

            $rows[] = sprintf(
                'REF-%04d,%s,%d,%.2f,%.2f',
                $index,
                ucfirst($words[array_rand($words)]).' '.$words[array_rand($words)],
                $quantity,
                $price,
                $quantity * $price,
            );
        }

        return implode("\n", $rows)."\n";
    }

    /**
     * @return list<string>
     */
    protected function demoSentences(int $count): array
    {
        $words = $this->demoWords();
        $sentences = [];

        for ($index = 0; $index < max(1, $count); $index++) {
            $sentence = [];

            for ($word = 0; $word < mt_rand(6, 14); $word++) {
                $sentence[] = $words[array_rand($words)];
            }

            $sentences[] = ucfirst(implode(' ', $sentence)).'.';
        }

        return $sentences;
    }

    /**
     * @return list<string>
     */
    protected function demoWords(): array
    {
        return [
            'archive', 'quarter', 'invoice', 'contract', 'review', 'draft', 'summary',
            'budget', 'forecast', 'baseline', 'delivery', 'milestone', 'handover',
            'signature', 'appendix', 'clause', 'schedule', 'estimate', 'variance',
            'approval', 'revision', 'reference', 'inventory', 'renewal', 'scope',
        ];
    }

    protected function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * @return array{int, int, int}
     */
    protected function demoRgb(int $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
        $sector = $hue / 60;
        $second = $chroma * (1 - abs(fmod($sector, 2) - 1));
        $match = $lightness - ($chroma / 2);

        [$r, $g, $b] = match ((int) floor($sector) % 6) {
            0 => [$chroma, $second, 0.0],
            1 => [$second, $chroma, 0.0],
            2 => [0.0, $chroma, $second],
            3 => [0.0, $second, $chroma],
            4 => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };

        return [
            (int) round(($r + $match) * 255),
            (int) round(($g + $match) * 255),
            (int) round(($b + $match) * 255),
        ];
    }
}
